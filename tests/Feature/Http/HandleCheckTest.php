<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB100/BB101/BB107 — handle availability endpoint contract.
 *
 * BB107 SHIFT: Instagram tests now hit the `web_profile_info` JSON
 * endpoint (the BB100 HTML-scrape path is dead — IG stopped serving
 * og:title in the unauthenticated shell). Fixtures live in
 * tests/fixtures/instagram/ and were captured from real live responses
 * so the tests reflect actual contract, not synthetic HTML that papers
 * over reality.
 *
 * Live-network smoke (skipped by default) lives in
 * tests/Feature/Http/InstagramLiveSmokeTest.php — set
 * RUN_LIVE_NETWORK_TESTS=true to run it pre-deploy.
 *
 * TikTok tests are unchanged (BB108 will rewrite TikTokHandleChecker
 * the same way; until then TT UI is gated behind WIZARD_SHOW_TIKTOK).
 *
 * Cache is flushed between cases so each request actually hits the
 * faked Http layer.
 */
class HandleCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ─── Instagram (web_profile_info) ────────────────────────────────

    #[Test]
    public function instagram_found_parses_web_profile_info_json(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                $this->igFoundFixture(),
                200,
                ['Content-Type' => 'application/json; charset=utf-8'],
            ),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'nasa',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists'         => true,
                'status'         => 'found',
                'display_name'   => 'NASA',
                'follower_count' => 104352365,
                'is_business'    => true,
            ]);
    }

    #[Test]
    public function instagram_not_found_html_page_returns_not_found(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                $this->igNotFoundFixture(),
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'xkjasdkjhasdkj_nonexistent',
        ]);

        $response->assertOk()
            ->assertJson(['exists' => false, 'status' => 'not_found']);
    }

    #[Test]
    public function instagram_actual_404_status_returns_not_found(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response('', 404),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'nope_no_such_user',
        ]);

        $response->assertOk()
            ->assertJson(['exists' => false, 'status' => 'not_found']);
    }

    #[Test]
    public function instagram_json_with_null_user_returns_not_found(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                json_encode(['data' => ['user' => null]]),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'softnull',
        ]);

        $response->assertOk()
            ->assertJson(['exists' => false, 'status' => 'not_found']);
    }

    #[Test]
    public function instagram_rate_limited_returns_error_not_false_negative(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response('throttled', 429),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'somehandle',
        ]);

        $response->assertOk()
            ->assertJson(['exists' => false, 'status' => 'error']);
    }

    #[Test]
    public function instagram_unparseable_2xx_returns_error_not_false_negative(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                'definitely not JSON nor an IG 404 page',
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'somehandle',
        ]);

        $response->assertOk()
            ->assertJson(['exists' => false, 'status' => 'error']);
    }

    #[Test]
    public function instagram_malformed_username_rejected_by_validator(): void
    {
        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'has space and !',
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function instagram_caches_per_username_for_one_hour(): void
    {
        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                $this->igFoundFixture(),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->postJson('/check-handle/instagram', ['username' => 'nasa'])->assertOk();
        $this->postJson('/check-handle/instagram', ['username' => 'nasa'])->assertOk();

        Http::assertSentCount(1);
    }

    // ─── TikTok (unchanged from BB101; BB108 will rewrite) ───────────

    #[Test]
    public function tiktok_found_parses_og_title(): void
    {
        Http::fake([
            'tiktok.com/@lessworry*' => Http::response($this->ttProfileHtml(
                title: 'Less Worry Laundry (@lessworry) | TikTok',
                desc:  '1.2K Followers, 50 Following, 320 Likes',
                image: 'https://p16.tiktokcdn.com/lw.jpg',
            ), 200),
        ]);

        $response = $this->postJson('/check-handle/tiktok', ['username' => 'lessworry']);

        $response->assertOk()
            ->assertJson([
                'exists'        => true,
                'status'        => 'found',
                'display_name'  => 'Less Worry Laundry',
                'follower_count' => 1200,
            ]);
    }

    #[Test]
    public function tiktok_soft_404_sentinel_returns_not_found(): void
    {
        Http::fake([
            'tiktok.com/@*' => Http::response(
                "<html><body>Couldn't find this account</body></html>",
                200,
            ),
        ]);

        $response = $this->postJson('/check-handle/tiktok', ['username' => 'ghost']);

        $response->assertOk()->assertJson(['exists' => false, 'status' => 'not_found']);
    }

    #[Test]
    public function tiktok_validator_rejects_too_short_username(): void
    {
        $response = $this->postJson('/check-handle/tiktok', ['username' => '']);
        $response->assertStatus(422);
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function igFoundFixture(): string
    {
        return file_get_contents(
            base_path('tests/fixtures/instagram/web_profile_info-nasa.json'),
        );
    }

    private function igNotFoundFixture(): string
    {
        return file_get_contents(
            base_path('tests/fixtures/instagram/web_profile_info-not-found.html'),
        );
    }

    private function ttProfileHtml(string $title, string $desc, string $image): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$desc}">
    <meta property="og:image" content="{$image}">
</head>
</html>
HTML;
    }
}
