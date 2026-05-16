<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchWebsiteJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Nema\WorkerClient\DTO\WebsiteScrapeResult;
use Nema\WorkerClient\Exceptions\WebsiteScrapeException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB78 — FetchWebsiteJob coverage. Verifies the four lifecycle paths:
 * skip (no URL), happy path, structured worker error, transport error.
 */
class FetchWebsiteJobTest extends TestCase
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
                'website_url' => 'https://lessworry.id',
            ], $touchpoints),
            'status'        => BrandAudit::STATUS_ANALYZING,
            'expires_at'    => now()->addDays(30),
        ]);
    }

    private function seedStep(BrandAudit $audit): AuditStep
    {
        return AuditStep::create([
            'brand_audit_id' => $audit->id,
            'step_key'       => 'fetch_website',
            'track'          => 'gather',
            'status'         => AuditStep::STATUS_PENDING,
            'order'          => 5,
        ]);
    }

    private function makeResult(): WebsiteScrapeResult
    {
        return new WebsiteScrapeResult(
            websiteUrl: 'https://lessworry.id',
            finalUrl: 'https://lessworry.id/home',
            title: 'Less Worry Laundry',
            metaDescription: 'kiloan + ekspres + antar jemput',
            canonicalUrl: 'https://lessworry.id/',
            h1Text: 'Laundry Bebas Worry',
            h2Texts: ['Harga', 'FAQ'],
            bodyExcerpt: 'sample body',
            hasPricingKeywords: true,
            hasPickupKeywords: true,
            hasExpressKeywords: true,
            hasComplaintPolicyKeywords: false,
            durationMs: 4200,
            limitations: [],
        );
    }

    #[Test]
    public function it_skips_cleanly_when_website_url_empty(): void
    {
        $audit = $this->makeAudit(['website_url' => '']);
        $step  = $this->seedStep($audit);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldNotReceive('scrapeWebsite');

        (new FetchWebsiteJob($audit->id))->handle($worker);

        $audit->refresh();
        $step->refresh();

        $this->assertNull($audit->audit_evidence['website']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertTrue($step->detail['skipped']);
        $this->assertSame('no_website_url', $step->detail['reason']);
    }

    #[Test]
    public function it_persists_result_to_evidence_on_happy_path(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('scrapeWebsite')
            ->once()
            ->with('https://lessworry.id', $audit->id, 30000)
            ->andReturn($this->makeResult());

        (new FetchWebsiteJob($audit->id))->handle($worker);

        $audit->refresh();
        $step->refresh();

        $website = $audit->audit_evidence['website'];
        $this->assertSame('Less Worry Laundry', $website['title']);
        $this->assertTrue($website['has_pricing_keywords']);
        $this->assertTrue($website['has_pickup_keywords']);
        $this->assertTrue($website['has_express_keywords']);
        $this->assertFalse($website['has_complaint_policy_keywords']);
        $this->assertSame(4200, $website['duration_ms']);

        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertTrue($step->detail['has_pricing_keywords']);
    }

    #[Test]
    public function it_persists_structured_error_when_worker_returns_unreachable(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('scrapeWebsite')
            ->once()
            ->andThrow(new WebsiteScrapeException(
                errorCode: 'unreachable',
                httpStatus: 502,
                detail: 'net::ERR_NAME_NOT_RESOLVED',
            ));

        (new FetchWebsiteJob($audit->id))->handle($worker);

        $audit->refresh();
        $step->refresh();

        $this->assertSame('unreachable', $audit->audit_evidence['website']['error']);
        $this->assertSame('net::ERR_NAME_NOT_RESOLVED', $audit->audit_evidence['website']['detail']);
        // Structured error keeps the step done (not failed) — the audit
        // still completes with a degraded website slice.
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertSame('unreachable', $step->detail['error_code']);
    }

    #[Test]
    public function it_persists_structured_error_when_worker_returns_timeout(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('scrapeWebsite')
            ->once()
            ->andThrow(new WebsiteScrapeException(
                errorCode: 'timeout',
                httpStatus: 504,
                detail: 'navigation exceeded budget',
            ));

        (new FetchWebsiteJob($audit->id))->handle($worker);

        $audit->refresh();
        $this->assertSame('timeout', $audit->audit_evidence['website']['error']);
    }

    #[Test]
    public function it_marks_step_failed_when_worker_unreachable(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('scrapeWebsite')
            ->once()
            ->andThrow(new WorkerNotAvailableException(
                'Connection refused',
                0,
            ));

        (new FetchWebsiteJob($audit->id))->handle($worker);

        $audit->refresh();
        $step->refresh();

        $this->assertNull($audit->audit_evidence['website']);
        $this->assertSame(AuditStep::STATUS_FAILED, $step->status);
        $this->assertStringContainsString('worker_unavailable', (string) ($step->detail['error'] ?? ''));
    }
}
