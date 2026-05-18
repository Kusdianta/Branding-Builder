<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\InstagramActivityScorer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12c.2-rubric-alignment BB116 — InstagramActivityScorer
 * tier-boundary tests. Pure unit (no DB), Carbon::now() frozen so
 * "days since last post" is deterministic.
 */
class InstagramActivityScorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-18 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_posts_returns_null_score_with_unavailable_reason(): void
    {
        $result = (new InstagramActivityScorer())->score([]);

        $this->assertNull($result['score']);
        $this->assertNull($result['tier']);
        $this->assertNotNull($result['unavailable_reason']);
    }

    public function test_recent_consistent_posting_yields_sangat_aktif(): void
    {
        $posts = [];
        for ($i = 0; $i < 16; $i++) {
            // 2 posts per week for 8 weeks → variance 0
            $posts[] = ['posted_at' => Carbon::now()->subDays($i * 3)->toIso8601String()];
        }

        $result = (new InstagramActivityScorer())->score([
            'posts'            => $posts,
            'has_active_story' => true,
        ]);

        $this->assertSame(20, $result['score']);
        $this->assertSame('sangat aktif', $result['tier']);
    }

    public function test_one_stale_post_yields_tidak_aktif(): void
    {
        $result = (new InstagramActivityScorer())->score([
            'posts' => [['posted_at' => Carbon::now()->subDays(90)->toIso8601String()]],
        ]);

        $this->assertSame(0, $result['score']);
        $this->assertSame('tidak aktif', $result['tier']);
    }

    public function test_recent_single_post_no_story_yields_jarang(): void
    {
        $result = (new InstagramActivityScorer())->score([
            'posts'            => [['posted_at' => Carbon::now()->subDays(3)->toIso8601String()]],
            'has_active_story' => false,
        ]);

        // 8 (recent post) + 0 (no story) + 0 (frequency) + 0 (variance, need 4+ weeks)
        $this->assertSame(8, $result['score']);
        $this->assertSame('jarang', $result['tier']);
    }

    public function test_source_attribution_is_always_present(): void
    {
        foreach ([[], ['posts' => []], ['posts' => [['posted_at' => Carbon::now()->toIso8601String()]]]] as $input) {
            $result = (new InstagramActivityScorer())->score($input);
            $this->assertNotEmpty($result['source'], 'source attribution must always populate');
            $this->assertStringStartsWith('Sumber:', $result['source']);
        }
    }
}
