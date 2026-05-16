<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ExtractServiceSignalsJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\Scoring\ServiceSignalsExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB74 — ExtractServiceSignalsJob coverage. Verifies the job persists
 * the extractor output to audit_evidence.analysis.service_signals and
 * marks the step correctly.
 */
class ExtractServiceSignalsJobTest extends TestCase
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
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['instagram_url' => 'https://instagram.com/x'],
            'status'        => BrandAudit::STATUS_ANALYZING,
            'audit_evidence' => [
                'gmaps_scrape' => [
                    'reviews' => [['text' => 'Ada antar jemput nya, mantap.']],
                ],
            ],
            'expires_at'    => now()->addDays(30),
        ], $overrides));
    }

    private function seedStep(BrandAudit $audit): AuditStep
    {
        return AuditStep::create([
            'brand_audit_id' => $audit->id,
            'step_key'       => 'extract_service_signals',
            'track'          => 'analyze',
            'status'         => AuditStep::STATUS_PENDING,
            'order'          => 6,
        ]);
    }

    #[Test]
    public function it_persists_service_signals_to_evidence_analysis_slice(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $extractor = Mockery::mock(ServiceSignalsExtractor::class);
        $extractor->shouldReceive('extract')
            ->once()
            ->andReturn([
                'bonus_ekspres' => [
                    'detected'   => false,
                    'confidence' => 0.0,
                    'sources'    => [],
                    'verified_by_llm' => false,
                ],
                'bonus_antar_jemput' => [
                    'detected'   => true,
                    'confidence' => 0.7,
                    'primary_source' => 'review_mention',
                    'sources'    => [['source' => 'review_mention', 'snippet' => 'antar jemput', 'score' => 0.7]],
                    'verified_by_llm' => false,
                ],
                'variasi_layanan' => [
                    'detected_variants' => ['kiloan'],
                    'sources' => ['kiloan' => [['source' => 'ig_bio', 'snippet' => 'kiloan']]],
                    'verified_by_llm' => false,
                ],
            ]);

        (new ExtractServiceSignalsJob($audit->id))->handle($extractor);

        $audit->refresh();
        $step->refresh();

        $sig = $audit->audit_evidence['analysis']['service_signals'];
        $this->assertSame(0.7, $sig['bonus_antar_jemput']['confidence']);
        $this->assertTrue($sig['bonus_antar_jemput']['detected']);
        $this->assertSame(['kiloan'], $sig['variasi_layanan']['detected_variants']);
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
        $this->assertContains('bonus_antar_jemput', $step->detail['detected']);
    }

    #[Test]
    public function it_passes_operator_declarations_to_extractor(): void
    {
        $audit = $this->makeAudit([
            'operator_declarations' => ['has_ekspres' => true],
        ]);
        $this->seedStep($audit);

        $extractor = Mockery::mock(ServiceSignalsExtractor::class);
        $extractor->shouldReceive('extract')
            ->once()
            ->withArgs(function (array $evidence, ?array $decls): bool {
                return is_array($evidence)
                    && is_array($decls)
                    && ($decls['has_ekspres'] ?? null) === true;
            })
            ->andReturn([]);

        (new ExtractServiceSignalsJob($audit->id))->handle($extractor);
        $this->assertTrue(true); // matcher passed
    }

    #[Test]
    public function it_persists_empty_payload_and_marks_step_failed_when_extractor_throws(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $extractor = Mockery::mock(ServiceSignalsExtractor::class);
        $extractor->shouldReceive('extract')
            ->once()
            ->andThrow(new \RuntimeException('boom'));

        (new ExtractServiceSignalsJob($audit->id))->handle($extractor);

        $audit->refresh();
        $step->refresh();
        $this->assertSame([], $audit->audit_evidence['analysis']['service_signals']);
        $this->assertSame(AuditStep::STATUS_FAILED, $step->status);
    }
}
