<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Services\HandleCheckers\InstagramHandleChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB107 — live-network smoke against Instagram's web_profile_info endpoint.
 *
 * Skipped by default. The endpoint is undocumented; the BB100 HTML scrape
 * silently rotted for months because the test suite ran exclusively against
 * synthetic fixtures. This guard runs against real Instagram so the moment
 * IG changes the JSON shape (or rate-limits us out, or moves the endpoint),
 * the failure is loud instead of invisible.
 *
 * Run locally or in pre-deploy:
 *
 *     RUN_LIVE_NETWORK_TESTS=true php artisan test \
 *         --filter='InstagramLiveSmokeTest'
 *
 * Cache is bypassed via Cache::flush() so each run actually hits IG.
 * Pick a high-stability handle for the existence check (`nasa` has
 * 100M+ followers and is unlikely to vanish overnight); pick a
 * deliberately-garbage handle for the not-found check so we don't
 * accidentally false-positive against a real low-traffic account.
 */
class InstagramLiveSmokeTest extends TestCase
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
    public function nasa_is_found_with_business_flag_and_followers(): void
    {
        $result = app(InstagramHandleChecker::class)->check('nasa');

        $this->assertSame('found', $result->status, 'NASA should be found');
        $this->assertTrue($result->exists);
        $this->assertNotNull($result->displayName, 'display_name should populate');
        $this->assertNotNull($result->followerCount, 'follower_count should populate');
        $this->assertGreaterThan(
            1_000_000,
            $result->followerCount,
            'NASA followers should be >1M (canary for endpoint shape change)',
        );
    }

    #[Test]
    public function obviously_fake_handle_returns_not_found(): void
    {
        $result = app(InstagramHandleChecker::class)->check(
            'xkjasdkjhasdkj_definitely_does_not_exist_99999',
        );

        $this->assertSame(
            'not_found',
            $result->status,
            'Garbage handle should be not_found, not error/found. If this fails, '
            . 'IG may have changed the not-found sentinel — update the checker.',
        );
        $this->assertFalse($result->exists);
    }
}
