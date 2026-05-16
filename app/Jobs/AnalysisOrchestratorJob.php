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
 * BB71 — Phase 11 Phase 2 (analyze) orchestrator.
 *
 * Phase 2 sits between gather (Phase 1) and validate (Phase 3). It runs
 * the analysis-layer jobs in parallel:
 *
 *   AnalyzeInstagramJob       — Claude analysis of the IG scrape
 *   ExtractServiceSignalsJob  — hybrid PHP + LLM band signal extraction
 *
 * Each writes into evidence.analysis.<key>; downstream Phase 4 pillar
 * scorers read those keys for the score-time inputs Phase 11 was
 * designed to surface.
 *
 * Mirrors the BB52 GatherEvidenceJob pattern:
 *   - allowFailures() so a partial analysis layer is acceptable
 *   - ->then() chains ValidateEvidenceJob (Phase 3)
 *   - ->catch() still chains Phase 3 — analysis failures degrade
 *     gracefully (downstream scorers handle missing analysis.* keys
 *     per the BB75 tier classifier and BB57 vision fallback)
 *
 * Audit status during this phase remains audit_evidence_status='gathered'
 * (set by GatherEvidenceJob.then). Transitioning to 'analyzing' here
 * would be cosmetic only; the validate phase flips to 'validated' /
 * 'validation_warning' after Phase 3 completes.
 */
class AnalysisOrchestratorJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $auditId = $this->auditId;

        // Defence-in-depth — refuse to start the analysis batch if the
        // audit row vanished between phases (test cleanup, manual
        // delete). Without this guard the inner batch would still fire
        // its then() callback against a non-existent ID.
        if (! BrandAudit::where('id', $auditId)->exists()) {
            Log::warning('AnalysisOrchestratorJob: audit no longer exists; aborting Phase 2', [
                'audit_id' => $auditId,
            ]);
            return;
        }

        Bus::batch([
            new AnalyzeInstagramJob($auditId),
            new ExtractServiceSignalsJob($auditId),
        ])
            ->name("audit:{$auditId}:analyze")
            ->allowFailures()
            ->then(static function (Batch $batch) use ($auditId): void {
                ValidateEvidenceJob::dispatch($auditId);
            })
            ->catch(static function (Batch $batch, Throwable $e) use ($auditId): void {
                Log::error('AnalysisOrchestratorJob: inner batch catch fired', [
                    'audit_id' => $auditId, 'error' => $e->getMessage(),
                ]);
                ValidateEvidenceJob::dispatch($auditId);
            })
            ->dispatch();
    }
}
