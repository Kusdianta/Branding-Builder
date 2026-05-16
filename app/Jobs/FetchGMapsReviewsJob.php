<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\GMapsReviewsService;
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
 * BB52 — Phase 10 gather sub-job 2 of 3.
 *
 * Delegates to the existing GMapsReviewsService::fetch() (which writes
 * to the legacy gmaps_reviews column + gmaps_reviews_status enum),
 * then mirrors the persisted payload into audit_evidence.gmaps_scrape.
 *
 * The legacy column write is retained so existing read paths (PDF
 * partial pdf/_gmaps-reviews.blade.php, ExperiencePenaltyDetector
 * lookups in current ScorePillarsJob) keep working pre-BB54-cutover.
 * After BB54 lands, scorers read from audit_evidence directly and the
 * legacy column becomes a fallback for retry tooling.
 *
 * Marks the 'gather_gmaps' audit_step. Service contract is
 * never-throw; this job is a defensive try/catch wrapper.
 */
class FetchGMapsReviewsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** GMaps scrape can take 30-90s. Mirrors ScorePillarsJob's old budget. */
    public int $timeout = 180;

    public function __construct(public readonly string $auditId) {}

    public function handle(GMapsReviewsService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('gather_gmaps');
        $step?->markRunning();

        try {
            $audit = BrandAudit::findOrFail($this->auditId);
            $service->fetch($audit);

            $audit->refresh();
            $status   = (string) $audit->gmaps_reviews_status;
            $payload  = $audit->gmaps_reviews;

            $this->mirrorToEvidence($payload);

            $detail = ['status' => $status];
            if (is_array($payload)) {
                $detail['review_count'] = (int) ($payload['total_review_count'] ?? count((array) ($payload['reviews'] ?? [])));
            }

            if ($status === 'done') {
                $step?->markDone($detail);
            } elseif ($status === 'no_gmaps_url_provided') {
                $step?->markDone(['skipped' => true, 'reason' => $status]);
            } else {
                // Non-fatal terminal status — partial evidence path. Don't
                // fail the step (the batch must still complete); record the
                // status so the operator can diagnose + retry via BB59.
                $step?->markDone($detail);
            }
        } catch (Throwable $e) {
            // Defence in depth — service contract is never-throw.
            Log::error('FetchGMapsReviewsJob: service threw despite contract', [
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

    private function mirrorToEvidence(mixed $gmapsPayload): void
    {
        DB::transaction(function () use ($gmapsPayload): void {
            $audit = BrandAudit::findOrFail($this->auditId);
            $evidence = (array) ($audit->audit_evidence ?? []);
            $evidence['gmaps_scrape'] = is_array($gmapsPayload) ? $gmapsPayload : null;
            $audit->update(['audit_evidence' => $evidence]);
        });
    }
}
