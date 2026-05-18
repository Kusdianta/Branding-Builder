<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Models\BrandAudit;
use App\Services\Scoring\RecallScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB117 — RecallScorer v3 path tests. PPT-rubric sub-buckets:
 *   rating_tier (35) + review_count_tier (25)
 *   + kualitas_ulasan_positif (20, merged keyword + sentiment)
 *   + manajemen_ulasan (20, owner reply rate)
 * Total caps internally at 100; ClaudeService::scoreRecall skips
 * the search_recall layering for v3.
 */
class RecallScorerV3Test extends TestCase
{
    private RecallScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new RecallScorer();
    }

    private function v3Inputs(array $overrides = []): array
    {
        return array_merge([
            '_wizard_version'  => BrandAudit::WIZARD_V3,
            'brand_name'       => 'Test Laundry',
            'rating'           => 0.0,
            'review_count'     => 0,
            'keyword_hits'     => [],
            'sampled_reviews'  => [],
            'full_reviews'     => [],
            'owner_reply_rate' => 0.0,
            'has_sop_declared' => false,
        ], $overrides);
    }

    #[Test]
    public function v3_drops_sentiment_quality_sub_bucket(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'rating'        => 4.7,
            'review_count'  => 100,
            'full_reviews'  => [['text' => 'bersih harum', 'rating' => 5.0]],
        ]));

        $this->assertArrayNotHasKey('sentiment_quality', $score->subBucketScores);
        $this->assertArrayNotHasKey('review_keyword_quality', $score->subBucketScores);
        $this->assertArrayHasKey('kualitas_ulasan_positif', $score->subBucketScores);
        $this->assertArrayHasKey('manajemen_ulasan', $score->subBucketScores);
    }

    #[Test]
    public function v3_rating_top_tier_awards_35(): void
    {
        $score = $this->scorer->score($this->v3Inputs(['rating' => 4.9]));
        $this->assertSame(35, $score->subBucketScores['rating_tier']);
        $this->assertSame(35, $score->scoreBreakdown['rating_tier']['cap']);
    }

    #[Test]
    public function v3_count_top_tier_awards_25(): void
    {
        $score = $this->scorer->score($this->v3Inputs(['review_count' => 600]));
        $this->assertSame(25, $score->subBucketScores['review_count_tier']);
        $this->assertSame(25, $score->scoreBreakdown['review_count_tier']['cap']);
    }

    #[Test]
    public function v3_kualitas_ulasan_caps_at_20(): void
    {
        $reviews = [];
        for ($i = 0; $i < 10; $i++) {
            $reviews[] = ['text' => 'sangat bersih harum wangi', 'rating' => 5.0];
        }
        $score = $this->scorer->score($this->v3Inputs([
            'full_reviews' => $reviews,
        ]));
        $this->assertLessThanOrEqual(20, $score->subBucketScores['kualitas_ulasan_positif']);
    }

    #[Test]
    public function v3_manajemen_ulasan_uses_reply_rate_tiers(): void
    {
        $high = $this->scorer->score($this->v3Inputs([
            'owner_reply_rate' => 0.96,
            'has_sop_declared' => false,
        ]));
        $this->assertSame(20, $high->subBucketScores['manajemen_ulasan']);

        $mid = $this->scorer->score($this->v3Inputs([
            'owner_reply_rate' => 0.60,
            'has_sop_declared' => false,
        ]));
        $this->assertSame(10, $mid->subBucketScores['manajemen_ulasan']);

        $low = $this->scorer->score($this->v3Inputs([
            'owner_reply_rate' => 0.10,
            'has_sop_declared' => false,
        ]));
        $this->assertSame(0, $low->subBucketScores['manajemen_ulasan']);
    }

    #[Test]
    public function v3_sop_bonus_applies_when_declared_and_verified(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'owner_reply_rate' => 0.55,
            'has_sop_declared' => true,
        ]));
        // base 10 (reply rate 0.50-0.94) + bonus 5 = 15
        $this->assertSame(15, $score->subBucketScores['manajemen_ulasan']);
    }

    #[Test]
    public function v3_sop_bonus_capped_at_20_not_exceeded(): void
    {
        $score = $this->scorer->score($this->v3Inputs([
            'owner_reply_rate' => 0.99,
            'has_sop_declared' => true,
        ]));
        // base 20 + bonus 5 = 25 → cap 20
        $this->assertSame(20, $score->subBucketScores['manajemen_ulasan']);
    }

    #[Test]
    public function v3_total_caps_at_100(): void
    {
        $reviews = [];
        for ($i = 0; $i < 30; $i++) {
            $reviews[] = ['text' => 'sangat bersih harum wangi rapi cepat', 'rating' => 5.0];
        }
        $score = $this->scorer->score($this->v3Inputs([
            'rating'           => 4.9,
            'review_count'     => 600,
            'full_reviews'     => $reviews,
            'owner_reply_rate' => 1.0,
            'has_sop_declared' => true,
        ]));
        $this->assertLessThanOrEqual(100, $score->score);
    }

    #[Test]
    public function v1_default_path_still_includes_legacy_sub_buckets(): void
    {
        $score = $this->scorer->score([
            'rating'          => 4.7,
            'review_count'    => 100,
            'keyword_hits'    => [],
            'sampled_reviews' => [['text' => 'bersih', 'rating' => 5.0]],
            // No _wizard_version → defaults to v1.
        ]);
        $this->assertArrayHasKey('sentiment_quality', $score->subBucketScores);
        $this->assertArrayHasKey('review_keyword_quality', $score->subBucketScores);
        $this->assertArrayNotHasKey('manajemen_ulasan', $score->subBucketScores);
    }
}
