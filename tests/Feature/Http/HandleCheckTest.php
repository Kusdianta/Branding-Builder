<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB100/BB101 — handle availability endpoint contract.
 *
 * Covers happy path (parses og:* meta tags), Instagram 404, TikTok
 * soft-404 sentinel ("Couldn't find this account"), transport error,
 * and the regex validator on the controller. Cache is flushed between
 * cases so each request actually hits the faked Http layer.
 */
class HandleCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function instagram_found_parses_meta_tags(): void
    {
        Http::fake([
            'instagram.com/lessworry.kemang/*' => Http::response($this->igProfileHtml(
                title:     'Less Worry Kemang (@lessworry.kemang) • Instagram photos and videos',
                desc:      '5,432 Followers, 123 Following, 89 Posts',
                image:     'https://scontent.cdninstagram.com/v/lw.jpg',
                business:  true,
            ), 200),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'lessworry.kemang',
        ]);

        $response->assertOk()
            ->assertJson([
                'exists'         => true,
                'status'         => 'found',
                'display_name'   => 'Less Worry Kemang',
                'profile_pic_url' => 'https://scontent.cdninstagram.com/v/lw.jpg',
                'follower_count' => 5432,
                'is_business'    => true,
            ]);
    }

    #[Test]
    public function instagram_404_returns_not_found(): void
    {
        Http::fake([
            'instagram.com/nope_no_such_user/*' => Http::response('Page not found', 404),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'nope_no_such_user',
        ]);

        $response->assertOk()
            ->assertJson(['exists' => false, 'status' => 'not_found']);
    }

    #[Test]
    public function instagram_soft_404_via_og_title_returns_not_found(): void
    {
        Http::fake([
            'instagram.com/*' => Http::response(
                '<html><head><meta property="og:title" content="Page Not Found • Instagram"></head></html>',
                200,
            ),
        ]);

        $response = $this->postJson('/check-handle/instagram', [
            'username' => 'soft404user',
        ]);

        $response->assertOk()->assertJson(['exists' => false, 'status' => 'not_found']);
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
            'instagram.com/*' => Http::response($this->igProfileHtml(
                title: 'Cached User (@cacheduser) • Instagram photos and videos',
                desc:  '100 Followers, 50 Following, 10 Posts',
                image: 'https://example.com/img.jpg',
            ), 200),
        ]);

        $this->postJson('/check-handle/instagram', ['username' => 'cacheduser'])->assertOk();
        $this->postJson('/check-handle/instagram', ['username' => 'cacheduser'])->assertOk();

        Http::assertSentCount(1);
    }

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

    private function igProfileHtml(
        string $title,
        string $desc,
        string $image,
        ?bool $business = null,
    ): string {
        $businessTag = $business === null
            ? ''
            : '<script>{"is_business_account":' . ($business ? 'true' : 'false') . '}</script>';
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$desc}">
    <meta property="og:image" content="{$image}">
</head>
<body>{$businessTag}</body>
</html>
HTML;
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
