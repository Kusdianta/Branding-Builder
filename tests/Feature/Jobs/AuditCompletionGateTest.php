<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AggregateAuditJob;
use App\Jobs\GenerateActivationKit;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\GeneratePdfJob;
use App\Models\BrandAudit;
use App\Services\CreditLedger;
use App\Services\HubUsageLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB145 — the wizard reveals the full dashboard the instant the audit's
 * top-level status === 'done'. The pipeline must therefore flip to DONE
 * ONLY after the entire pipeline (scoring + LLM insights + PDF) has run,
 * so the user never sees an incomplete "preview" while insights/PDF are
 * still generating.
 *
 * Contract these tests lock:
 *   - AggregateAuditJob (a mid-pipeline scoring step) does NOT flip to
 *     DONE — it still computes the overall score.
 *   - GeneratePdfJob (the guaranteed final step) is the SOLE DONE-setter.
 *   - GeneratePdfJob never masks a terminal FAILED as DONE.
 *   - A crashed GenerateInsightsJob still rolls forward to the PDF step
 *     so the audit can never strand on the analyzing screen.
 */
class AuditCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // AggregateAuditJob's fire-and-forget target-score reasoning is the
        // only Claude touchpoint on the happy path. Empty key => the
        // generator's constructor throws MissingAnthropicKeyException, which
        // AggregateAuditJob swallows — keeping this test hermetic (no network).
        config(['services.anthropic.key' => '']);
    }

    private function makeScoredAudit(string $status = BrandAudit::STATUS_ANALYZING): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['gmaps_url' => 'https://maps.app.goo.gl/x'],
            'status'        => $status,
            'pillar_scores' => [
                'brand-recall'      => ['score' => 35, 'reasoning' => 'r'],
                'brand-konsistensi' => ['score' => 70, 'reasoning' => 'k'],
                'brand-experience'  => ['score' => 30, 'reasoning' => 'e'],
                'digital-presence'  => ['score' => 60, 'reasoning' => 'd'],
            ],
            'sub_bucket_scores' => ['brand-experience' => ['base' => 30]],
            'expires_at'        => now()->addDays(30),
        ]);
    }

    #[Test]
    public function aggregate_audit_job_does_not_reveal_the_dashboard(): void
    {
        $audit = $this->makeScoredAudit();

        (new AggregateAuditJob($audit->id))->handle($this->app->make(CreditLedger::class));

        $audit->refresh();
        // The gate stays closed — insights + PDF still have to run.
        $this->assertNotSame(BrandAudit::STATUS_DONE, $audit->status);
        $this->assertSame(BrandAudit::STATUS_ANALYZING, $audit->status);
        // Aggregation itself still did its job.
        $this->assertGreaterThan(0, (int) $audit->overall_score);
        $this->assertNotEmpty($audit->overall_label);
    }

    #[Test]
    public function generate_pdf_job_is_the_sole_done_setter(): void
    {
        Bus::fake([GenerateActivationKit::class]);
        $audit = $this->makeScoredAudit();

        (new GeneratePdfJob($audit->id))->handle($this->app->make(HubUsageLogger::class));

        $this->assertSame(BrandAudit::STATUS_DONE, $audit->refresh()->status);
    }

    #[Test]
    public function generate_pdf_job_does_not_clobber_a_terminal_failed(): void
    {
        Bus::fake([GenerateActivationKit::class]);
        $audit = $this->makeScoredAudit(BrandAudit::STATUS_FAILED);
        $audit->update(['instagram_audit_status' => 'pending']);

        (new GeneratePdfJob($audit->id))->handle($this->app->make(HubUsageLogger::class));

        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_FAILED, $audit->status);
        // BB146 — coercion is inside the success branch, so a FAILED audit's
        // lingering IG status is left untouched (the 'failed' reveal gate
        // already allows the dashboard to show).
        $this->assertSame('pending', $audit->instagram_audit_status);
    }

    #[Test]
    public function generate_pdf_coerces_lingering_pending_instagram_to_terminal(): void
    {
        Bus::fake([GenerateActivationKit::class]);
        $audit = $this->makeScoredAudit();
        $audit->update(['instagram_audit_status' => 'pending']);

        (new GeneratePdfJob($audit->id))->handle($this->app->make(HubUsageLogger::class));

        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_DONE, $audit->status);
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        $this->assertStringStartsWith('worker_unavailable', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function generate_pdf_coerces_lingering_scraped_instagram_to_terminal(): void
    {
        Bus::fake([GenerateActivationKit::class]);
        $audit = $this->makeScoredAudit();
        $audit->update(['instagram_audit_status' => 'scraped']);

        (new GeneratePdfJob($audit->id))->handle($this->app->make(HubUsageLogger::class));

        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_DONE, $audit->status);
        $this->assertSame('audit_failed', $audit->instagram_audit_status);
        // BB147 — a stranded 'scraped' status means the analysis never
        // finished; it must be coerced to the HONEST 'analysis_incomplete'
        // code, NOT 'claude_analysis_failed' (which lied "Claude error /
        // kuota habis" about an analysis that was merely interrupted).
        $this->assertStringStartsWith('analysis_incomplete', (string) ($audit->instagram_audit['error'] ?? ''));
        $this->assertStringNotContainsString('claude_analysis_failed', (string) ($audit->instagram_audit['error'] ?? ''));
    }

    #[Test]
    public function generate_pdf_does_not_touch_a_terminal_instagram_status(): void
    {
        Bus::fake([GenerateActivationKit::class]);
        $audit = $this->makeScoredAudit();
        $audit->update([
            'instagram_audit_status' => 'done',
            'instagram_audit'        => ['executive_summary' => 'all good'],
        ]);

        (new GeneratePdfJob($audit->id))->handle($this->app->make(HubUsageLogger::class));

        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_DONE, $audit->status);
        $this->assertSame('done', $audit->instagram_audit_status);
        $this->assertSame('all good', $audit->instagram_audit['executive_summary']);
    }

    #[Test]
    public function generate_pdf_does_not_touch_no_instagram_url_provided(): void
    {
        Bus::fake([GenerateActivationKit::class]);
        $audit = $this->makeScoredAudit();
        $audit->update(['instagram_audit_status' => 'no_instagram_url_provided']);

        (new GeneratePdfJob($audit->id))->handle($this->app->make(HubUsageLogger::class));

        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_DONE, $audit->status);
        $this->assertSame('no_instagram_url_provided', $audit->instagram_audit_status);
    }

    #[Test]
    public function crashed_insights_job_still_rolls_forward_to_pdf(): void
    {
        Bus::fake([GeneratePdfJob::class]);
        $audit = $this->makeScoredAudit();

        (new GenerateInsightsJob($audit->id))->failed(new \RuntimeException('boom'));

        Bus::assertDispatched(GeneratePdfJob::class, fn ($j) => $j->auditId === $audit->id);
    }
}
