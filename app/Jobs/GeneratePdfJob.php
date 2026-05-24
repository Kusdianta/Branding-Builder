<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\HubUsageLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * BB20 final-step job: triggered from the outer Bus::batch ->then() in
 * AnalyzeBrand after Track A (pillars) + Track B (Instagram) both
 * complete. Generates the activation kit PDF and marks the audit
 * status='done', which the dashboard polling translates into the
 * step='dashboard' redirect.
 *
 * Delegates PDF rendering to GenerateActivationKit (dispatched sync)
 * so the existing user-button-triggered path in the wizard keeps
 * working unchanged.
 */
class GeneratePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public function __construct(public readonly string $auditId) {}

    public function handle(HubUsageLogger $usageLogger): void
    {
        $audit = BrandAudit::findOrFail($this->auditId);

        $step = AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', 'generate_pdf')
            ->first();
        $step?->markRunning();

        try {
            GenerateActivationKit::dispatchSync($audit);
            $audit->refresh();
            $step?->markDone(['path' => $audit->activation_kit_path]);
        } catch (Throwable $e) {
            $step?->markFailed($e->getMessage());
            // Don't re-throw — PDF failure shouldn't block status='done'
            // for the rest of the audit; user can re-trigger via the
            // "Buat Activation Kit" button.
        }

        // BB145 — this is the SOLE point that flips the audit to DONE,
        // which is the gate the wizard uses to reveal the full dashboard.
        // By the time we get here, scoring + the LLM insights have all
        // run, so the preview the user finally sees is complete (no more
        // "still processing" tail). PDF success/failure itself is not a
        // gate — it's a downloadable artifact the user can re-trigger.
        //
        // Refresh + skip the flip when a terminal FAILED was already
        // recorded (e.g. AggregateAuditJob hard-failed >=2 pillars) so we
        // never mask a failed audit as a successful one.
        $audit->refresh();
        if ($audit->status !== BrandAudit::STATUS_FAILED) {
            // BB146 — guarantee the IG bonus audit is terminal before we open
            // the reveal gate. In the normal flow it already is (both IG jobs
            // are upstream in the chain), but a 240s scrape timeout can strand
            // instagram_audit_status at 'pending' (or 'scraped' on an analysis
            // timeout). Coerce that to a real failure so the dashboard reveals
            // with an honest banner instead of a never-resolving "masih dalam
            // proses" one — and so the reveal gate never hangs.
            $this->coerceLingeringInstagramStatus($audit);
            $audit->update(['status' => BrandAudit::STATUS_DONE]);
        }

        // This is the guaranteed final step of the pipeline — report the
        // audit's total execution time to the Hub for the admin duration
        // chart. Fire-and-forget; never affects the audit outcome.
        $this->reportTiming($audit, $usageLogger);
    }

    /**
     * BB146 — if the IG audit never reached a terminal state (scrape timed
     * out → 'pending'; analysis timed out → 'scraped'), persist a terminal
     * failure so the reveal gate can open. Runs only on the success path
     * (caller already excluded STATUS_FAILED). By here both IG jobs have
     * stopped, so there is no concurrent writer to instagram_audit — a
     * direct update() is race-free. The error prefixes match the friendly
     * banners in _instagram-audit-section.blade.php (worker_unavailable /
     * claude_analysis_failed).
     */
    private function coerceLingeringInstagramStatus(BrandAudit $audit): void
    {
        if ($audit->instagramAuditIsTerminal()) {
            return;
        }

        $reason = $audit->instagram_audit_status === 'scraped'
            ? 'claude_analysis_failed: instagram analysis did not finish (worker timeout)'
            : 'worker_unavailable: instagram scrape did not finish (worker timeout)';

        $audit->update([
            'instagram_audit_status' => 'audit_failed',
            'instagram_audit'        => ['error' => $reason],
        ]);
        $audit->refresh();
    }

    /**
     * Total wall-time = first step start → last step completion. Falls back
     * to the audit's created_at / now when step timestamps are unavailable.
     */
    private function reportTiming(BrandAudit $audit, HubUsageLogger $usageLogger): void
    {
        try {
            $bounds = AuditStep::where('brand_audit_id', $this->auditId)
                ->whereNotNull('started_at')
                ->selectRaw('MIN(started_at) as first_start, MAX(completed_at) as last_end')
                ->first();

            $start = $bounds?->first_start ? Carbon::parse($bounds->first_start) : $audit->created_at;
            $end   = $bounds?->last_end ? Carbon::parse($bounds->last_end) : Carbon::now();

            if ($start === null) {
                return;
            }

            $usageLogger->logAuditDuration(
                auditId: $audit->id,
                totalSeconds: (int) max(0, $start->diffInSeconds($end)),
                completedAt: $end->toIso8601String(),
                brandName: (string) $audit->brand_name,
            );
        } catch (Throwable $e) {
            // Reporting must never affect the audit outcome.
        }
    }
}
