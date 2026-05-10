<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Digital Presence pillar.
 * Presence-based only — no LLM call for the score itself.
 * ClaudeService wraps this and optionally appends an evidence narrative.
 */
final class DigitalPresenceScorer
{
    /**
     * @param array{
     *     has_instagram: bool,
     *     has_website: bool,
     *     has_gmaps: bool,
     *     has_wa_business: bool,
     *     has_tiktok: bool,
     *     review_count: int,
     * } $inputs
     */
    public function score(array $inputs): PillarScore
    {
        $gmaps     = ((bool) ($inputs['has_gmaps'] ?? false))       ? 25 : 0;
        $instagram = ((bool) ($inputs['has_instagram'] ?? false))   ? 20 : 0;
        $website   = ((bool) ($inputs['has_website'] ?? false))     ? 20 : 0;
        $wa        = ((bool) ($inputs['has_wa_business'] ?? false)) ? 15 : 0;
        $tiktok    = ((bool) ($inputs['has_tiktok'] ?? false))      ? 10 : 0;

        $reviewCount = (int) ($inputs['review_count'] ?? 0);
        $reviewBonus = match (true) {
            $reviewCount >= 50 => 15,
            $reviewCount >= 10 => 5,
            default            => 0,
        };

        $subBuckets = [
            'has_gmaps'     => $gmaps,
            'has_instagram' => $instagram,
            'has_website'   => $website,
            'has_wa'        => $wa,
            'has_tiktok'    => $tiktok,
            'review_bonus'  => $reviewBonus,
        ];

        $total = max(0, min(100, array_sum($subBuckets)));

        return new PillarScore(
            pillarSlug:      ScoringRubric::PILLAR_DIGITAL,
            score:           $total,
            evidence:        [],
            reasoning:       '',
            subBucketScores: $subBuckets,
        );
    }
}
