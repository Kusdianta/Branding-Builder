<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Brand Recall pillar.
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
        $rating         = (float) $inputs['rating'];
        $count          = (int) $inputs['review_count'];

        $ratingScore                  = $this->calcRatingScore($rating);
        $countScore                   = $this->calcCountScore($count);
        [$kwScore, $kwHits, $kwTotal] = $this->keywordSaturation($sampledReviews, $clusters);
        [$sentScore, $sentAvg]        = $this->sentimentQuality($sampledReviews);

        $subBuckets = [
            'rating_tier'        => $ratingScore,
            'review_count_tier'  => $countScore,
            'keyword_saturation' => $kwScore,
            'sentiment_quality'  => $sentScore,
        ];

        $total = max(0, min(100, array_sum($subBuckets)));

        $breakdown = [
            'rating_tier'        => $this->ratingBreakdown($rating, $ratingScore),
            'review_count_tier'  => $this->countBreakdown($count, $countScore),
            'keyword_saturation' => $this->kwBreakdown($kwScore, $kwHits, $kwTotal),
            'sentiment_quality'  => $this->sentBreakdown($sentScore, $sentAvg, count($sampledReviews)),
        ];

        return new PillarScore(
            pillarSlug:      ScoringRubric::PILLAR_RECALL,
            score:           $total,
            evidence:        [],
            reasoning:       '',
            subBucketScores: $subBuckets,
            scoreBreakdown:  $breakdown,
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
     * @param  list<array{text: string, rating: float}>  $reviews
     * @param  array<string,mixed>  $clusters
     * @return array{0:int,1:int,2:int}  [score, hitsCount, total]
     */
    private function keywordSaturation(array $reviews, array $clusters): array
    {
        $total = count($reviews);
        if ($total === 0) {
            return [0, 0, 0];
        }

        $phrases = array_merge(...array_values((array) ($clusters['positive'] ?? [])));

        $hitsCount = 0;
        foreach ($reviews as $review) {
            $text = mb_strtolower((string) ($review['text'] ?? ''));
            foreach ($phrases as $phrase) {
                if (str_contains($text, mb_strtolower((string) $phrase))) {
                    $hitsCount++;
                    break;
                }
            }
        }

        $ratio = $hitsCount / $total;
        return [min(25, (int) round($ratio * 5) * 5), $hitsCount, $total];
    }

    /**
     * @param  list<array{text: string, rating: float}>  $reviews
     * @return array{0:int,1:float}  [score, avgRating]
     */
    private function sentimentQuality(array $reviews): array
    {
        $total = count($reviews);
        if ($total === 0) {
            return [0, 0.0];
        }

        $avg = array_sum(array_column($reviews, 'rating')) / $total;

        $score = match (true) {
            $avg >= 4.8 => 15,
            $avg >= 4.5 => 12,
            $avg >= 4.0 => 8,
            $avg >= 3.5 => 4,
            default     => 0,
        };

        return [$score, round($avg, 2)];
    }

    /** @return array<string,mixed> */
    private function ratingBreakdown(float $rating, int $score): array
    {
        $noData = $rating <= 0.0;
        return [
            'score'      => $score,
            'cap'        => 35,
            'raw_inputs' => ['rating' => $noData ? null : $rating, 'source' => 'Google Maps Places API'],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '≥4.8',           'points' => 35, 'matched' => $rating >= 4.8],
                ['range' => '4.5–4.7',        'points' => 28, 'matched' => $rating >= 4.5 && $rating < 4.8],
                ['range' => '4.0–4.4',        'points' => 20, 'matched' => $rating >= 4.0 && $rating < 4.5],
                ['range' => '3.5–3.9',        'points' => 12, 'matched' => $rating >= 3.5 && $rating < 4.0],
                ['range' => '3.0–3.4',        'points' => 6,  'matched' => $rating >= 3.0 && $rating < 3.5],
                ['range' => '<3.0',           'points' => 0,  'matched' => ! $noData && $rating < 3.0],
                ['range' => 'Tidak tersedia', 'points' => 0,  'matched' => $noData],
            ],
            'explanation_id' => 'rating_tier_v1',
        ];
    }

    /** @return array<string,mixed> */
    private function countBreakdown(int $count, int $score): array
    {
        return [
            'score'      => $score,
            'cap'        => 25,
            'raw_inputs' => ['review_count' => $count, 'source' => 'Google Maps Places API'],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '>200',    'points' => 25, 'matched' => $count > 200],
                ['range' => '101–200', 'points' => 20, 'matched' => $count >= 101 && $count <= 200],
                ['range' => '51–100',  'points' => 15, 'matched' => $count >= 51 && $count <= 100],
                ['range' => '11–50',   'points' => 10, 'matched' => $count >= 11 && $count <= 50],
                ['range' => '1–10',    'points' => 5,  'matched' => $count >= 1 && $count <= 10],
                ['range' => '0',       'points' => 0,  'matched' => $count === 0],
            ],
            'explanation_id' => 'review_count_tier_v1',
        ];
    }

    /** @return array<string,mixed> */
    private function kwBreakdown(int $score, int $hitsCount, int $total): array
    {
        $hitPct = $total > 0 ? round($hitsCount / $total * 100, 1) : 0.0;
        return [
            'score'      => $score,
            'cap'        => 25,
            'raw_inputs' => [
                'sampled_reviews' => $total,
                'keyword_hits'    => $hitsCount,
                'hit_rate_pct'    => $hitPct,
            ],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '0–9%',   'points' => 0,  'matched' => $score === 0 && $total > 0],
                ['range' => '10–29%', 'points' => 5,  'matched' => $score === 5],
                ['range' => '30–49%', 'points' => 10, 'matched' => $score === 10],
                ['range' => '50–69%', 'points' => 15, 'matched' => $score === 15],
                ['range' => '70–89%', 'points' => 20, 'matched' => $score === 20],
                ['range' => '≥90%',   'points' => 25, 'matched' => $score === 25],
                ['range' => 'Tidak ada data', 'points' => 0, 'matched' => $total === 0],
            ],
            'explanation_id' => 'keyword_saturation_v1',
        ];
    }

    /** @return array<string,mixed> */
    private function sentBreakdown(int $score, float $avgRating, int $sampleSize): array
    {
        $noData = $sampleSize === 0;
        return [
            'score'      => $score,
            'cap'        => 15,
            'raw_inputs' => [
                'avg_rating'  => $noData ? null : $avgRating,
                'sample_size' => $sampleSize,
                'source'      => 'Google Maps Places API',
            ],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '≥4.8',           'points' => 15, 'matched' => $avgRating >= 4.8 && ! $noData],
                ['range' => '4.5–4.7',        'points' => 12, 'matched' => $avgRating >= 4.5 && $avgRating < 4.8],
                ['range' => '4.0–4.4',        'points' => 8,  'matched' => $avgRating >= 4.0 && $avgRating < 4.5],
                ['range' => '3.5–3.9',        'points' => 4,  'matched' => $avgRating >= 3.5 && $avgRating < 4.0],
                ['range' => '<3.5',           'points' => 0,  'matched' => ! $noData && $avgRating < 3.5],
                ['range' => 'Tidak ada data', 'points' => 0,  'matched' => $noData],
            ],
            'explanation_id' => 'sentiment_quality_v1',
        ];
    }
}
