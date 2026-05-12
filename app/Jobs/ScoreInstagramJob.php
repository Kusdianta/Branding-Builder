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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB20 Track B: Instagram profile audit (scrape via worker + Claude
 * analysis). Runs in parallel with ScorePillarsJob inside the outer
 * Bus::batch dispatched from AnalyzeBrand.
 *
 * InstagramProfileAuditService swallows all errors internally and
 * persists them as instagram_audit_status on the row — this job never
 * throws back to the batch, so a failed IG track does not block the
 * outer batch's ->then() (PDF generation).
 */
class ScoreInstagramJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public readonly string $auditId) {}

    public function handle(InstagramProfileAuditService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audit = BrandAudit::findOrFail($this->auditId);

        $scrapeStep   = $this->step('ig_scrape');
        $analysisStep = $this->step('ig_analysis');

        // The service does both scrape + Claude analysis behind one call.
        // We mark scrape running first, then transition to analysis once
        // the worker call returns (the service doesn't expose that seam,
        // so we approximate by flipping both steps around one boundary).
        $scrapeStep?->markRunning();

        try {
            $service->audit($audit);
            $audit->refresh();

            $status = (string) $audit->instagram_audit_status;
            if (in_array($status, ['done'], true)) {
                $scrapeStep?->markDone();
                $analysisStep?->markRunning();
                $analysisStep?->markDone();
            } elseif ($status === 'no_instagram_url_provided') {
                $scrapeStep?->markDone(['skipped' => true, 'reason' => 'no_instagram_url_provided']);
                $analysisStep?->markDone(['skipped' => true]);
            } else {
                // Any other terminal status (credentials_stale, rate_limited,
                // profile_not_found, audit_failed, no_credentials_available)
                // counts as scrape phase failed; analysis never started.
                $scrapeStep?->markFailed("instagram_audit_status={$status}");
                $analysisStep?->markFailed('not_reached', ['reason' => 'scrape_failed']);
            }
        } catch (Throwable $e) {
            // Defence in depth — the service contract says no throws.
            Log::error('ScoreInstagramJob: service threw despite contract', [
                'audit_id' => $this->auditId, 'class' => $e::class, 'error' => $e->getMessage(),
            ]);
            $scrapeStep?->markFailed($e->getMessage());
            $analysisStep?->markFailed('not_reached');
        }
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }
}
