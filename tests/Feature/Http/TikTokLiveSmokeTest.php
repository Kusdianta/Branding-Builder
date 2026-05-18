<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Services\HandleCheckers\TikTokHandleChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB113 — live-network smoke against TikTok's user/detail JSON endpoint.
 *
 * Skipped by default. The endpoint is undocumented; the BB101 HTML
 * scrape rotted invisibly for months. This guard runs against real
 * TikTok so the moment they change the JSON shape, rate-limit us,
 * or move the endpoint, the failure is loud instead of invisible.
 *
 * Run locally or in pre-deploy:
 *
 *     RUN_LIVE_NETWORK_TESTS=true php artisan test \
 *         --filter='TikTokLiveSmokeTest'
 *
 * Cache is bypassed via Cache::flush() so each run actually hits TikTok.
 * Pick a high-stability handle for the existence check (`tiktok`'s
 * own corporate account has 80M+ followers) and a deliberately-garbage
 * handle for the not-found check.
 */
class TikTokLiveSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('RUN_LIVE_NETWORK_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped(
                'Live-network smoke. Set RUN_LIVE_NETWORK_TESTS=true to run.',
            );
        }

        Cache::flush();
    }

    #[Test]
    public function tiktok_corporate_handle_is_found_with_followers(): void
    {
        $result = app(TikTokHandleChecker::class)->check('tiktok');

        $this->assertSame('found', $result->status, 'TikTok corporate handle should be found');
        $this->assertTrue($result->exists);
        $this->assertNotNull($result->displayName, 'display_name should populate');
        $this->assertNotNull($result->followerCount, 'follower_count should populate');
        $this->assertGreaterThan(
            1_000_000,
            $result->followerCount,
            'TikTok corporate followers should be >1M (canary for endpoint shape change)',
        );
    }

    #[Test]
    public function obviously_fake_handle_returns_not_found(): void
    {
        $result = app(TikTokHandleChecker::class)->check(
            'xkjasdkjhasdkj_definitely_no_99',
        );

        $this->assertSame(
            'not_found',
            $result->status,
            'Garbage handle should be not_found, not error/found. If this fails, '
            . 'TikTok may have changed the statusCode sentinel — update the checker.',
        );
        $this->assertFalse($result->exists);
    }
}
