<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Models\BrandAudit;
use App\Services\Scoring\DigitalPresenceScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB117 — DigitalPresenceScorer v3 path tests. PPT-rubric sub-buckets:
 *   has_gmaps (25) + has_instagram (20 graded) + has_website (20 live check)
 *   + has_wa (15) + has_tiktok (10) + review_count_5plus (5)
 *   + review_count_50plus (5) = 100.
 */
class DigitalPresenceScorerV3Test extends TestCase
{
    private DigitalPresenceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new DigitalPresenceScorer();
    }

    private function v3Inputs(array $overrides = []): array
    {
        return array_merge([
            '_wizard_version'                       => BrandAudit::WIZARD_V3,
            'has_gmaps'                             => false,
            'has_instagram'                         => false,
            'has_website'                           => false,
            'has_wa_business'                       => false,
            'has_tiktok'                            => false,
            'review_count'                          => 0,
            'instagram_activity_score'              => null,
            'instagram_activity_evidence'           => [],
            'website_is_live'                       => false,
            'website_evidence'                      => [],
            'tiktok_check_status'                   => 'not_checked',
        ], $overrides);
    }

    #[Test]
    public function v3_instagram_uses_activity_score_not_boolean(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'instagram_activity_score' => 16,
        ]));
        $this->assertSame(16, $score->subBucketScores['has_instagram']);
    }

    #[Test]
    public function v3_instagram_caps_at_20_when_score_exceeds(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'instagram_activity_score' => 99,
        ]));
        $this->assertSame(20, $score->subBucketScores['has_instagram']);
    }

    #[Test]
    public function v3_instagram_zero_when_score_null(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'instagram_activity_score' => null,
        ]));
        $this->assertSame(0, $score->subBucketScores['has_instagram']);
    }

    #[Test]
    public function v3_website_uses_liveness_check(): void
    {
        $live = $this->scorer->score($this->v3Inputs(['website_is_live' => true]));
        $this->assertSame(20, $live->subBucketScores['has_website']);

        $dead = $this->scorer->score($this->v3Inputs(['website_is_live' => false]));
        $this->assertSame(0, $dead->subBucketScores['has_website']);
    }

    #[Test]
    public function v3_tiktok_promoted_back_to_ten(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'tiktok_check_status' => 'found',
        ]));
        $this->assertSame(10, $score->subBucketScores['has_tiktok']);
    }

    #[Test]
    public function v3_tiktok_not_found_awards_zero(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'tiktok_check_status' => 'not_found',
        ]));
        $this->assertSame(0, $score->subBucketScores['has_tiktok']);
    }

    #[Test]
    public function v3_review_bonus_split_into_5plus_and_50plus(): void
    {
        $zero = $this->scorer->score($this->v3Inputs(['review_count' => 0]));
        $this->assertSame(0, $zero->subBucketScores['review_count_5plus']);
        $this->assertSame(0, $zero->subBucketScores['review_count_50plus']);

        $ten = $this->scorer->score($this->v3Inputs(['review_count' => 10]));
        $this->assertSame(5, $ten->subBucketScores['review_count_5plus']);
        $this->assertSame(0, $ten->subBucketScores['review_count_50plus']);

        $fifty = $this->scorer->score($this->v3Inputs(['review_count' => 60]));
        $this->assertSame(5, $fifty->subBucketScores['review_count_5plus']);
        $this->assertSame(5, $fifty->subBucketScores['review_count_50plus']);

        $this->assertArrayNotHasKey('review_bonus', $fifty->subBucketScores);
    }

    #[Test]
    public function v3_total_caps_at_100(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'has_gmaps'                  => true,
            'instagram_activity_score'   => 20,
            'website_is_live'            => true,
            'has_wa_business'            => true,
            'tiktok_check_status'        => 'found',
            'review_count'               => 600,
        ]));
        // 25 + 20 + 20 + 15 + 10 + 5 + 5 = 100
        $this->assertSame(100, $score->score);
        $this->assertLessThanOrEqual(100, $score->score);
    }

    #[Test]
    public function v1_legacy_path_still_uses_old_review_bonus(): void
    {
        // No _wizard_version → legacy path.
        $score = $this->scorer->score([
            'has_gmaps'       => true,
            'has_instagram'   => false,
            'has_website'     => false,
            'has_wa_business' => false,
            'has_tiktok'      => true,
            'review_count'    => 60,
        ]);
        $this->assertArrayHasKey('review_bonus', $score->subBucketScores);
        $this->assertArrayNotHasKey('review_count_5plus', $score->subBucketScores);
        $this->assertSame(3, $score->subBucketScores['has_tiktok'], 'legacy v1 keeps BB101 demotion of TikTok to 3 pts');
    }
}
