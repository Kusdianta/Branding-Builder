<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Brand Recall pillar.
 * No LLM call — pure arithmetic against the canonical deck rubric.
 * ClaudeService wraps this and optionally appends an evidence narrative.
 *
 * Sub-buckets (total cap 100):
 *   rating_tier        35  — overall Google star rating tier
 *   review_count_tier  25  — volume of Google reviews
 *   keyword_saturation 25  — share of sampled reviews containing ≥1 positive keyword
 *   sentiment_quality  15  — avg star rating of sampled reviews
 */
final class RecallScorer
{
    /**
     * @param array{
     *     rating: float,
     *     review_count: int,
     *     keyword_hits: array<string,mixed>,
     *     sampled_reviews: list<array{text: string, rating: float}>,
     * } $inputs
     */
    public function score(array $inputs): PillarScore
    {
        $clusters       = (array) config('branding.recall_keyword_clusters', []);
        $sampledReviews = (array) ($inputs['sampled_reviews'] ?? []);

        $subBuckets = [
            'rating_tier'        => $this->calcRatingScore((float) $inputs['rating']),
            'review_count_tier'  => $this->calcCountScore((int) $inputs['review_count']),
            'keyword_saturation' => $this->keywordSaturation($sampledReviews, $clusters),
            'sentiment_quality'  => $this->sentimentQuality($sampledReviews),
        ];

        $total = max(0, min(100, array_sum($subBuckets)));

        return new PillarScore(
            pillarSlug:      ScoringRubric::PILLAR_RECALL,
            score:           $total,
            evidence:        [],
            reasoning:       '',
            subBucketScores: $subBuckets,
        );
    }

    private function calcRatingScore(float $rating): int
    {
        return match (true) {
            $rating >= 4.8 => 35,
            $rating >= 4.5 => 28,
            $rating >= 4.0 => 20,
            $rating >= 3.5 => 12,
            $rating >= 3.0 => 6,
            default        => 0,
        };
    }

    private function calcCountScore(int $count): int
    {
        return match (true) {
            $count > 200  => 25,
            $count >= 101 => 20,
            $count >= 51  => 15,
            $count >= 11  => 10,
            $count >= 1   => 5,
            default       => 0,
        };
    }

    /**
     * Count of sampled reviews containing ≥1 positive keyword phrase,
     * divided by total sampled, scaled to 25 pts.
     *
     * Scale: 5/5=25, 4/5=20, 3/5=15, 2/5=10, 1/5=5, 0/5=0
     *
     * @param list<array{text: string, rating: float}> $reviews
     * @param array<string,mixed> $clusters
     */
    private function keywordSaturation(array $reviews, array $clusters): int
    {
        $total = count($reviews);
        if ($total === 0) {
            return 0;
        }

        $phrases = array_merge(...array_values((array) ($clusters['positive'] ?? [])));

        $hitsCount = 0;
        foreach ($reviews as $review) {
            $text = mb_strtolower((string) ($review['text'] ?? ''));
            foreach ($phrases as $phrase) {
                if (str_contains($text, mb_strtolower((string) $phrase))) {
                    $hitsCount++;
                    break; // count each review once
                }
            }
        }

        $ratio = $hitsCount / $total;
        return min(25, (int) round($ratio * 5) * 5);
    }

    /**
     * Average star rating of sampled reviews → 15-pt tier.
     *
     * @param list<array{text: string, rating: float}> $reviews
     */
    private function sentimentQuality(array $reviews): int
    {
        $total = count($reviews);
        if ($total === 0) {
            return 0;
        }

        $avg = array_sum(array_column($reviews, 'rating')) / $total;

        return match (true) {
            $avg >= 4.8 => 15,
            $avg >= 4.5 => 12,
            $avg >= 4.0 => 8,
            $avg >= 3.5 => 4,
            default     => 0,
        };
    }
}
