<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchGMapsReviewsJob;
use App\Jobs\FetchInstagramAuditJob;
use App\Jobs\FetchPlacesApiJob;
use App\Jobs\FetchWebsiteJob;
use App\Jobs\GatherEvidenceJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\GMapsReviewsService;
use App\Services\InstagramProfileAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10 BB52 — evidence-gathering layer coverage.
 *
 * Verifies the three Fetch*Job slice writers + the GatherEvidenceJob
 * orchestrator. Mocks underlying services so tests are
 * deterministic; the integration-level smoke happens after BB55 wires
 * the new pipeline into AnalyzeBrand.
 */
class GatherEvidenceJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeAudit(array $touchpoints = []): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => array_merge([
                'instagram_url' => 'https://www.instagram.com/lessworry.id/',
                'gmaps_url'     => 'https://maps.app.goo.gl/example',
            ], $touchpoints),
            'status'        => BrandAudit::STATUS_ANALYZING,
            'expires_at'    => now()->addDays(30),
        ]);
    }

    private function seedStep(BrandAudit $audit, string $key, string $track, int $order): AuditStep
    {
        return AuditStep::create([
            'brand_audit_id' => $audit->id,
            'step_key'       => $key,
            'track'          => $track,
            'status'         => AuditStep::STATUS_PENDING,
            'order'          => $order,
        ]);
    }

    #[Test]
    public function fetch_places_api_job_skips_gracefully_when_api_key_missing(): void
    {
        // GoogleMapsReviewsFetcher is `new`-ed inside the job (not
        // container-resolved) and is marked final, so the happy-path
        // happy-path test would require HTTP::fake() of the underlying
        // Places API call. Here we cover the no-credential skip path:
        // null places_api slice + step.status=done + reason logged.
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit, 'gather_places', 'gather', 1);

        config(['services.google.maps_api_key' => '']);
        // BB66: handle() now takes a HubUsageLogger dep — the no-api-key
        // path short-circuits before any logger calls, so resolving the
        // real bound instance works fine here.
        (new FetchPlacesApiJob($audit->id))->handle($this->app->make(\App\Services\HubUsageLogger::class));

        $audit->refresh();
        $step->refresh();

        $this->assertArrayHasKey('places_api', $audit->audit_evidence ?? []);
        $this->assertNull($audit->audit_evidence['places_api']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertSame('no_api_key', $step->detail['reason'] ?? null);
    }

    #[Test]
    public function fetch_gmaps_reviews_job_mirrors_legacy_column_into_evidence(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit, 'gather_gmaps', 'gather', 2);

        $service = Mockery::mock(GMapsReviewsService::class);
        $service->shouldReceive('fetch')
            ->once()
            ->andReturnUsing(function (BrandAudit $a): void {
                $a->update([
                    'gmaps_reviews_status' => 'done',
                    'gmaps_reviews'        => [
                        'business_name'      => 'Less Worry Laundry',
                        'rating'             => 4.6,
                        'total_review_count' => 142,
                        'reviews'            => [
                            ['author' => 'A', 'rating_value' => 5, 'text' => 'good'],
                            ['author' => 'B', 'rating_value' => 4, 'text' => 'ok'],
                        ],
                    ],
                ]);
            });

        (new FetchGMapsReviewsJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();

        $ev = $audit->audit_evidence;
        $this->assertNotNull($ev['gmaps_scrape']);
        $this->assertSame('Less Worry Laundry', $ev['gmaps_scrape']['business_name']);
        $this->assertCount(2, $ev['gmaps_scrape']['reviews']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertSame('done', $step->detail['status']);
        $this->assertSame(142, $step->detail['review_count']);
    }

    #[Test]
    public function fetch_gmaps_reviews_job_persists_null_evidence_when_service_clears_legacy_column(): void
    {
        $audit = $this->makeAudit(['gmaps_url' => '']);
        $step  = $this->seedStep($audit, 'gather_gmaps', 'gather', 2);

        $service = Mockery::mock(GMapsReviewsService::class);
        $service->shouldReceive('fetch')
            ->once()
            ->andReturnUsing(function (BrandAudit $a): void {
                $a->update(['gmaps_reviews_status' => 'no_gmaps_url_provided']);
            });

        (new FetchGMapsReviewsJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();
        $this->assertNull($audit->audit_evidence['gmaps_scrape']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertTrue($step->detail['skipped']);
    }

    #[Test]
    public function fetch_instagram_audit_job_mirrors_raw_slice_after_scrape(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit, 'gather_instagram', 'gather', 3);
        $this->seedStep($audit, 'analyze_instagram', 'gather', 4);

        // BB69: scrape() persists the snapshot shape (raw_payload + _meta),
        // status='scraped'. The job then dispatches AnalyzeInstagramJob
        // synchronously — which mocks below; we stub it as a no-op to keep
        // this test focused on the FetchInstagramAuditJob mirror behaviour.
        $snapshot = [
            'raw_payload' => [
                'username'    => 'lessworry.id',
                'captured_at' => '2026-05-16T02:00:00Z',
                'profile'     => ['name' => 'Less Worry', 'bio' => '', 'followers' => 1000],
            ],
            '_meta' => [
                'username'             => 'lessworry.id',
                'captured_at'          => '2026-05-16T02:00:00Z',
                'profile_pic_path'     => 'audits/01abc/profile_pic.png',
                'screenshot_path'      => 'audits/01abc/screenshot.png',
                'post_thumbnail_paths' => [
                    'audits/01abc/post_0.jpg',
                    'audits/01abc/post_1.jpg',
                ],
            ],
        ];

        $service = Mockery::mock(InstagramProfileAuditService::class);
        $service->shouldReceive('scrape')
            ->once()
            ->andReturnUsing(function (BrandAudit $a) use ($snapshot): void {
                $a->update([
                    'instagram_audit_status' => 'scraped',
                    'instagram_audit'        => $snapshot,
                ]);
            });
        // BB71: analyze() now runs in the Phase 2 batch
        // (AnalysisOrchestratorJob), not inline. This gather job MUST
        // NOT call analyze() — covered by shouldNotReceive.
        $service->shouldNotReceive('analyze');
        $this->app->instance(InstagramProfileAuditService::class, $service);

        (new FetchInstagramAuditJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();
        $ev = $audit->audit_evidence;

        // Raw slice (this job's responsibility) has the visual paths +
        // identification fields lifted from _meta.
        $this->assertSame('audits/01abc/profile_pic.png', $ev['instagram_audit']['profile_pic_path']);
        $this->assertSame('audits/01abc/screenshot.png', $ev['instagram_audit']['screenshot_path']);
        $this->assertCount(2, $ev['instagram_audit']['post_thumbnail_paths']);
        $this->assertSame('lessworry.id', $ev['instagram_audit']['username']);

        // Analysis slice is AnalyzeInstagramJob's responsibility (see
        // AnalyzeInstagramJobTest). This job's contract ends at the raw
        // slice; we only verify the gather_instagram step is done here.
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
    }

    #[Test]
    public function fetch_instagram_audit_job_handles_no_url_provided(): void
    {
        $audit = $this->makeAudit(['instagram_url' => '']);
        $step  = $this->seedStep($audit, 'gather_instagram', 'gather', 3);

        $service = Mockery::mock(InstagramProfileAuditService::class);
        $service->shouldReceive('scrape')
            ->once()
            ->andReturnUsing(function (BrandAudit $a): void {
                $a->update(['instagram_audit_status' => 'no_instagram_url_provided']);
            });
        // analyze() must NOT be called when scrape returned a terminal status.
        $service->shouldNotReceive('analyze');

        (new FetchInstagramAuditJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();

        $this->assertNull($audit->audit_evidence['instagram_audit']);
        // BB69: instagram_analysis is AnalyzeInstagramJob's slice; the
        // no-url path never reaches analyze(), so the key stays absent
        // (not null) from evidence.
        $this->assertArrayNotHasKey('instagram_analysis', $audit->audit_evidence);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertTrue($step->detail['skipped']);
    }

    #[Test]
    public function gather_evidence_job_dispatches_three_sub_jobs_as_inner_batch_with_allow_failures(): void
    {
        Bus::fake();

        $audit = $this->makeAudit();

        (new GatherEvidenceJob($audit->id))->handle();

        $audit->refresh();
        $this->assertSame('gathering', $audit->audit_evidence_status);

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) use ($audit): bool {
            $classes = collect($batch->jobs->all())->map(fn ($j) => get_class($j))->all();
            return $batch->name === "audit:{$audit->id}:gather"
                && in_array(FetchPlacesApiJob::class, $classes, true)
                && in_array(FetchGMapsReviewsJob::class, $classes, true)
                && in_array(FetchInstagramAuditJob::class, $classes, true)
                && in_array(FetchWebsiteJob::class, $classes, true)
                && $batch->options['allowFailures'] === true;
        });
    }

    #[Test]
    public function fetch_gmaps_reviews_job_does_not_throw_when_service_throws(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit, 'gather_gmaps', 'gather', 2);

        $service = Mockery::mock(GMapsReviewsService::class);
        $service->shouldReceive('fetch')
            ->once()
            ->andThrow(new \RuntimeException('unexpected'));

        // No throw expected — defence-in-depth catch.
        (new FetchGMapsReviewsJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();
        $this->assertNull($audit->audit_evidence['gmaps_scrape']);
        $this->assertSame(AuditStep::STATUS_FAILED, $step->status);
    }
}
