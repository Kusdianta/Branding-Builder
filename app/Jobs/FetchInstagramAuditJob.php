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
 * BB52 gather sub-job 3 of 3, BB69-refactored.
 *
 * Phase 1 (gather): worker scrape only. Delegates to
 * InstagramProfileAuditService::scrape() which performs the credential
 * fetch + worker call + visual-asset persistence and writes a sanitized
 * snapshot to the instagram_audit column with status='scraped'.
 *
 * After scrape, dispatches AnalyzeInstagramJob synchronously so the
 * Claude analysis pass completes before this batch finalizes. BB71 will
 * move that dispatch out into a Phase 2 parallel batch.
 *
 * Evidence mirroring is split across the two jobs:
 *   - This job:           audit_evidence.instagram_audit (raw slice with
 *                          visual asset paths from _meta)
 *   - AnalyzeInstagramJob: audit_evidence.instagram_analysis (Claude
 *                          scorecard + analysis JSON)
 *
 * Marks the 'gather_instagram' audit_step. Service contract is
 * never-throw; this job is a defensive wrapper.
 */
class FetchInstagramAuditJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesAuditEvidence;

    public int $timeout = 240;

    /**
     * BB132 — InstagramProfileAuditService is never-throw: every failure
     * path persists a terminal status onto the audit row (worker_unavailable,
     * credentials_stale, profile_not_found, etc.). The only way this job
     * "fails" in the queue's eyes is if the wall clock blows past 240s
     * (two credential attempts × 120s worker HTTP timeout). When that
     * happens, retrying buys nothing — the next attempt hits the same
     * hung worker and the user just waits another four minutes. Cap at
     * one attempt; the dashboard surfaces a "worker tidak merespons"
     * banner and the BB59 retryStep button gives operators an explicit
     * retry path.
     */
    public int $tries = 1;

    public function __construct(public readonly string $auditId) {}

    public function handle(InstagramProfileAuditService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('gather_instagram');
        $step?->markRunning();

        try {
            $audit = BrandAudit::findOrFail($this->auditId);
            $service->scrape($audit);

            $audit->refresh();
            $status  = (string) $audit->instagram_audit_status;

            $this->mirrorScrapeToEvidence($audit);

            $detail = ['status' => $status];

            if ($status === 'scraped') {
                $step?->markDone($detail);
                // BB71: AnalyzeInstagramJob now runs in the Phase 2
                // parallel batch (AnalysisOrchestratorJob). No inline
                // dispatch from this gather-phase job; the batch boundary
                // owns the hand-off.
            } elseif ($status === 'no_instagram_url_provided') {
                $step?->markDone(['skipped' => true, 'reason' => $status]);
            } else {
                // BB109 — surface the actual error from
                // BrandAudit.instagram_audit into audit_steps.detail
                // so the operator sees `{"status":"audit_failed",
                // "error":"internal_error: NotImplementedError: ..."}`
                // instead of the pre-BB109 useless
                // `{"status":"audit_failed"}`. Same root cause is now
                // visible without grep-ing logs.
                $instagramPayload = (array) ($audit->instagram_audit ?? []);
                if (isset($instagramPayload['error']) && is_string($instagramPayload['error'])) {
                    $detail['error'] = $instagramPayload['error'];
                }
                $step?->markDone($detail);
            }
        } catch (Throwable $e) {
            Log::error('FetchInstagramAuditJob: service threw despite contract', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
            $this->mirrorScrapeToEvidence(BrandAudit::find($this->auditId));
            $step?->markFailed($e->getMessage());
        }
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    /**
     * Mirror the raw scrape slice to audit_evidence.instagram_audit so
     * KonsistensiScorer's vision path can find profile_pic_path,
     * screenshot_path, and post_thumbnail_paths without re-reading the
     * instagram_audit column directly.
     *
     * Shape mirrors what BB55 KonsistensiScorer::collectVisualAssets()
     * already expects:
     *   evidence.instagram_audit = {profile_pic_path, screenshot_path,
     *                               post_thumbnail_paths, captured_at, username}
     *
     * Tolerant of a null audit (defensive failure path).
     */
    private function mirrorScrapeToEvidence(?BrandAudit $audit): void
    {
        if ($audit === null) {
            return;
        }

        // The instagram_audit column is single-writer
        // (InstagramProfileAuditService), so re-reading the freshly-persisted
        // snapshot here is race-free. The evidence write itself is a single
        // atomic json_set UPDATE (BB139 WritesAuditEvidence), so the
        // concurrent Phase-2 instagram_analysis write is never clobbered.
        $payload = BrandAudit::findOrFail($audit->id)->instagram_audit;

        if (! is_array($payload)) {
            $this->writeEvidenceKey('instagram_audit', null);
            return;
        }

        $meta = (array) ($payload['_meta'] ?? []);

        $rawSlice = [
            'profile_pic_path'      => $meta['profile_pic_path']      ?? null,
            'screenshot_path'       => $meta['screenshot_path']       ?? null,
            'post_thumbnail_paths'  => (array) ($meta['post_thumbnail_paths'] ?? []),
            'captured_at'           => $meta['captured_at']           ?? null,
            'username'              => $meta['username']              ?? null,
        ];

        $this->writeEvidenceKey('instagram_audit', $rawSlice);
    }
}
