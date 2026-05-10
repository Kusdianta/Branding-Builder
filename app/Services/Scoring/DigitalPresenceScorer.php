<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Digital Presence pillar.
 * Presence-based only — no LLM call for the score itself.
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
        $hasGmaps    = (bool) ($inputs['has_gmaps'] ?? false);
        $hasIg       = (bool) ($inputs['has_instagram'] ?? false);
        $hasWebsite  = (bool) ($inputs['has_website'] ?? false);
        $hasWa       = (bool) ($inputs['has_wa_business'] ?? false);
        $hasTiktok   = (bool) ($inputs['has_tiktok'] ?? false);
        $reviewCount = (int) ($inputs['review_count'] ?? 0);

        $gmaps     = $hasGmaps    ? 25 : 0;
        $instagram = $hasIg       ? 20 : 0;
        $website   = $hasWebsite  ? 20 : 0;
        $wa        = $hasWa       ? 15 : 0;
        $tiktok    = $hasTiktok   ? 10 : 0;

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

        $breakdown = [
            'has_gmaps'     => $this->presenceEntry('has_gmaps',    $hasGmaps,   25, 'Google Maps'),
            'has_instagram' => $this->presenceEntry('has_instagram', $hasIg,      20, 'Instagram'),
            'has_website'   => $this->presenceEntry('has_website',   $hasWebsite, 20, 'Website'),
            'has_wa'        => $this->presenceEntry('has_wa',        $hasWa,      15, 'WhatsApp Business'),
            'has_tiktok'    => $this->presenceEntry('has_tiktok',    $hasTiktok,  10, 'TikTok'),
            'review_bonus'  => [
                'score'      => $reviewBonus,
                'cap'        => 15,
                'raw_inputs' => ['review_count' => $reviewCount, 'source' => 'Google Maps Places API'],
                'formula'    => 'deterministic_threshold',
                'tier_table' => [
                    ['range' => '≥50',   'points' => 15, 'matched' => $reviewCount >= 50],
                    ['range' => '10–49', 'points' => 5,  'matched' => $reviewCount >= 10 && $reviewCount < 50],
                    ['range' => '<10',   'points' => 0,  'matched' => $reviewCount < 10],
                ],
                'explanation_id' => 'review_bonus_v1',
            ],
        ];

        return new PillarScore(
            pillarSlug:      ScoringRubric::PILLAR_DIGITAL,
            score:           $total,
            evidence:        [],
            reasoning:       '',
            subBucketScores: $subBuckets,
            scoreBreakdown:  $breakdown,
        );
    }

    /** @return array<string,mixed> */
    private function presenceEntry(string $key, bool $present, int $cap, string $touchpointName): array
    {
        return [
            'score'      => $present ? $cap : 0,
            'cap'        => $cap,
            'raw_inputs' => ['present' => $present, 'touchpoint' => $touchpointName],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => 'Hadir',       'points' => $cap, 'matched' => $present],
                ['range' => 'Tidak hadir', 'points' => 0,    'matched' => ! $present],
            ],
            'explanation_id' => $key . '_presence_v1',
        ];
    }
}
