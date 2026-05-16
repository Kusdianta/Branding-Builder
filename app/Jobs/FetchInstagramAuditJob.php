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
 * BB52 — Phase 10 gather sub-job 3 of 3.
 *
 * Delegates to InstagramProfileAuditService::audit() (which scrapes
 * via worker + runs Claude analysis + persists to the legacy
 * instagram_audit column), then splits the persisted payload into the
 * two audit_evidence slices BB54+ scorers expect:
 *
 *   audit_evidence.instagram_audit    — RAW extraction-time data:
 *     visual asset paths (profile_pic_path, screenshot_path,
 *     post_thumbnail_paths), captured_at marker. The visual paths are
 *     what BB57's vision-Konsistensi scorer feeds into the multimodal
 *     Claude call.
 *
 *   audit_evidence.instagram_analysis — Claude scorecard + executive
 *     summary + content/engagement/growth analysis: the Phase 7-B
 *     output that the IG pillar already consumes.
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
            $service->audit($audit);

            $audit->refresh();
            $status  = (string) $audit->instagram_audit_status;
            $payload = $audit->instagram_audit;

            $this->mirrorToEvidence(is_array($payload) ? $payload : null);

            $detail = ['status' => $status];

            if ($status === 'done') {
                $step?->markDone($detail);
            } elseif ($status === 'no_instagram_url_provided') {
                $step?->markDone(['skipped' => true, 'reason' => $status]);
            } else {
                // Other terminal statuses (credentials_stale, rate_limited,
                // profile_not_found, audit_failed, no_credentials_available)
                // — record but don't fail the step; pipeline continues with
                // missing IG slice and downstream scorers degrade gracefully.
                $step?->markDone($detail);
            }
        } catch (Throwable $e) {
            // Defence in depth — service contract is never-throw.
            Log::error('FetchInstagramAuditJob: service threw despite contract', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
            $this->mirrorToEvidence(null);
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
     * Split the legacy instagram_audit payload into raw vs analysis
     * slices. The legacy column commingles Claude scorecard fields
     * (executive_summary, profile_branding, scorecard, ...) with a
     * _meta block holding visual asset paths
     * (profile_pic_path, screenshot_path, post_thumbnail_paths) that
     * persistInstagramAssets() wrote to disk during the audit pass.
     *
     * Raw slice keeps the visual paths + captured_at marker. Analysis
     * slice keeps everything else.
     */
    private function mirrorToEvidence(?array $payload): void
    {
        DB::transaction(function () use ($payload): void {
            $audit = BrandAudit::findOrFail($this->auditId);
            $evidence = (array) ($audit->audit_evidence ?? []);

            if ($payload === null) {
                $evidence['instagram_audit']    = null;
                $evidence['instagram_analysis'] = null;
                $audit->update(['audit_evidence' => $evidence]);
                return;
            }

            $meta = (array) ($payload['_meta'] ?? []);

            $rawSlice = [
                'profile_pic_path'      => $meta['profile_pic_path']      ?? null,
                'screenshot_path'       => $meta['screenshot_path']       ?? null,
                'post_thumbnail_paths'  => (array) ($meta['post_thumbnail_paths'] ?? []),
                'captured_at'           => $meta['captured_at']           ?? ($payload['analyzed_at'] ?? null),
                'username'              => $meta['username']              ?? null,
            ];

            // Analysis = everything except the visual-asset paths we
            // just lifted into the raw slice. Keep _meta for traceability
            // but strip the path fields to avoid duplication.
            $analysisSlice = $payload;
            if (isset($analysisSlice['_meta'])) {
                $cleanMeta = $meta;
                unset(
                    $cleanMeta['profile_pic_path'],
                    $cleanMeta['screenshot_path'],
                    $cleanMeta['post_thumbnail_paths'],
                );
                $analysisSlice['_meta'] = $cleanMeta;
            }

            $evidence['instagram_audit']    = $rawSlice;
            $evidence['instagram_analysis'] = $analysisSlice;

            $audit->update(['audit_evidence' => $evidence]);
        });
    }
}
