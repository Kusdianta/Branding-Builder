<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AnalyzeBrand;
use App\Jobs\GatherEvidenceJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10 BB55 — orchestrator smoke.
 *
 * Locks the new 12-step audit_steps seed + the kick-off contract
 * (status flip + GatherEvidenceJob dispatch). Phase chains beyond
 * GatherEvidenceJob are covered by GatherEvidenceJobTest +
 * ValidateEvidenceJobTest.
 */
class AnalyzeBrandTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => [
                'instagram_url' => 'https://www.instagram.com/lessworry.id/',
                'gmaps_url'     => 'https://maps.app.goo.gl/x',
            ],
            'status'        => BrandAudit::STATUS_PENDING,
            'expires_at'    => now()->addDays(30),
        ]);
    }

    #[Test]
    public function it_seeds_the_thirteen_step_pipeline_in_phase_order(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();

        (new AnalyzeBrand($audit->id))->handle();

        $steps = AuditStep::where('brand_audit_id', $audit->id)
            ->orderBy('order')
            ->get(['step_key', 'track', 'order'])
            ->map(fn ($s) => "{$s->order}:{$s->track}:{$s->step_key}")
            ->all();

        // BB69: analyze_instagram added between gather_instagram and
        // validate_evidence (still in 'gather' track until BB71 introduces
        // a dedicated 'analyze' phase).
        $this->assertSame([
            '1:gather:gather_places',
            '2:gather:gather_gmaps',
            '3:gather:gather_instagram',
            '4:gather:analyze_instagram',
            '5:validate:validate_evidence',
            '6:score:score_recall',
            '7:score:score_digital',
            '8:score:score_konsistensi',
            '9:score:score_experience',
            '10:final:generate_recommendations',
            '11:final:generate_quick_wins',
            '12:final:generate_positioning',
            '13:final:generate_pdf',
        ], $steps);
    }

    #[Test]
    public function it_flips_status_to_analyzing_and_dispatches_gather_evidence_job(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();

        (new AnalyzeBrand($audit->id))->handle();

        $audit->refresh();
        $this->assertSame(BrandAudit::STATUS_ANALYZING, $audit->status);
        Bus::assertDispatched(GatherEvidenceJob::class, fn ($job) => $job->auditId === $audit->id);
    }

    #[Test]
    public function legacy_step_keys_no_longer_seeded(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();

        (new AnalyzeBrand($audit->id))->handle();

        $legacyKeys = ['fetch_gmaps', 'fetch_gmaps_reviews', 'apply_experience_penalties', 'aggregate_pillars', 'ig_scrape', 'ig_analysis'];
        $present = AuditStep::where('brand_audit_id', $audit->id)
            ->whereIn('step_key', $legacyKeys)
            ->count();

        $this->assertSame(0, $present, 'no legacy Track A/B step keys should be seeded by BB55 AnalyzeBrand');
    }
}
