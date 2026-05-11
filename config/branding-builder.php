<?php

declare(strict_types=1);

/*
 * Phase 7-B configuration namespace.
 *
 * Kept separate from config/branding.php (pillar scoring) so the LLM
 * analysis layer has its own clean surface area for future tuning
 * (benchmarks, prompts, schema versions).
 */
return [

    /*
     * Laundry-specific Instagram engagement-rate benchmarks.
     *
     * Sources synthesized from Indonesian SMB social-media benchmarks
     * (Adcolony Indonesia 2023, Influencer Marketing Hub APAC 2024,
     * native-vs-paid breakdowns) and adjusted for the laundry vertical,
     * which trends BELOW lifestyle/fashion (transactional rather than
     * aspirational content). Used in two places:
     *
     *   1. Embedded as a reference table in the analyzeInstagramProfile
     *      prompt so Claude calibrates `estimated_er_range` against the
     *      RIGHT industry, not its broader training prior.
     *   2. Read by InstagramProfileAuditService callers/UI later for
     *      side-by-side display.
     *
     * follower_range is [min, max) — upper bound exclusive so the tiers
     * partition cleanly without gaps or overlaps.
     */
    'ig_benchmarks' => [
        'engagement_rate_by_tier' => [
            'nano' => [
                'follower_range' => [0, 10000],
                'er_range_pct'   => [3.5, 6.0],
            ],
            'micro' => [
                'follower_range' => [10000, 50000],
                'er_range_pct'   => [2.0, 4.0],
            ],
            'mid' => [
                'follower_range' => [50000, 100000],
                'er_range_pct'   => [1.5, 3.0],
            ],
            'macro' => [
                'follower_range' => [100000, PHP_INT_MAX],
                'er_range_pct'   => [1.0, 2.5],
            ],
        ],
        // B2B / entrepreneur-targeted laundry brands typically run 20-30%
        // lower ER than B2C lifestyle laundry. Subtract this from both
        // bounds of the tier range when bio/positioning signals B2B.
        'niche_adjustment_b2b_entrepreneur' => -0.3,

        'tier_display_names' => [
            'nano'  => 'nano-influencer',
            'micro' => 'micro-influencer',
            'mid'   => 'mid-tier influencer',
            'macro' => 'macro-influencer',
        ],
    ],

];
