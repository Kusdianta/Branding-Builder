<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesAuditEvidence;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\InstagramProfileAuditService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB69 — Phase 11 analysis-layer job. Pairs with FetchInstagramAuditJob.
 *
 * FetchInstagramAuditJob (Phase 1) performs the worker scrape and writes a
 * sanitized snapshot to BrandAudit.instagram_audit, flipping
 * instagram_audit_status to 'scraped'. This job consumes that snapshot,
 * runs the Claude analysis pass via InstagramProfileAuditService::analyze(),
 * and mirrors the resulting analysis JSON to audit_evidence.instagram_analysis.
 *
 * Pipeline alignment:
 *   - BB69 (this commit) wires this job to run synchronously at the end of
 *     FetchInstagramAuditJob so the current 3-phase gather/validate/score
 *     pipeline (BB52-BB55) keeps working unchanged.
 *   - BB71 will move this job into a Phase 2 parallel batch alongside
 *     AnalyzeVisualConsistencyJob + ExtractServiceSignalsJob.
 *
 * Service contract is never-throw; this job is a defensive wrapper that
 * records step status and tolerates a missing snapshot (treated as no-op).
 */
class AnalyzeInstagramJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesAuditEvidence;

    /** Claude analysis budget plus retry headroom. */
    public int $timeout = 180;

    /**
     * BB132 — analyze() is a no-op when scrape didn't set status='scraped'
     * and never-throws on Claude failure (it persists a 'claude_analysis_failed'
     * status). Queue retries on a 180s timeout are dead weight — they
     * just delay the failure surface to the user. Operators can hit
     * BB59's retryStep to force a fresh attempt.
     */
    public int $tries = 1;

    public function __construct(public readonly string $auditId) {}

    public function handle(InstagramProfileAuditService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('analyze_instagram');
        $step?->markRunning();

        $audit = BrandAudit::findOrFail($this->auditId);

        // analyze() is a no-op when status != 'scraped' — skip the
        // mirror and short-circuit the step row to a 'skipped' done.
        if ((string) $audit->instagram_audit_status !== 'scraped') {
            $step?->markDone([
                'skipped' => true,
                'reason'  => 'instagram_audit_status not scraped: ' . (string) $audit->instagram_audit_status,
            ]);
            return;
        }

        try {
            $service->analyze($audit);
            $audit->refresh();
            $finalStatus = (string) $audit->instagram_audit_status;

            $this->mirrorAnalysisToEvidence($audit);

            if ($finalStatus === 'done') {
                $step?->markDone(['status' => 'done']);
            } else {
                // analyze() persisted a failure (claude_analysis_failed,
                // snapshot_corrupt, etc.) — surface but don't block pipeline.
                $step?->markDone(['status' => $finalStatus]);
            }
        } catch (Throwable $e) {
            Log::error('AnalyzeInstagramJob: service threw despite never-throw contract', [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);
            $step?->markFailed($e->getMessage());
        }
    }

    /**
     * BB146 — a 180s timeout (tries=1), a worker restart, or a re-reservation
     * (MaxAttemptsExceededException) can kill this job after a successful
     * scrape, leaving instagram_audit_status stranded at 'scraped'. Coerce
     * that to a terminal failure so the reveal gate can open. Guarded so a
     * real failure status analyze() already persisted is never clobbered.
     *
     * BB147 — use the honest 'analysis_incomplete' code, NOT
     * 'claude_analysis_failed'. A queue-level death means the analysis never
     * RAN to completion — it is not a Claude error or quota exhaustion.
     * Conflating the two produced a banner ("Claude error / kuota habis")
     * that lied about an analysis that was merely interrupted. Genuine Claude
     * exceptions are still recorded as 'claude_analysis_failed' by
     * InstagramProfileAuditService::analyze()'s catch block.
     */
    public function failed(Throwable $e): void
    {
        $audit = BrandAudit::find($this->auditId);
        if ($audit === null || $audit->instagramAuditIsTerminal()) {
            return;
        }

        $audit->update([
            'instagram_audit_status' => 'audit_failed',
            'instagram_audit'        => ['error' => 'analysis_incomplete: ' . $e->getMessage()],
        ]);
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    /**
     * Mirror the analysis JSON to audit_evidence.instagram_analysis so
     * downstream scorers can consume it without re-reading the
     * instagram_audit column directly. Strips _meta's visual paths to
     * avoid duplicating the FetchInstagramAuditJob.mirrorToEvidence raw
     * slice (which owns those paths).
     */
    private function mirrorAnalysisToEvidence(BrandAudit $audit): void
    {
        // instagram_audit is single-writer; re-read the freshly-analysed
        // snapshot then write only the instagram_analysis slice via a single
        // atomic json_set UPDATE (BB139 WritesAuditEvidence) so the
        // concurrent Phase-2 service_signals write is preserved.
        $payload = BrandAudit::findOrFail($audit->id)->instagram_audit;

        if (! is_array($payload)) {
            $this->writeEvidenceKey('instagram_analysis', null);
            return;
        }

        $analysisSlice = $payload;
        $meta = (array) ($analysisSlice['_meta'] ?? []);
        unset(
            $meta['profile_pic_path'],
            $meta['screenshot_path'],
            $meta['post_thumbnail_paths'],
        );
        $analysisSlice['_meta'] = $meta;

        $this->writeEvidenceKey('instagram_analysis', $analysisSlice);
    }
}
