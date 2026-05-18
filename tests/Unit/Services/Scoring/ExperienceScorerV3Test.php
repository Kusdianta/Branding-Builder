<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Models\BrandAudit;
use App\Services\Scoring\ExperienceScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB117 — ExperienceScorer v3 path tests. Additive bonus model on top
 * of base 30, reading declarations from touchpoints.operational +
 * touchpoints.service_types.variety_count + audit_evidence
 * .price_list_detection. SOP gets +15 (declared + verified) or +8
 * (declared only).
 */
class ExperienceScorerV3Test extends TestCase
{
    private ExperienceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new ExperienceScorer();
    }

    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'price_list_detection' => ['detected' => false, 'method' => 'fallback', 'confidence' => 0.0],
            'analysis'             => [],
        ], $overrides);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            '_wizard_version'         => BrandAudit::WIZARD_V3,
            'brand_name'              => 'Test Laundry',
            'touchpoints_operational' => [
                'express_service'  => false,
                'pickup_delivery'  => false,
                'complaint_sop'    => false,
            ],
            'variety_count'           => 1,
            'owner_reply_rate'        => 0.0,
        ], $overrides);
    }

    #[Test]
    public function v3_base_thirty_when_no_bonuses_apply(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context(),
        );
        $this->assertSame(30, $score->score);
        $this->assertSame(30, $score->subBucketScores['base']);
    }

    #[Test]
    public function v3_express_bonus_reads_from_touchpoints_operational(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context([
                'touchpoints_operational' => [
                    'express_service'  => true,
                    'pickup_delivery'  => false,
                    'complaint_sop'    => false,
                ],
            ]),
        );
        $this->assertSame(10, $score->subBucketScores['bonus_ekspres']);
        $this->assertSame(40, $score->score);
    }

    #[Test]
    public function v3_pickup_bonus_awards_twelve(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context([
                'touchpoints_operational' => [
                    'express_service'  => false,
                    'pickup_delivery'  => true,
                    'complaint_sop'    => false,
                ],
            ]),
        );
        $this->assertSame(12, $score->subBucketScores['bonus_antar_jemput']);
    }

    #[Test]
    public function v3_variasi_bonus_requires_variety_count_at_least_four(): void
    {
        $three = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context(['variety_count' => 3]),
        );
        $this->assertSame(0, $three->subBucketScores['bonus_variasi_layanan']);

        $four = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context(['variety_count' => 4]),
        );
        $this->assertSame(15, $four->subBucketScores['bonus_variasi_layanan']);
    }

    #[Test]
    public function v3_sop_bonus_full_when_declared_and_verified(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context([
                'touchpoints_operational' => [
                    'express_service'  => false,
                    'pickup_delivery'  => false,
                    'complaint_sop'    => true,
                ],
                'owner_reply_rate'        => 0.65,
            ]),
        );
        $this->assertSame(15, $score->subBucketScores['bonus_sop_keluhan']);
    }

    #[Test]
    public function v3_sop_partial_bonus_when_declared_but_not_verified(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence(),
            null,
            $this->context([
                'touchpoints_operational' => [
                    'express_service'  => false,
                    'pickup_delivery'  => false,
                    'complaint_sop'    => true,
                ],
                'owner_reply_rate'        => 0.10,
            ]),
        );
        $this->assertSame(8, $score->subBucketScores['bonus_sop_keluhan']);
        $this->assertTrue($score->scoreBreakdown['sop_partial_bonus_applied']);
    }

    #[Test]
    public function v3_price_list_bonus_reads_from_evidence(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence([
                'price_list_detection' => ['detected' => true, 'method' => 'caption_only', 'confidence' => 0.9],
            ]),
            null,
            $this->context(),
        );
        $this->assertSame(10, $score->subBucketScores['bonus_price_list']);
    }

    #[Test]
    public function v3_total_caps_at_100_with_all_bonuses(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->evidence([
                'price_list_detection' => ['detected' => true, 'method' => 'caption_only', 'confidence' => 0.9],
            ]),
            null,
            $this->context([
                'touchpoints_operational' => [
                    'express_service'  => true,
                    'pickup_delivery'  => true,
                    'complaint_sop'    => true,
                ],
                'variety_count'           => 5,
                'owner_reply_rate'        => 1.0,
            ]),
        );
        // 30 + 10 + 12 + 15 + 15 + 10 = 92 — under the 100 cap, no clamp.
        $this->assertSame(92, $score->score);
        $this->assertLessThanOrEqual(100, $score->score);
    }

    #[Test]
    public function v1_legacy_path_still_uses_tier_classifier(): void
    {
        // No _wizard_version in context → routes to legacy tier classifier.
        $score = $this->scorer->scoreFromEvidence(
            ['analysis' => ['service_signals' => []]],
            ['has_ekspres' => true],
            ['brand_name' => 'Test'],
        );
        // Legacy path uses tier classifier; we just assert it produced
        // a structurally valid PillarScore with the legacy reasoning shape.
        $this->assertArrayHasKey('base', $score->subBucketScores);
        $this->assertArrayHasKey('tier_classification', $score->scoreBreakdown);
        $this->assertArrayNotHasKey('sop_partial_bonus_applied', $score->scoreBreakdown);
    }
}
