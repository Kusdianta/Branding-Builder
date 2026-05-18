<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\OwnerReplyRateScorer;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12c.2-rubric-alignment BB115 — OwnerReplyRateScorer tier tests.
 */
class OwnerReplyRateScorerTest extends TestCase
{
    public function test_empty_review_array_is_unavailable_not_zero(): void
    {
        $result = (new OwnerReplyRateScorer())->score([], false);
        $this->assertNull($result['score']);
        $this->assertNull($result['tier']);
        $this->assertNotNull($result['unavailable_reason']);
    }

    public function test_full_reply_rate_yields_sempurna(): void
    {
        $reviews = [];
        for ($i = 0; $i < 10; $i++) {
            $reviews[] = [
                'author'       => 'Reviewer ' . $i,
                'rating_value' => 5,
                'text'         => 'Bersih, harum.',
                'owner_reply'  => ['has_reply' => true, 'reply_text' => 'Terima kasih!'],
            ];
        }

        $result = (new OwnerReplyRateScorer())->score($reviews, false);
        $this->assertSame(20, $result['score']);
        $this->assertSame('sempurna', $result['tier']);
    }

    public function test_half_reply_rate_yields_cukup(): void
    {
        $reviews = [];
        for ($i = 0; $i < 10; $i++) {
            $reviews[] = [
                'author'       => 'Reviewer ' . $i,
                'rating_value' => 4,
                'text'         => 'Oke.',
                'owner_reply'  => $i < 5
                    ? ['has_reply' => true, 'reply_text' => 'Trims.']
                    : null,
            ];
        }

        $result = (new OwnerReplyRateScorer())->score($reviews, false);
        $this->assertSame(10, $result['score']);
        $this->assertSame('cukup', $result['tier']);
    }

    public function test_sop_declared_plus_50_pct_reply_adds_bonus(): void
    {
        $reviews = array_fill(0, 10, ['author' => 'X', 'rating_value' => 5, 'text' => 'OK', 'owner_reply' => ['has_reply' => true, 'reply_text' => 'TY']]);
        // Wipe half the replies — keep rate at 50%
        for ($i = 5; $i < 10; $i++) {
            $reviews[$i]['owner_reply'] = null;
        }

        $resultBase  = (new OwnerReplyRateScorer())->score($reviews, false);
        $resultSop   = (new OwnerReplyRateScorer())->score($reviews, true);

        $this->assertSame(10, $resultBase['score']);
        $this->assertSame(15, $resultSop['score']);
        $this->assertTrue($resultSop['evidence']['sop_declared_bonus']);
    }

    public function test_zero_replies_yield_kurang_with_source(): void
    {
        $reviews = array_fill(0, 5, ['author' => 'X', 'rating_value' => 4, 'text' => 'OK', 'owner_reply' => null]);
        $result = (new OwnerReplyRateScorer())->score($reviews, false);

        $this->assertSame(0, $result['score']);
        $this->assertSame('kurang', $result['tier']);
        $this->assertStringStartsWith('Sumber:', $result['source']);
    }

    public function test_matched_replies_are_captured_for_review_quote_embed(): void
    {
        $reviews = [
            ['author' => 'Budi', 'rating_value' => 5, 'text' => 'Bagus', 'owner_reply' => ['has_reply' => true, 'reply_text' => 'Makasih Budi!']],
        ];

        $result = (new OwnerReplyRateScorer())->score($reviews, false);
        $this->assertNotEmpty($result['evidence']['matched_replies']);
        $this->assertSame('Budi', $result['evidence']['matched_replies'][0]['reviewer_name']);
        $this->assertStringContainsString('Makasih', $result['evidence']['matched_replies'][0]['reply_text']);
    }
}
