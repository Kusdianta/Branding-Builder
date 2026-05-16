<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ValidateEvidenceJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\ClaudeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10 BB53 — validation coverage.
 *
 * Verifies the heuristic checks, LLM combination, and status transitions.
 * ClaudeService is mocked so tests don't hit the live Anthropic API.
 */
class ValidateEvidenceJobTest extends TestCase
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
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_ANALYZING,
            'audit_evidence' => [
                'places_api'         => null,
                'gmaps_scrape'       => [
                    'business_name' => 'Less Worry | Laundry Bebas Worry',
                    'address'       => 'Jl. Sukasari No. 5, Bandung 40161',
                ],
                'instagram_audit'    => ['username' => 'lessworry.id'],
                'instagram_analysis' => ['profile_branding' => ['name' => 'Less Worry']],
                'validation'         => null,
            ],
            'audit_evidence_status' => 'gathered',
            'expires_at'    => now()->addDays(30),
        ], $overrides));
    }

    private function seedStep(BrandAudit $audit): AuditStep
    {
        return AuditStep::create([
            'brand_audit_id' => $audit->id,
            'step_key'       => 'validate_evidence',
            'track'          => 'validate',
            'status'         => AuditStep::STATUS_PENDING,
            'order'          => 4,
        ]);
    }

    private function mockClaude(array $response): ClaudeService
    {
        $m = Mockery::mock(ClaudeService::class);
        $m->shouldReceive('validateBrandMatch')->once()->andReturn($response);
        $this->app->instance(ClaudeService::class, $m);
        return $m;
    }

    #[Test]
    public function it_writes_validation_block_and_marks_validated_when_confidence_is_high(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $this->mockClaude([
            'confidence'       => 0.95,
            'brand_name_match' => true,
            'city_match'       => true,
            'warnings'         => [],
            'reasoning'        => 'GMaps and IG profile names are clearly the same brand.',
        ]);

        $this->app->call([new ValidateEvidenceJob($audit->id), 'handle']);

        $audit->refresh();
        $step->refresh();

        $v = $audit->audit_evidence['validation'];
        $this->assertSame(0.95, $v['confidence']);
        $this->assertTrue($v['brand_name_match']);
        $this->assertTrue($v['city_match']);
        $this->assertSame([], $v['warnings']);
        $this->assertSame('validated', $audit->audit_evidence_status);
        $this->assertSame(BrandAudit::STATUS_ANALYZING, $audit->status); // top-level untouched on success
        $this->assertSame(AuditStep::STATUS_DONE, $step->status);
    }

    #[Test]
    public function it_transitions_to_validation_warning_when_confidence_is_low(): void
    {
        $audit = $this->makeAudit();
        $step  = $this->seedStep($audit);

        $this->mockClaude([
            'confidence'       => 0.2,
            'brand_name_match' => false,
            'city_match'       => true,
            'warnings'         => ['Nama brand input dan Instagram berbeda secara signifikan.'],
            'reasoning'        => 'Likely different brands.',
        ]);

        $this->app->call([new ValidateEvidenceJob($audit->id), 'handle']);

        $audit->refresh();

        $this->assertSame(0.2, $audit->audit_evidence['validation']['confidence']);
        $this->assertSame('validation_warning', $audit->audit_evidence_status);
        $this->assertSame(BrandAudit::STATUS_VALIDATION_WARNING, $audit->status);
        $this->assertTrue($audit->hasValidationWarning());
        $this->assertTrue($audit->isComplete()); // warning still counts as complete
    }

    #[Test]
    public function heuristic_substring_match_catches_semantic_equivalents(): void
    {
        // Substring "less worry" should match "less worry laundry bebas worry"
        // (post-normalize stripping "laundry" suffix).
        $audit = $this->makeAudit([
            'brand_name'    => 'Less Worry',
            'audit_evidence' => [
                'gmaps_scrape'       => [
                    'business_name' => 'Less Worry Laundry Bebas Worry',
                    'address'       => 'Jl. Sukasari No. 5, Bandung 40161',
                ],
                'instagram_audit'    => null,
                'instagram_analysis' => null,
                'places_api'         => null,
                'validation'         => null,
            ],
        ]);
        $step = $this->seedStep($audit);

        // LLM returns null on brand_name_match — heuristic should fill it.
        $this->mockClaude([
            'confidence'       => 0.8,
            'brand_name_match' => null,
            'city_match'       => null,
            'warnings'         => [],
            'reasoning'        => 'LLM did not return match flags.',
        ]);

        $this->app->call([new ValidateEvidenceJob($audit->id), 'handle']);

        $audit->refresh();
        $v = $audit->audit_evidence['validation'];
        $this->assertTrue($v['brand_name_match'], 'heuristic substring match should fill null LLM flag');
        $this->assertTrue($v['city_match'], 'heuristic city token should fill null LLM flag');
        $this->assertSame('validated', $audit->audit_evidence_status);
    }

    #[Test]
    public function it_flags_city_mismatch_when_address_does_not_contain_city(): void
    {
        $audit = $this->makeAudit([
            'city' => 'Surabaya',
            'audit_evidence' => [
                'gmaps_scrape' => [
                    'business_name' => 'Less Worry Laundry',
                    'address'       => 'Jl. Sukasari No. 5, Bandung 40161',
                ],
                'instagram_audit'    => null,
                'instagram_analysis' => null,
                'places_api'         => null,
                'validation'         => null,
            ],
        ]);
        $step = $this->seedStep($audit);

        $this->mockClaude([
            'confidence'       => 0.6,
            'brand_name_match' => true,
            'city_match'       => false,
            'warnings'         => [],
            'reasoning'        => 'Address is in a different city than input.',
        ]);

        $this->app->call([new ValidateEvidenceJob($audit->id), 'handle']);

        $audit->refresh();
        $v = $audit->audit_evidence['validation'];
        $this->assertFalse($v['city_match']);
        $this->assertNotEmpty($v['warnings']);
        $this->assertStringContainsString('Surabaya', implode(' ', $v['warnings']));
    }

    #[Test]
    public function it_records_match_flag_as_null_when_no_scraped_names_present(): void
    {
        $audit = $this->makeAudit([
            'audit_evidence' => [
                'gmaps_scrape'       => null,
                'instagram_audit'    => null,
                'instagram_analysis' => null,
                'places_api'         => null,
                'validation'         => null,
            ],
        ]);
        $step = $this->seedStep($audit);

        $this->mockClaude([
            'confidence'       => 0.5,
            'brand_name_match' => null,
            'city_match'       => null,
            'warnings'         => [],
            'reasoning'        => 'No scraped data available.',
        ]);

        $this->app->call([new ValidateEvidenceJob($audit->id), 'handle']);

        $audit->refresh();
        $v = $audit->audit_evidence['validation'];
        $this->assertNull($v['brand_name_match']);
        $this->assertNull($v['city_match']);
    }
}
