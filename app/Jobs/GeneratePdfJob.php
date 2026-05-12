<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(): void
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

        // Mark the audit done regardless of PDF outcome — the dashboard
        // data is ready; PDF is a downloadable artifact, not a gate.
        $audit->update(['status' => BrandAudit::STATUS_DONE]);
    }
}
