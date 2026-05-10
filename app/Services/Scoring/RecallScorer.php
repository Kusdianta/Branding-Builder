<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Brand Recall pillar.
 * No LLM call — pure arithmetic against the canonical deck rubric.
 * ClaudeService wraps this and optionally appends an evidence narrative.
 */
final class RecallScorer
{
    private const POSITIVE_CLUSTERS = [
        'harum_bersih' => ['harum', 'bersih'],
        'tepat_cepat'  => ['tepat', 'cepat', 'tepat_waktu'],  // fetcher may emit compound key
        'ramah_sopan'  => ['ramah', 'sopan'],
        'rekomen_puas' => ['rekomen', 'puas'],
    ];

    /**
     * @param array{
     *     rating: float,
     *     review_count: int,
     *     owner_response_rate: float,
     *     keyword_hits: list<string>,
     *     sop_keluhan_visible?: bool,
     * } $inputs
     */
    public function score(array $inputs): PillarScore
    {
        $ratingScore     = $this->calcRatingScore((float) $inputs['rating']);
        $countScore      = $this->calcCountScore((int) $inputs['review_count']);
        $keywordScore    = $this->calcKeywordScore((array) ($inputs['keyword_hits'] ?? []));
        $managementScore = $this->calcManagementScore(
            (float) ($inputs['owner_response_rate'] ?? 0.0),
            (bool) ($inputs['sop_keluhan_visible'] ?? false),
        );

        $subBuckets = [
            'rating'            => $ratingScore,
            'review_count'      => $countScore,
            'keyword_quality'   => $keywordScore,
            'review_management' => $managementScore,
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

    /** +5 per positive cluster that has at least one matching keyword. */
    private function calcKeywordScore(array $hits): int
    {
        $score = 0;
        foreach (self::POSITIVE_CLUSTERS as $keywords) {
            foreach ($keywords as $kw) {
                if (in_array($kw, $hits, true)) {
                    $score += 5;
                    break;
                }
            }
        }

        return min(20, $score);
    }

    /**
     * Bases: balas semua (>=0.8) = 20, kadang (>0) = 10, never = 0.
     * +5 SOP bonus can push above base but overall pillar still caps at 100.
     */
    private function calcManagementScore(float $responseRate, bool $sopVisible): int
    {
        $base = match (true) {
            $responseRate >= 0.8 => 20,
            $responseRate > 0.0  => 10,
            default              => 0,
        };

        return $base + ($sopVisible ? 5 : 0);
    }
}
