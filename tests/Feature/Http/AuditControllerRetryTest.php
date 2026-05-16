<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Jobs\FetchGMapsReviewsJob;
use App\Jobs\FetchInstagramAuditJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10 BB59 — retry endpoint coverage.
 */
class AuditControllerRetryTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_DONE,
            'expires_at'    => now()->addDays(30),
        ]);
    }

    private function seedSteps(BrandAudit $audit): void
    {
        $keys = [
            'gather_places', 'gather_gmaps', 'gather_instagram', 'validate_evidence',
            'score_recall', 'score_digital', 'score_konsistensi', 'score_experience',
            'generate_recommendations', 'generate_quick_wins', 'generate_positioning', 'generate_pdf',
        ];
        foreach ($keys as $i => $k) {
            AuditStep::create([
                'brand_audit_id' => $audit->id,
                'step_key'       => $k,
                'track'          => 'x',
                'status'         => AuditStep::STATUS_DONE,
                'completed_at'   => now()->subMinutes(2),
                'order'          => $i + 1,
            ]);
        }
    }

    #[Test]
    public function retry_gather_gmaps_resets_steps_and_dispatches_fetch_job(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();
        $this->seedSteps($audit);

        $resp = $this->postJson(route('audit.retry-step', $audit->session_token), [
            'step_key' => 'gather_gmaps',
        ]);

        $resp->assertStatus(202)->assertJson(['status' => 'queued', 'step' => 'gather_gmaps']);

        // gather step reset
        $reset = AuditStep::where('brand_audit_id', $audit->id)->where('step_key', 'gather_gmaps')->first();
        $this->assertSame(AuditStep::STATUS_PENDING, $reset->status);
        $this->assertNull($reset->started_at);
        $this->assertNull($reset->completed_at);

        // downstream steps reset
        $downstream = AuditStep::where('brand_audit_id', $audit->id)
            ->whereIn('step_key', ['score_recall', 'generate_pdf'])
            ->pluck('status')
            ->all();
        $this->assertSame([AuditStep::STATUS_PENDING, AuditStep::STATUS_PENDING], $downstream);

        // audit flipped back to analyzing
        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_ANALYZING, $audit->status);

        // batch dispatch with the right fetch job
        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) use ($audit): bool {
            $classes = collect($batch->jobs->all())->map(fn ($j) => get_class($j))->all();
            return $batch->name === "audit:{$audit->id}:retry-gather_gmaps"
                && in_array(FetchGMapsReviewsJob::class, $classes, true);
        });
    }

    #[Test]
    public function retry_gather_instagram_dispatches_instagram_fetch(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();
        $this->seedSteps($audit);

        $this->postJson(route('audit.retry-step', $audit->session_token), [
            'step_key' => 'gather_instagram',
        ])->assertStatus(202);

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
            $classes = collect($batch->jobs->all())->map(fn ($j) => get_class($j))->all();
            return in_array(FetchInstagramAuditJob::class, $classes, true);
        });
    }

    #[Test]
    public function retry_rejects_non_gather_step_keys(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();
        $this->seedSteps($audit);

        $this->postJson(route('audit.retry-step', $audit->session_token), [
            'step_key' => 'score_recall',
        ])->assertStatus(422)->assertJson(['status' => 'rejected', 'reason' => 'step_not_retryable']);

        Bus::assertNothingBatched();
    }

    #[Test]
    public function retry_returns_404_when_step_row_absent(): void
    {
        Bus::fake();
        $audit = $this->makeAudit(); // no steps seeded

        $this->postJson(route('audit.retry-step', $audit->session_token), [
            'step_key' => 'gather_gmaps',
        ])->assertStatus(404)->assertJson(['reason' => 'step_not_found']);
    }
}
