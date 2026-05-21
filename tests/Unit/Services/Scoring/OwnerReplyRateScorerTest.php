<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Services\Scoring\OwnerReplyRateScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB115/BB130 — OwnerReplyRateScorer. Reads the ``owner_reply`` field
 * on each scraped GMaps review and computes the reply rate + tier.
 * Thresholds (locked by BB130 constraint): >=95% -> 20, >=50% -> 10,
 * else 0; +5 SOP bonus when SOP declared AND reply rate >= 50%.
 */
class OwnerReplyRateScorerTest extends TestCase
{
    private OwnerReplyRateScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new OwnerReplyRateScorer();
    }

    /** @return list<array<string,mixed>> */
    private function reviews(int $total, int $replied): array
    {
        $out = [];
        for ($i = 0; $i < $total; $i++) {
            $out[] = [
                'author'       => "User {$i}",
                'rating_value' => 5,
                'text'         => "Review {$i}",
                'owner_reply'  => $i < $replied
                    ? ['has_reply' => true, 'reply_text' => "Thanks {$i}", 'reply_date_relative' => '1 month ago']
                    : null,
            ];
        }
        return $out;
    }

    #[Test]
    public function zero_percent_scores_zero_kurang(): void
    {
        $r = $this->scorer->score($this->reviews(4, 0), hasSopDeclared: false);
        $this->assertSame(0, $r['score']);
        $this->assertSame('kurang', $r['tier']);
        $this->assertSame(0.0, $r['evidence']['reply_rate_pct']);
        $this->assertSame(4, $r['evidence']['total_reviews']);
        $this->assertSame(0, $r['evidence']['replied_reviews']);
    }

    #[Test]
    public function fifty_percent_scores_ten_cukup(): void
    {
        $r = $this->scorer->score($this->reviews(4, 2), hasSopDeclared: false);
        $this->assertSame(10, $r['score']);
        $this->assertSame('cukup', $r['tier']);
        $this->assertSame(50.0, $r['evidence']['reply_rate_pct']);
        $this->assertFalse($r['evidence']['sop_declared_bonus']);
    }

    #[Test]
    public function fifty_percent_with_sop_adds_bonus(): void
    {
        $r = $this->scorer->score($this->reviews(4, 2), hasSopDeclared: true);
        $this->assertSame(15, $r['score']);
        $this->assertTrue($r['evidence']['sop_declared_bonus']);
    }

    #[Test]
    public function ninety_five_percent_scores_twenty_sempurna(): void
    {
        $r = $this->scorer->score($this->reviews(20, 19), hasSopDeclared: false);
        $this->assertSame(20, $r['score']);
        $this->assertSame('sempurna', $r['tier']);
        $this->assertSame(95.0, $r['evidence']['reply_rate_pct']);
    }

    #[Test]
    public function hundred_percent_caps_at_twenty_even_with_sop(): void
    {
        $r = $this->scorer->score($this->reviews(5, 5), hasSopDeclared: true);
        $this->assertSame(20, $r['score']);
        $this->assertSame('sempurna', $r['tier']);
        $this->assertSame(100.0, $r['evidence']['reply_rate_pct']);
    }

    #[Test]
    public function below_fifty_percent_gets_no_sop_bonus(): void
    {
        // 40% reply rate: base 0, and the bonus is gated on >=50% so it
        // stays 0 even though SOP is declared.
        $r = $this->scorer->score($this->reviews(10, 4), hasSopDeclared: true);
        $this->assertSame(0, $r['score']);
        $this->assertFalse($r['evidence']['sop_declared_bonus']);
    }

    #[Test]
    public function empty_corpus_returns_null_with_unavailable_reason(): void
    {
        $r = $this->scorer->score([], hasSopDeclared: false);
        $this->assertNull($r['score']);
        $this->assertNull($r['tier']);
        $this->assertNotNull($r['unavailable_reason']);
    }

    #[Test]
    public function ignores_owner_reply_with_has_reply_false(): void
    {
        $reviews = [
            ['author' => 'A', 'rating_value' => 5, 'text' => 'x', 'owner_reply' => ['has_reply' => false]],
            ['author' => 'B', 'rating_value' => 5, 'text' => 'y', 'owner_reply' => ['has_reply' => true, 'reply_text' => 'hi']],
        ];
        $r = $this->scorer->score($reviews, hasSopDeclared: false);
        $this->assertSame(1, $r['evidence']['replied_reviews']);
        $this->assertSame(50.0, $r['evidence']['reply_rate_pct']);
    }
}
