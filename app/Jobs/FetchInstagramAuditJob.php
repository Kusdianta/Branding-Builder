<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\InstagramProfileAuditService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;

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
                // Synchronous hand-off until BB71 introduces the Phase 2
                // parallel batch. Keeps the current 3-phase gather/validate/
                // score chain intact: GatherEvidenceJob's batch ->then()
                // still fires only after BOTH scrape and analyze finish.
                AnalyzeInstagramJob::dispatchSync($this->auditId);
            } elseif ($status === 'no_instagram_url_provided') {
                $step?->markDone(['skipped' => true, 'reason' => $status]);
            } else {
                // Terminal failure modes (credentials_stale, rate_limited,
                // profile_not_found, audit_failed, no_credentials_available).
                // Record but don't fail the step — pipeline continues with
                // missing IG slice and downstream scorers degrade gracefully.
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
        $auditId = $audit->id;

        DB::transaction(function () use ($auditId): void {
            $fresh = BrandAudit::findOrFail($auditId);
            $evidence = (array) ($fresh->audit_evidence ?? []);

            $payload = $fresh->instagram_audit;
            if (! is_array($payload)) {
                $evidence['instagram_audit'] = null;
                $fresh->update(['audit_evidence' => $evidence]);
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

            $evidence['instagram_audit'] = $rawSlice;

            $fresh->update(['audit_evidence' => $evidence]);
        });
    }
}
