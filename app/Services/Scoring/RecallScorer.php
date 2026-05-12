<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Brand Recall pillar's review-based signals.
 *
 * Sub-buckets covered here (caps after Phase 6-partial rebalance, total 65):
 *   rating_tier        25  — overall Google star rating tier
 *   review_count_tier  15  — volume of Google reviews
 *   keyword_saturation 15  — share of sampled reviews containing ≥1 positive keyword
 *   sentiment_quality  10  — avg star rating of sampled reviews
 *
 * The remaining 35 points come from the autocomplete-based "search_recall"
 * sub-bucket emitted by SearchRecallScorer and merged in by ClaudeService.
 *
 * Phase 8 BB25: the scorer accepts an optional ``full_reviews`` input
 * (the Phase 8 W8 GMaps reviews scrape, up to 30 reviews vs Places API's
 * 5-cap). When present and non-empty, full_reviews replaces
 * sampled_reviews as the corpus driving keyword saturation and
 * sentiment quality. The score_breakdown raw_inputs gain a
 * ``sample_source`` flag so downstream renderers can show
 * "30 ulasan (full scrape)" vs "5 ulasan (Places API)" honestly.
 *
 * Tier tables stay unchanged — they were already volume-tolerant
 * (review_count_tier already handles up to ≥500). The hit_rate_pct
 * tier table (kwBreakdown) was tuned to 5-sample populations and
 * intentionally remains so; with ~30-review corpora most brands will
 * land 1-2 tiers lower, which is the more honest signal Phase 8 is
 * after.
 */
final class RecallScorer
{
    /**
     * @param array{
     *     rating: float,
     *     review_count: int,
     *     keyword_hits: array<string,mixed>,
     *     sampled_reviews: list<array{text: string, rating: float}>,
     *     full_reviews?: list<array{text: string, rating: float}>,
     * } $inputs
     */
    public function score(array $inputs): PillarScore
    {
        $clusters       = (array) config('branding.recall_keyword_clusters', []);
        $sampledReviews = (array) ($inputs['sampled_reviews'] ?? []);
        $fullReviews    = (array) ($inputs['full_reviews'] ?? []);
        $rating         = (float) $inputs['rating'];
        $count          = (int) $inputs['review_count'];

        // BB25: prefer full_reviews when the GMaps W8 scraper returned
        // a non-empty corpus. Falls back to the Places API 5-sample
        // when full_reviews is absent or empty (BB30 legacy audits).
        if ($fullReviews !== []) {
            $reviewCorpus = $fullReviews;
            $sampleSource = 'gmaps_scrape';
        } else {
            $reviewCorpus = $sampledReviews;
            $sampleSource = $sampledReviews === [] ? 'none' : 'places_api_sample';
        }

        $ratingScore                  = $this->calcRatingScore($rating);
        $countScore                   = $this->calcCountScore($count);
        [$kwScore, $kwHits, $kwTotal] = $this->keywordSaturation($reviewCorpus, $clusters);
        [$sentScore, $sentAvg]        = $this->sentimentQuality($reviewCorpus);

        // BB18: keyword_saturation → review_keyword_quality. The old name
        // implied "keyword density across our review samples" — actually
        // misleading; the metric is the share of sampled reviews that
        // include ≥1 positive keyword (harum, bersih, etc.). The new name
        // matches the more honest "Kata Kunci Positif di Ulasan" label.
        // True branded-search-share measure is filed as Phase 8 backlog.
        $subBuckets = [
            'rating_tier'            => $ratingScore,
            'review_count_tier'      => $countScore,
            'review_keyword_quality' => $kwScore,
            'sentiment_quality'      => $sentScore,
        ];

        // Cap at 65 — search_recall (35 more pts) is layered on later by
        // ClaudeService::scoreRecall so the full pillar caps at 100.
        $total = max(0, min(65, array_sum($subBuckets)));

        $breakdown = [
            'rating_tier'            => $this->ratingBreakdown($rating, $ratingScore),
            'review_count_tier'      => $this->countBreakdown($count, $countScore),
            'review_keyword_quality' => $this->kwBreakdown($kwScore, $kwHits, $kwTotal, $sampleSource),
            'sentiment_quality'      => $this->sentBreakdown($sentScore, $sentAvg, count($reviewCorpus), $sampleSource),
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
            $rating >= 4.8 => 25,
            $rating >= 4.5 => 20,
            $rating >= 4.0 => 14,
            $rating >= 3.5 => 8,
            $rating >= 3.0 => 4,
            default        => 0,
        };
    }

    private function calcCountScore(int $count): int
    {
        return match (true) {
            $count >= 500 => 15,
            $count >= 200 => 12,
            $count >= 100 => 9,
            $count >= 50  => 5,
            $count >= 11  => 3,
            $count >= 1   => 1,
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
        $pct   = $ratio * 100;
        $score = match (true) {
            $pct >= 90 => 15,
            $pct >= 70 => 12,
            $pct >= 50 => 9,
            $pct >= 30 => 6,
            $pct >= 10 => 3,
            default    => 0,
        };

        return [$score, $hitsCount, $total];
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
            $avg >= 4.8 => 10,
            $avg >= 4.5 => 8,
            $avg >= 4.0 => 5,
            $avg >= 3.5 => 3,
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
            'cap'        => 25,
            'raw_inputs' => ['rating' => $noData ? null : $rating, 'source' => 'Google Maps Places API'],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '≥4.8',           'points' => 25, 'matched' => $rating >= 4.8],
                ['range' => '4.5–4.7',        'points' => 20, 'matched' => $rating >= 4.5 && $rating < 4.8],
                ['range' => '4.0–4.4',        'points' => 14, 'matched' => $rating >= 4.0 && $rating < 4.5],
                ['range' => '3.5–3.9',        'points' => 8,  'matched' => $rating >= 3.5 && $rating < 4.0],
                ['range' => '3.0–3.4',        'points' => 4,  'matched' => $rating >= 3.0 && $rating < 3.5],
                ['range' => '<3.0',           'points' => 0,  'matched' => ! $noData && $rating < 3.0],
                ['range' => 'Tidak tersedia', 'points' => 0,  'matched' => $noData],
            ],
            'explanation_id' => 'rating_tier_v2',
        ];
    }

    /** @return array<string,mixed> */
    private function countBreakdown(int $count, int $score): array
    {
        return [
            'score'      => $score,
            'cap'        => 15,
            'raw_inputs' => ['review_count' => $count, 'source' => 'Google Maps Places API'],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '≥500',    'points' => 15, 'matched' => $count >= 500],
                ['range' => '200–499', 'points' => 12, 'matched' => $count >= 200 && $count < 500],
                ['range' => '100–199', 'points' => 9,  'matched' => $count >= 100 && $count < 200],
                ['range' => '50–99',   'points' => 5,  'matched' => $count >= 50  && $count < 100],
                ['range' => '11–49',   'points' => 3,  'matched' => $count >= 11  && $count < 50],
                ['range' => '1–10',    'points' => 1,  'matched' => $count >= 1   && $count < 11],
                ['range' => '0',       'points' => 0,  'matched' => $count === 0],
            ],
            'explanation_id' => 'review_count_tier_v2',
        ];
    }

    /** @return array<string,mixed> */
    private function kwBreakdown(int $score, int $hitsCount, int $total, string $sampleSource = 'places_api_sample'): array
    {
        $hitPct = $total > 0 ? round($hitsCount / $total * 100, 1) : 0.0;
        return [
            'score'      => $score,
            'cap'        => 15,
            'raw_inputs' => [
                'sampled_reviews' => $total,
                'keyword_hits'    => $hitsCount,
                'hit_rate_pct'    => $hitPct,
                'sample_source'   => $sampleSource,
            ],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '0–9%',   'points' => 0,  'matched' => $score === 0 && $total > 0],
                ['range' => '10–29%', 'points' => 3,  'matched' => $score === 3],
                ['range' => '30–49%', 'points' => 6,  'matched' => $score === 6],
                ['range' => '50–69%', 'points' => 9,  'matched' => $score === 9],
                ['range' => '70–89%', 'points' => 12, 'matched' => $score === 12],
                ['range' => '≥90%',   'points' => 15, 'matched' => $score === 15],
                ['range' => 'Tidak ada data', 'points' => 0, 'matched' => $total === 0],
            ],
            'explanation_id' => 'review_keyword_quality_v2',
        ];
    }

    /** @return array<string,mixed> */
    private function sentBreakdown(int $score, float $avgRating, int $sampleSize, string $sampleSource = 'places_api_sample'): array
    {
        $noData = $sampleSize === 0;
        return [
            'score'      => $score,
            'cap'        => 10,
            'raw_inputs' => [
                'avg_rating'    => $noData ? null : $avgRating,
                'sample_size'   => $sampleSize,
                'source'        => $sampleSource === 'gmaps_scrape'
                    ? 'Google Maps full-corpus scrape (Phase 8 W8)'
                    : 'Google Maps Places API',
                'sample_source' => $sampleSource,
            ],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '≥4.8',           'points' => 10, 'matched' => $avgRating >= 4.8 && ! $noData],
                ['range' => '4.5–4.7',        'points' => 8,  'matched' => $avgRating >= 4.5 && $avgRating < 4.8],
                ['range' => '4.0–4.4',        'points' => 5,  'matched' => $avgRating >= 4.0 && $avgRating < 4.5],
                ['range' => '3.5–3.9',        'points' => 3,  'matched' => $avgRating >= 3.5 && $avgRating < 4.0],
                ['range' => '<3.5',           'points' => 0,  'matched' => ! $noData && $avgRating < 3.5],
                ['range' => 'Tidak ada data', 'points' => 0,  'matched' => $noData],
            ],
            'explanation_id' => 'sentiment_quality_v2',
        ];
    }
}
