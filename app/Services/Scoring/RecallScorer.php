<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Brand Recall pillar's review-based signals.
 *
 * Two scoring paths, selected by ``$inputs['_wizard_version']``:
 *
 *   Legacy (v1/v2, default): 65-pt internal cap with sub-buckets
 *     rating_tier (25) + review_count_tier (15) + review_keyword_quality (15)
 *     + sentiment_quality (10). ClaudeService::scoreRecall layers
 *     search_recall (35) on top so the full pillar caps at 100.
 *
 *   V3 (Phase 12c.2 rubric alignment, BB117): 100-pt internal cap
 *     matching the PPT rubric exactly — rating_tier (35) +
 *     review_count_tier (25) + kualitas_ulasan_positif (20, merges
 *     keyword saturation + sentiment) + manajemen_ulasan (20, fed by
 *     OwnerReplyRateScorer). search_recall is RETIRED from the numeric
 *     score in v3; ClaudeService::scoreRecall skips the layering and
 *     surfaces the autocomplete signal as informational only.
 *
 * Phase 8 BB25: the scorer accepts an optional ``full_reviews`` input
 * (the GMaps Phase 8 W8 scrape, up to 30 reviews vs Places API's 5-cap).
 * When present and non-empty, full_reviews replaces sampled_reviews as
 * the corpus driving keyword saturation and sentiment quality.
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
     *     _wizard_version?: string,
     *     owner_reply_rate?: float,
     *     has_sop_declared?: bool,
     *     manajemen_ulasan_evidence?: array<string,mixed>,
     * } $inputs
     */
    public function score(array $inputs): PillarScore
    {
        $version = (string) ($inputs['_wizard_version'] ?? BrandAudit::WIZARD_V1);

        return $version === BrandAudit::WIZARD_V3
            ? $this->scoreV3($inputs)
            : $this->scoreLegacy($inputs);
    }

    /**
     * Legacy v1/v2 path — unchanged from pre-BB117 behaviour. Caps at 65;
     * ClaudeService::scoreRecall layers search_recall (35) on top.
     *
     * @param array<string,mixed> $inputs
     */
    private function scoreLegacy(array $inputs): PillarScore
    {
        $clusters       = (array) config('branding.recall_keyword_clusters', []);
        $sampledReviews = (array) ($inputs['sampled_reviews'] ?? []);
        $fullReviews    = (array) ($inputs['full_reviews'] ?? []);
        $rating         = (float) ($inputs['rating'] ?? 0.0);
        $count          = (int) ($inputs['review_count'] ?? 0);

        [$reviewCorpus, $sampleSource] = $this->resolveCorpus($sampledReviews, $fullReviews);

        $ratingScore                  = $this->calcRatingScore($rating, /*v3*/ false);
        $countScore                   = $this->calcCountScore($count, /*v3*/ false);
        [$kwScore, $kwHits, $kwTotal] = $this->keywordSaturation($reviewCorpus, $clusters, /*v3Cap*/ false);
        [$sentScore, $sentAvg]        = $this->sentimentQuality($reviewCorpus);

        // BB18: keyword_saturation → review_keyword_quality. The metric
        // is the share of sampled reviews that include ≥1 positive
        // keyword (harum, bersih, etc.).
        $subBuckets = [
            'rating_tier'            => $ratingScore,
            'review_count_tier'      => $countScore,
            'review_keyword_quality' => $kwScore,
            'sentiment_quality'      => $sentScore,
        ];

        // Cap at 65 — search_recall (35 more pts) layered on by
        // ClaudeService::scoreRecall so the legacy pillar caps at 100.
        $total = max(0, min(65, array_sum($subBuckets)));

        $breakdown = [
            'rating_tier'            => $this->ratingBreakdown($rating, $ratingScore, /*v3*/ false),
            'review_count_tier'      => $this->countBreakdown($count, $countScore, /*v3*/ false),
            'review_keyword_quality' => $this->kwBreakdown($kwScore, $kwHits, $kwTotal, $sampleSource, /*v3Cap*/ false),
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

    /**
     * V3 (BB117) path — full PPT rubric. Caps at 100 internally.
     *
     * @param array<string,mixed> $inputs
     */
    private function scoreV3(array $inputs): PillarScore
    {
        $clusters       = (array) config('branding.recall_keyword_clusters', []);
        $sampledReviews = (array) ($inputs['sampled_reviews'] ?? []);
        $fullReviews    = (array) ($inputs['full_reviews'] ?? []);
        $rating         = (float) ($inputs['rating'] ?? 0.0);
        $count          = (int) ($inputs['review_count'] ?? 0);
        $replyRate      = (float) ($inputs['owner_reply_rate'] ?? 0.0);
        $hasSopDeclared = (bool) ($inputs['has_sop_declared'] ?? false);
        $manajemenEvi   = (array) ($inputs['manajemen_ulasan_evidence'] ?? []);

        [$reviewCorpus, $sampleSource] = $this->resolveCorpus($sampledReviews, $fullReviews);

        $ratingScore           = $this->calcRatingScore($rating, /*v3*/ true);
        $countScore            = $this->calcCountScore($count, /*v3*/ true);
        [$kualitasScore, $kualitasBreakdown] = $this->kualitasUlasanPositif($reviewCorpus, $clusters, $sampleSource);
        [$manajemenScore, $manajemenBreakdown] = $this->manajemenUlasan($replyRate, $hasSopDeclared, $manajemenEvi);

        $subBuckets = [
            'rating_tier'             => $ratingScore,
            'review_count_tier'       => $countScore,
            'kualitas_ulasan_positif' => $kualitasScore,
            'manajemen_ulasan'        => $manajemenScore,
        ];

        // V3 caps at 100 internally — PPT rubric is exact.
        $total = max(0, min(100, array_sum($subBuckets)));

        $breakdown = [
            'rating_tier'             => $this->ratingBreakdown($rating, $ratingScore, /*v3*/ true),
            'review_count_tier'       => $this->countBreakdown($count, $countScore, /*v3*/ true),
            'kualitas_ulasan_positif' => $kualitasBreakdown,
            'manajemen_ulasan'        => $manajemenBreakdown,
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

    /**
     * @param  list<array<string,mixed>> $sampledReviews
     * @param  list<array<string,mixed>> $fullReviews
     * @return array{0: list<array<string,mixed>>, 1: string}  [corpus, sampleSource]
     */
    private function resolveCorpus(array $sampledReviews, array $fullReviews): array
    {
        // BB25 precedence: full_reviews (W8 scrape, ~30 rows) preferred
        // over sampled_reviews (Places API, capped at 5) when present.
        if ($fullReviews !== []) {
            return [$fullReviews, 'gmaps_scrape'];
        }
        return [$sampledReviews, $sampledReviews === [] ? 'none' : 'places_api_sample'];
    }

    private function calcRatingScore(float $rating, bool $v3): int
    {
        // PPT-correct caps: v3 raises the top tier to 35; legacy stays at 25.
        if ($v3) {
            return match (true) {
                $rating >= 4.8 => 35,
                $rating >= 4.5 => 28,
                $rating >= 4.0 => 20,
                $rating >= 3.5 => 12,
                $rating >= 3.0 => 6,
                default        => 0,
            };
        }
        return match (true) {
            $rating >= 4.8 => 25,
            $rating >= 4.5 => 20,
            $rating >= 4.0 => 14,
            $rating >= 3.5 => 8,
            $rating >= 3.0 => 4,
            default        => 0,
        };
    }

    private function calcCountScore(int $count, bool $v3): int
    {
        // PPT-correct caps: v3 raises the top tier to 25; legacy stays at 15.
        if ($v3) {
            return match (true) {
                $count >= 500 => 25,
                $count >= 200 => 20,
                $count >= 100 => 15,
                $count >= 50  => 9,
                $count >= 11  => 5,
                $count >= 1   => 2,
                default       => 0,
            };
        }
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
    private function keywordSaturation(array $reviews, array $clusters, bool $v3Cap): array
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

        $pct = ($hitsCount / $total) * 100;

        // Legacy cap 15 with 5-tier ramp; v3 cap 15 too (kept here for
        // pre-merge balance — merged with sentiment to 20 inside
        // kualitasUlasanPositif).
        $cap = 15;
        $score = match (true) {
            $pct >= 90 => $cap,
            $pct >= 70 => (int) round($cap * 0.8),
            $pct >= 50 => (int) round($cap * 0.6),
            $pct >= 30 => (int) round($cap * 0.4),
            $pct >= 10 => (int) round($cap * 0.2),
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

    /**
     * V3 merged kualitas_ulasan_positif. Cap 20. Combines keyword
     * saturation (cap 12) + sentiment quality (cap 8) so the merged
     * score preserves both signals without exceeding the PPT cap.
     *
     * @param  list<array<string,mixed>> $reviews
     * @param  array<string,mixed>       $clusters
     * @return array{0:int,1:array<string,mixed>}
     */
    private function kualitasUlasanPositif(array $reviews, array $clusters, string $sampleSource): array
    {
        $total = count($reviews);
        if ($total === 0) {
            return [0, [
                'score'      => 0,
                'cap'        => 20,
                'tier'       => 'tidak ada data',
                'raw_inputs' => [
                    'sampled_reviews' => 0,
                    'keyword_hits'    => 0,
                    'hit_rate_pct'    => 0.0,
                    'avg_rating'      => null,
                    'sample_source'   => $sampleSource,
                ],
                'formula'        => 'deterministic_threshold',
                'explanation_id' => 'kualitas_ulasan_positif_v3',
            ]];
        }

        // Keyword sub-signal (cap 12): hit rate share.
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
        $pct = ($hitsCount / $total) * 100;
        $kwSubScore = match (true) {
            $pct >= 90 => 12,
            $pct >= 70 => 10,
            $pct >= 50 => 7,
            $pct >= 30 => 4,
            $pct >= 10 => 2,
            default    => 0,
        };

        // Sentiment sub-signal (cap 8): avg star rating.
        $avg = array_sum(array_column($reviews, 'rating')) / $total;
        $sentSubScore = match (true) {
            $avg >= 4.8 => 8,
            $avg >= 4.5 => 6,
            $avg >= 4.0 => 4,
            $avg >= 3.5 => 2,
            default     => 0,
        };

        $score = min(20, $kwSubScore + $sentSubScore);

        return [$score, [
            'score'      => $score,
            'cap'        => 20,
            'tier'       => $this->kualitasTier($score),
            'raw_inputs' => [
                'sampled_reviews' => $total,
                'keyword_hits'    => $hitsCount,
                'hit_rate_pct'    => round($pct, 1),
                'avg_rating'      => round($avg, 2),
                'sample_source'   => $sampleSource,
            ],
            'formula'      => 'deterministic_threshold',
            'sub_signals'  => [
                'keyword_saturation' => ['score' => $kwSubScore, 'cap' => 12],
                'sentiment_average'  => ['score' => $sentSubScore, 'cap' => 8],
            ],
            'explanation_id' => 'kualitas_ulasan_positif_v3',
        ]];
    }

    private function kualitasTier(int $score): string
    {
        return match (true) {
            $score >= 18 => 'sempurna',
            $score >= 14 => 'sangat baik',
            $score >= 10 => 'baik',
            $score >= 6  => 'cukup',
            $score >= 1  => 'kurang',
            default      => 'tidak ada data',
        };
    }

    /**
     * V3 manajemen_ulasan. Cap 20. Reads OwnerReplyRateScorer evidence
     * already attached to inputs by ScorePillarsJob; falls back to the
     * raw reply_rate + sop_declared bools when evidence is absent.
     *
     * @param array<string,mixed> $evidence
     * @return array{0:int,1:array<string,mixed>}
     */
    private function manajemenUlasan(float $replyRate, bool $hasSopDeclared, array $evidence): array
    {
        $base = match (true) {
            $replyRate >= 0.95 => 20,
            $replyRate >= 0.50 => 10,
            default            => 0,
        };
        $bonus = ($hasSopDeclared && $replyRate >= 0.50) ? 5 : 0;
        $score = (int) min(20, $base + $bonus);

        $tier = match (true) {
            $score >= 20 => 'sempurna',
            $score >= 10 => 'cukup',
            default      => 'kurang',
        };

        $total = isset($evidence['total_reviews']) ? (int) $evidence['total_reviews'] : null;
        $replied = isset($evidence['replied_reviews']) ? (int) $evidence['replied_reviews'] : null;

        return [$score, [
            'score'      => $score,
            'cap'        => 20,
            'tier'       => $tier,
            'raw_inputs' => [
                'reply_rate_pct'    => round($replyRate * 100, 1),
                'total_reviews'     => $total,
                'replied_reviews'   => $replied,
                'sop_declared_bonus'=> $bonus > 0,
                'sample_source'     => 'gmaps_owner_reply_scrape',
            ],
            'formula'           => 'deterministic_threshold',
            'matched_replies'   => $evidence['matched_replies'] ?? [],
            'tier_table' => [
                ['range' => '≥95% balas',          'points' => 20, 'matched' => $replyRate >= 0.95],
                ['range' => '50-94% balas',        'points' => 10, 'matched' => $replyRate >= 0.50 && $replyRate < 0.95],
                ['range' => '<50% balas',          'points' => 0,  'matched' => $replyRate < 0.50],
                ['range' => '+5 bonus SOP+balas',  'points' => 5,  'matched' => $bonus > 0],
            ],
            'explanation_id' => 'manajemen_ulasan_v3',
        ]];
    }

    /** @return array<string,mixed> */
    private function ratingBreakdown(float $rating, int $score, bool $v3): array
    {
        $noData = $rating <= 0.0;
        $cap = $v3 ? 35 : 25;
        $tierTable = $v3 ? [
            ['range' => '≥4.8',           'points' => 35, 'matched' => $rating >= 4.8],
            ['range' => '4.5–4.7',        'points' => 28, 'matched' => $rating >= 4.5 && $rating < 4.8],
            ['range' => '4.0–4.4',        'points' => 20, 'matched' => $rating >= 4.0 && $rating < 4.5],
            ['range' => '3.5–3.9',        'points' => 12, 'matched' => $rating >= 3.5 && $rating < 4.0],
            ['range' => '3.0–3.4',        'points' => 6,  'matched' => $rating >= 3.0 && $rating < 3.5],
            ['range' => '<3.0',           'points' => 0,  'matched' => ! $noData && $rating < 3.0],
            ['range' => 'Tidak tersedia', 'points' => 0,  'matched' => $noData],
        ] : [
            ['range' => '≥4.8',           'points' => 25, 'matched' => $rating >= 4.8],
            ['range' => '4.5–4.7',        'points' => 20, 'matched' => $rating >= 4.5 && $rating < 4.8],
            ['range' => '4.0–4.4',        'points' => 14, 'matched' => $rating >= 4.0 && $rating < 4.5],
            ['range' => '3.5–3.9',        'points' => 8,  'matched' => $rating >= 3.5 && $rating < 4.0],
            ['range' => '3.0–3.4',        'points' => 4,  'matched' => $rating >= 3.0 && $rating < 3.5],
            ['range' => '<3.0',           'points' => 0,  'matched' => ! $noData && $rating < 3.0],
            ['range' => 'Tidak tersedia', 'points' => 0,  'matched' => $noData],
        ];

        return [
            'score'      => $score,
            'cap'        => $cap,
            'raw_inputs' => ['rating' => $noData ? null : $rating, 'source' => 'Google Maps Places API'],
            'formula'    => 'deterministic_threshold',
            'tier_table' => $tierTable,
            'explanation_id' => $v3 ? 'rating_tier_v3' : 'rating_tier_v2',
        ];
    }

    /** @return array<string,mixed> */
    private function countBreakdown(int $count, int $score, bool $v3): array
    {
        $cap = $v3 ? 25 : 15;
        $tierTable = $v3 ? [
            ['range' => '≥500',    'points' => 25, 'matched' => $count >= 500],
            ['range' => '200–499', 'points' => 20, 'matched' => $count >= 200 && $count < 500],
            ['range' => '100–199', 'points' => 15, 'matched' => $count >= 100 && $count < 200],
            ['range' => '50–99',   'points' => 9,  'matched' => $count >= 50  && $count < 100],
            ['range' => '11–49',   'points' => 5,  'matched' => $count >= 11  && $count < 50],
            ['range' => '1–10',    'points' => 2,  'matched' => $count >= 1   && $count < 11],
            ['range' => '0',       'points' => 0,  'matched' => $count === 0],
        ] : [
            ['range' => '≥500',    'points' => 15, 'matched' => $count >= 500],
            ['range' => '200–499', 'points' => 12, 'matched' => $count >= 200 && $count < 500],
            ['range' => '100–199', 'points' => 9,  'matched' => $count >= 100 && $count < 200],
            ['range' => '50–99',   'points' => 5,  'matched' => $count >= 50  && $count < 100],
            ['range' => '11–49',   'points' => 3,  'matched' => $count >= 11  && $count < 50],
            ['range' => '1–10',    'points' => 1,  'matched' => $count >= 1   && $count < 11],
            ['range' => '0',       'points' => 0,  'matched' => $count === 0],
        ];

        return [
            'score'      => $score,
            'cap'        => $cap,
            'raw_inputs' => ['review_count' => $count, 'source' => 'Google Maps Places API'],
            'formula'    => 'deterministic_threshold',
            'tier_table' => $tierTable,
            'explanation_id' => $v3 ? 'review_count_tier_v3' : 'review_count_tier_v2',
        ];
    }

    /** @return array<string,mixed> */
    private function kwBreakdown(int $score, int $hitsCount, int $total, string $sampleSource, bool $v3Cap): array
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
    private function sentBreakdown(int $score, float $avgRating, int $sampleSize, string $sampleSource): array
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
