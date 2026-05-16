<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\Recommendation\CompetitivePositioningGenerator;
use App\Services\Recommendation\QuickWinsGenerator;
use App\Services\Recommendation\RecommendationGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 9 BB38: post-batch insight generation.
 *
 * Runs the three apikprimadya-style generators (Recommendation,
 * QuickWins, CompetitivePositioning) sequentially after BOTH tracks
 * (pillar scoring + Instagram analysis) complete, then dispatches
 * GeneratePdfJob. This sequencing matters because:
 *
 *   - RecommendationGenerator needs pillar scores to rank by
 *     weakest-pillar urgency (Track A output)
 *   - All three generators benefit from instagram_audit context
 *     (Track B output) for richer per-brand specificity
 *
 * Each generator runs in its own try/catch so a single LLM hiccup
 * doesn't take down the others. Each writes its own column directly
 * via $audit->update() so mid-run failures still persist what was
 * already generated. PDF generation always fires at the end —
 * GeneratePdfJob's templates render gracefully when any column is
 * null (BB39-BB43 fall-back paths).
 *
 * Wall-time budget: ~30-60s for all three Claude calls in serial.
 * Could parallelise via Bus::batch but the marginal latency win
 * isn't worth the orchestration complexity here — the calling user
 * already sees the dashboard immediately after Track A + B finish;
 * insights + PDF are background polish that arrive a minute later.
 */
class GenerateInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Three serial Claude calls — generous ceiling. */
    public int $timeout = 180;

    public function __construct(public readonly string $auditId) {}

    public function handle(
        RecommendationGenerator $recommendations,
        QuickWinsGenerator $quickWins,
        CompetitivePositioningGenerator $positioning,
    ): void {
        $audit = BrandAudit::findOrFail($this->auditId);

        // BB66: tag each generator's Claude call with the audit id so the
        // Hub api_usage_log dashboard can roll up per-audit cost across
        // the three insight passes.
        $recommendations->setAuditContext($this->auditId);
        $quickWins->setAuditContext($this->auditId);
        $positioning->setAuditContext($this->auditId);

        $this->runStep(
            'generate_recommendations',
            fn () => $audit->update($recommendations->generate($audit)),
        );

        $this->runStep(
            'generate_quick_wins',
            fn () => $audit->update($quickWins->generate($audit)),
        );

        $this->runStep(
            'generate_positioning',
            fn () => $audit->update(['competitive_positioning' => $positioning->generate($audit)]),
        );

        // Hand off to PDF generation regardless of generator outcomes —
        // templates handle null/empty payloads.
        GeneratePdfJob::dispatch($this->auditId);
    }

    private function runStep(string $stepKey, callable $work): void
    {
        $step = AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $stepKey)
            ->first();

        $step?->markRunning();
        try {
            $work();
            $step?->markDone();
        } catch (Throwable $e) {
            Log::warning("GenerateInsightsJob: {$stepKey} failed", [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);
            $step?->markFailed($e->getMessage());
        }
    }
}
