<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AnalyzeInstagramJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\InstagramProfileAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB69 — AnalyzeInstagramJob coverage. Verifies the Phase 2 analyze pass
 * reads the snapshot left by FetchInstagramAuditJob and mirrors the
 * resulting analysis JSON to audit_evidence.instagram_analysis.
 */
class AnalyzeInstagramJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeAudit(array $overrides = []): BrandAudit
    {
        return BrandAudit::create(array_merge([
            'session_token'          => Str::random(64),
            'ip_address'             => '127.0.0.1',
            'brand_name'             => 'Less Worry Laundry',
            'city'                   => 'Bandung',
            'service_type'           => 'kiloan',
            'touchpoints'            => ['instagram_url' => 'https://www.instagram.com/lessworry.id/'],
            'status'                 => BrandAudit::STATUS_ANALYZING,
            'instagram_audit_status' => 'scraped',
            'instagram_audit'        => [
                'raw_payload' => [
                    'username'    => 'lessworry.id',
                    'captured_at' => '2026-05-16T02:00:00Z',
                ],
                '_meta' => [
                    'username'         => 'lessworry.id',
                    'captured_at'      => '2026-05-16T02:00:00Z',
                    'profile_pic_path' => 'audits/x/profile_pic.jpg',
                ],
            ],
            'expires_at'             => now()->addDays(30),
        ], $overrides));
    }

    private function seedStep(BrandAudit $audit): AuditStep
    {
        return AuditStep::create([
            'brand_audit_id' => $audit->id,
            'step_key'       => 'analyze_instagram',
            'track'          => 'gather',
            'status'         => AuditStep::STATUS_PENDING,
            'order'          => 4,
        ]);
    }

    #[Test]
    public function it_mirrors_analysis_to_evidence_when_status_done(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $service = Mockery::mock(InstagramProfileAuditService::class);
        $service->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (BrandAudit $a): void {
                $a->update([
                    'instagram_audit_status' => 'done',
                    'instagram_audit'        => [
                        'executive_summary' => 'Analysis OK',
                        'scorecard'         => ['profile_branding' => 75],
                        '_meta'             => [
                            'username'         => 'lessworry.id',
                            'profile_pic_path' => 'audits/x/profile_pic.jpg',
                        ],
                    ],
                ]);
            });

        (new AnalyzeInstagramJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();

        $ev = $audit->audit_evidence;
        $this->assertSame('Analysis OK', $ev['instagram_analysis']['executive_summary']);
        $this->assertSame(75, $ev['instagram_analysis']['scorecard']['profile_branding']);
        // Visual paths stripped from analysis slice (FetchInstagramAuditJob
        // owns those in the raw slice).
        $this->assertArrayNotHasKey('profile_pic_path', $ev['instagram_analysis']['_meta']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertSame('done', $step->detail['status']);
    }

    #[Test]
    public function it_skips_when_status_not_scraped(): void
    {
        $audit = $this->makeAudit(['instagram_audit_status' => 'no_instagram_url_provided']);
        $step  = $this->seedStep($audit);

        $service = Mockery::mock(InstagramProfileAuditService::class);
        $service->shouldNotReceive('analyze');

        (new AnalyzeInstagramJob($audit->id))->handle($service);

        $step->refresh();
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertTrue($step->detail['skipped'] ?? false);
    }

    #[Test]
    public function it_writes_null_analysis_slice_when_service_clears_audit_column(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $service = Mockery::mock(InstagramProfileAuditService::class);
        $service->shouldReceive('analyze')
            ->once()
            ->andReturnUsing(function (BrandAudit $a): void {
                $a->update([
                    'instagram_audit_status' => 'audit_failed',
                    'instagram_audit'        => null,
                ]);
            });

        (new AnalyzeInstagramJob($audit->id))->handle($service);

        $audit->refresh();
        $step->refresh();
        $this->assertNull($audit->audit_evidence['instagram_analysis']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertSame('audit_failed', $step->detail['status']);
    }
}
