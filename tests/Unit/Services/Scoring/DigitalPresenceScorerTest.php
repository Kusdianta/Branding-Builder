<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Services\Scoring\DigitalPresenceScorer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB101 — verify TikTok demoted from 10 pts to 3 pts AND that TikTok
 * absence contributes zero score impact (no negative delta).
 * Mirrors the BB104 "absent = silent" stance — the scorer must never
 * subtract points for missing optional signals.
 */
class DigitalPresenceScorerTest extends TestCase
{
    private DigitalPresenceScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new DigitalPresenceScorer();
    }

    #[Test]
    public function tiktok_present_awards_three_points_not_ten(): void
    {
        $without = $this->scorer->score($this->presence(['has_tiktok' => false]));
        $with    = $this->scorer->score($this->presence(['has_tiktok' => true]));

        $delta = $with->subBucketScores['has_tiktok'];
        $this->assertSame(3, $delta, 'TikTok should contribute exactly 3 pts after BB101 demotion');

        // Pillar-level delta is at most 3 (could cap when other signals fill the pillar).
        $this->assertLessThanOrEqual(3, $with->score - $without->score);
    }

    #[Test]
    public function tiktok_absence_is_not_a_penalty(): void
    {
        $without = $this->scorer->score($this->presence(['has_tiktok' => false]));

        $this->assertSame(0, $without->subBucketScores['has_tiktok']);
        $this->assertGreaterThanOrEqual(0, $without->score);
    }

    #[Test]
    public function tiktok_breakdown_is_labelled_as_bonus(): void
    {
        $score = $this->scorer->score($this->presence(['has_tiktok' => true]));

        $breakdown = $score->scoreBreakdown['has_tiktok'] ?? [];
        $this->assertSame(3, $breakdown['cap'] ?? null, 'has_tiktok cap should be 3');
        $this->assertSame(
            'TikTok (bonus)',
            $breakdown['raw_inputs']['touchpoint'] ?? null,
            'Breakdown label should communicate bonus status',
        );
    }

    /** @return array{has_instagram:bool,has_website:bool,has_gmaps:bool,has_wa_business:bool,has_tiktok:bool,review_count:int} */
    private function presence(array $overrides): array
    {
        return array_merge([
            'has_instagram'   => true,
            'has_website'     => false,
            'has_gmaps'       => true,
            'has_wa_business' => false,
            'has_tiktok'      => false,
            'review_count'    => 0,
        ], $overrides);
    }
}
