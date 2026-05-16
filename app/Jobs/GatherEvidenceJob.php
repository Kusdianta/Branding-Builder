<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB52 — Phase 10 evidence-gathering orchestrator.
 *
 * Phase 1 of the new 3-phase pipeline (gather -> validate -> score),
 * replacing the Phase 7-B Track A / Track B split. Dispatches the
 * three Fetch*Job sub-jobs as an INNER Bus::batch with
 * allowFailures(): partial evidence is a valid intermediate state
 * because scorers degrade gracefully when an evidence slice is null.
 *
 * Lifecycle on audit_evidence_status:
 *
 *   pending     (BB51 default)
 *      |
 *      v   GatherEvidenceJob::handle()
 *   gathering
 *      |
 *      v   inner batch ->then()  (ALL 3 sub-jobs done, even on partial failure)
 *   gathered
 *      |
 *      v   BB55 chains ValidateEvidenceJob
 *   validated  |  validation_warning   (BB53)
 *
 * The batch's ->then() callback runs only after every sub-job either
 * completes or is recorded as failed via allowFailures(). The outer
 * BB55 chain hangs off ->then() of this batch the same way Phase 7-B's
 * AnalyzeBrand hangs GenerateInsightsJob off Track A+B's outer batch.
 *
 * The job itself never throws; sub-job errors are absorbed by the
 * allowFailures() contract and surfaced via audit_steps + status enum.
 */
class GatherEvidenceJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Per-job timeout is the soft cap; sub-jobs have their own per-job
     * timeouts (FetchInstagramAuditJob is 240s, FetchGMapsReviewsJob is
     * 180s, FetchPlacesApiJob is 60s). This outer timeout protects the
     * orchestrator if the batch dispatch itself misbehaves.
     */
    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audit = BrandAudit::findOrFail($this->auditId);
        $audit->update(['audit_evidence_status' => 'gathering']);

        $auditId = $this->auditId;

        Bus::batch([
            new FetchPlacesApiJob($auditId),
            new FetchGMapsReviewsJob($auditId),
            new FetchInstagramAuditJob($auditId),
        ])
            ->name("audit:{$auditId}:gather")
            ->allowFailures()
            ->then(static function (Batch $batch) use ($auditId): void {
                // Idempotent transition — sub-jobs may have written to
                // audit_evidence concurrently; we only flip the status here.
                BrandAudit::where('id', $auditId)
                    ->update(['audit_evidence_status' => 'gathered']);
            })
            ->catch(static function (Batch $batch, Throwable $e) use ($auditId): void {
                Log::error('GatherEvidenceJob: inner batch catch fired (allowFailures should make this rare)', [
                    'audit_id' => $auditId, 'error' => $e->getMessage(),
                ]);
                // Still transition to 'gathered' so BB55's chain can decide
                // what to do — partial evidence is acceptable downstream.
                BrandAudit::where('id', $auditId)
                    ->update(['audit_evidence_status' => 'gathered']);
            })
            ->dispatch();
    }
}
