<?php

declare(strict_types=1);

namespace App\Services\Scoring;

/**
 * Phase 12c.2-rubric-alignment BB115 — Brand Recall "Manajemen Ulasan"
 * sub-bucket scorer.
 *
 * Consumes the BB115 worker change: each scraped GMaps review now
 * carries an ``owner_reply`` field (null when absent). Reply rate
 * is the count of replies divided by the count of scraped reviews.
 *
 * Tier table (PPT rubric):
 *   ≥ 95% replied → 20 pts ("sempurna")
 *   ≥ 50% replied → 10 pts ("cukup")
 *   else          →  0 pts ("kurang")
 *
 * Bonus: when the operator declared SOP Keluhan AND the reply rate
 * is ≥ 50%, +5 (cap at 20). This rewards declared-and-verified.
 */
final class OwnerReplyRateScorer
{
    private const MAX_SCORE = 20;

    /**
     * @param array<int,array<string,mixed>> $reviews   $audit->gmaps_reviews['reviews']
     * @param bool                           $hasSopDeclared $touchpoints.operational.complaint_sop
     * @return array{
     *   score: int|null,
     *   tier: string|null,
     *   evidence: array<string,mixed>,
     *   source: string,
     *   unavailable_reason: string|null,
     * }
     */
    public function score(array $reviews, bool $hasSopDeclared): array
    {
        $totalReviews = count($reviews);
        if ($totalReviews === 0) {
            return [
                'score'              => null,
                'tier'               => null,
                'evidence'           => [],
                'source'             => 'Sumber: scrape balasan pemilik di Google Maps',
                'unavailable_reason' => 'Belum ada ulasan Google Maps yang berhasil di-scrape.',
            ];
        }

        $repliedReviews = 0;
        $matchedReplies = [];
        foreach ($reviews as $review) {
            $reply = $review['owner_reply'] ?? null;
            if (! is_array($reply)) {
                continue;
            }
            if (($reply['has_reply'] ?? false) !== true) {
                continue;
            }
            $repliedReviews++;
            if (count($matchedReplies) < 3) {
                $matchedReplies[] = [
                    'reviewer_name'        => (string) ($review['author'] ?? 'Anonim'),
                    'rating'               => (int)    ($review['rating_value'] ?? 0),
                    'reply_text'           => (string) ($reply['reply_text'] ?? ''),
                    'reply_date_relative'  => $reply['reply_date_relative'] ?? null,
                ];
            }
        }

        $replyRate = $repliedReviews / $totalReviews;
        $base = match (true) {
            $replyRate >= 0.95 => 20,
            $replyRate >= 0.50 => 10,
            default            => 0,
        };
        $bonus = ($hasSopDeclared && $replyRate >= 0.50) ? 5 : 0;
        $score = (int) min(self::MAX_SCORE, $base + $bonus);

        $tier = match (true) {
            $score >= 20 => 'sempurna',
            $score >= 10 => 'cukup',
            default      => 'kurang',
        };

        return [
            'score'    => $score,
            'tier'     => $tier,
            'evidence' => [
                'total_reviews'      => $totalReviews,
                'replied_reviews'    => $repliedReviews,
                'reply_rate_pct'     => round($replyRate * 100, 1),
                'sop_declared_bonus' => $bonus > 0,
                'matched_replies'    => $matchedReplies,
            ],
            'source'             => 'Sumber: scrape balasan pemilik di Google Maps reviews',
            'unavailable_reason' => null,
        ];
    }
}
