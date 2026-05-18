<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;

/**
 * Deterministic scorer for the Digital Presence pillar.
 * Presence-based only — no LLM call for the score itself.
 *
 * Two paths selected by ``$inputs['_wizard_version']``:
 *
 *   Legacy (v1/v2): boolean presence per touchpoint. has_instagram pass/
 *     fail (20), has_website pass/fail (20), TikTok demoted 10 → 3 per
 *     BB101, review_bonus single bucket (15 max).
 *
 *   V3 (BB117): graded signals replace booleans. has_instagram passes
 *     through InstagramActivityScorer (0–20 graded), has_website passes
 *     through WebsiteLivenessScorer (20 or 0 deterministic), TikTok
 *     re-promoted to 10 to match PPT, review_bonus split into two
 *     5-point sub-buckets per PPT (≥10 and ≥50).
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
     *     _wizard_version?: string,
     *     instagram_activity_score?: int|null,
     *     instagram_activity_evidence?: array<string,mixed>,
     *     instagram_activity_source?: string,
     *     website_is_live?: bool,
     *     website_evidence?: array<string,mixed>,
     *     tiktok_check_status?: string,
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
     * Legacy v1/v2 path — unchanged from pre-BB117 behaviour. TikTok
     * stays demoted to 3 (BB101).
     *
     * @param array<string,mixed> $inputs
     */
    private function scoreLegacy(array $inputs): PillarScore
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
        // BB101: tiktok demoted 10 → 3. Absence contributes zero.
        $tiktok    = $hasTiktok   ? 3 : 0;

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
            'has_tiktok'    => $this->presenceEntry('has_tiktok',    $hasTiktok,  3,  'TikTok (bonus)'),
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

    /**
     * V3 (BB117) path — PPT rubric exact.
     *   has_gmaps 25 + has_instagram 20 (graded) + has_website 20 (live
     *   check) + has_wa 15 + has_tiktok 10 + review_count_5plus 5 +
     *   review_count_50plus 5 = 100.
     *
     * @param array<string,mixed> $inputs
     */
    private function scoreV3(array $inputs): PillarScore
    {
        $hasGmaps    = (bool) ($inputs['has_gmaps'] ?? false);
        $hasWa       = (bool) ($inputs['has_wa_business'] ?? false);
        $reviewCount = (int) ($inputs['review_count'] ?? 0);

        $igActivityScore   = $inputs['instagram_activity_score'] ?? null;
        $igActivityEvidence= (array) ($inputs['instagram_activity_evidence'] ?? []);
        $igActivitySource  = (string) ($inputs['instagram_activity_source'] ?? 'Sumber: aktivitas feed + story Instagram');
        $igActivityUnavail = $inputs['instagram_activity_unavailable_reason'] ?? null;

        $websiteIsLive    = (bool) ($inputs['website_is_live'] ?? false);
        $websiteEvidence  = (array) ($inputs['website_evidence'] ?? []);
        $websiteUnavail   = $inputs['website_unavailable_reason'] ?? null;

        $tiktokStatus = (string) ($inputs['tiktok_check_status'] ?? 'not_checked');

        $gmaps     = $hasGmaps ? 25 : 0;
        $instagram = $igActivityScore === null ? 0 : (int) min(20, max(0, (int) $igActivityScore));
        $website   = $websiteIsLive ? 20 : 0;
        $wa        = $hasWa ? 15 : 0;
        // PPT: TikTok promoted back to 10 in v3 — operator-locked.
        $tiktok    = $tiktokStatus === 'found' ? 10 : 0;

        $review10 = $reviewCount >= 10 ? 5 : 0;
        $review50 = $reviewCount >= 50 ? 5 : 0;

        $subBuckets = [
            'has_gmaps'           => $gmaps,
            'has_instagram'       => $instagram,
            'has_website'         => $website,
            'has_wa'              => $wa,
            'has_tiktok'          => $tiktok,
            'review_count_5plus'  => $review10,
            'review_count_50plus' => $review50,
        ];

        $total = max(0, min(100, array_sum($subBuckets)));

        $breakdown = [
            'has_gmaps'     => $this->presenceEntry('has_gmaps', $hasGmaps, 25, 'Google Maps'),
            'has_instagram' => [
                'score'      => $instagram,
                'cap'        => 20,
                'raw_inputs' => [
                    'activity_score'    => $igActivityScore,
                    'last_post_days'    => $igActivityEvidence['last_post_days_ago'] ?? null,
                    'has_active_story'  => $igActivityEvidence['has_active_story']   ?? null,
                    'posts_per_week'    => $igActivityEvidence['posts_per_week_avg'] ?? null,
                    'cadence_variance'  => $igActivityEvidence['cadence_variance']   ?? null,
                    'source'            => $igActivitySource,
                ],
                'formula'             => 'graded_activity',
                'unavailable_reason'  => is_string($igActivityUnavail) ? $igActivityUnavail : null,
                'explanation_id'      => 'instagram_activity_v3',
            ],
            'has_website'   => [
                'score'      => $website,
                'cap'        => 20,
                'raw_inputs' => [
                    'is_live'     => $websiteIsLive,
                    'http_status' => $websiteEvidence['http_status'] ?? null,
                    'response_ms' => $websiteEvidence['response_time_ms'] ?? null,
                    'source'      => 'Sumber: cek HTTP langsung ke website',
                ],
                'formula'             => 'http_liveness',
                'unavailable_reason'  => is_string($websiteUnavail) ? $websiteUnavail : null,
                'explanation_id'      => 'website_liveness_v3',
            ],
            'has_wa'        => $this->presenceEntry('has_wa', $hasWa, 15, 'WhatsApp Business'),
            'has_tiktok'    => [
                'score'      => $tiktok,
                'cap'        => 10,
                'raw_inputs' => [
                    'tiktok_check_status' => $tiktokStatus,
                    'source'              => 'Sumber: cek ketersediaan handle TikTok via JSON endpoint',
                ],
                'formula'        => 'deterministic_threshold',
                'tier_table'     => [
                    ['range' => 'Handle ditemukan', 'points' => 10, 'matched' => $tiktokStatus === 'found'],
                    ['range' => 'Tidak ditemukan',  'points' => 0,  'matched' => $tiktokStatus !== 'found'],
                ],
                'explanation_id' => 'tiktok_check_v3',
            ],
            'review_count_5plus' => [
                'score'      => $review10,
                'cap'        => 5,
                'raw_inputs' => ['review_count' => $reviewCount, 'source' => 'Google Maps Places API'],
                'formula'    => 'deterministic_threshold',
                'tier_table' => [
                    ['range' => '≥10', 'points' => 5, 'matched' => $reviewCount >= 10],
                    ['range' => '<10', 'points' => 0, 'matched' => $reviewCount < 10],
                ],
                'explanation_id' => 'review_count_5plus_v3',
            ],
            'review_count_50plus' => [
                'score'      => $review50,
                'cap'        => 5,
                'raw_inputs' => ['review_count' => $reviewCount, 'source' => 'Google Maps Places API'],
                'formula'    => 'deterministic_threshold',
                'tier_table' => [
                    ['range' => '≥50', 'points' => 5, 'matched' => $reviewCount >= 50],
                    ['range' => '<50', 'points' => 0, 'matched' => $reviewCount < 50],
                ],
                'explanation_id' => 'review_count_50plus_v3',
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
