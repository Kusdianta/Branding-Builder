<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Services\HubCredentialsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Nema\WorkerClient\DTO\InstagramHandleCheckResult;
use Nema\WorkerClient\Exceptions\ProfileAuditException;
use Nema\WorkerClient\NemaWorkerClient;
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

        // BB131 — the IG checker is now worker-first. Default the Hub to
        // "no healthy credential" so the existing web_profile_info cases
        // below exercise the anonymous FALLBACK path their Http::fake
        // mocks target. The worker-path cases re-bind the Hub + worker.
        $this->bindHub(null);
    }

    /** Bind a HubCredentialsClient whose getNextCredential returns $credential. */
    private function bindHub(?array $credential): void
    {
        $hub = Mockery::mock(HubCredentialsClient::class);
        $hub->shouldReceive('getNextCredential')->andReturn($credential);
        $this->app->instance(HubCredentialsClient::class, $hub);
    }

    /** @return array{id:string,session_cookies:list<array<string,string>>} */
    private function fakeCredential(): array
    {
        return [
            'id'              => '01krcredentialulid000000000',
            'platform'        => 'instagram',
            'username'        => 'nairs.vfx',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ];
    }

    // ─── Instagram via worker (BB131 — primary path) ─────────────────

    #[Test]
    public function instagram_found_via_worker(): void
    {
        Http::fake(); // anonymous path must NOT be touched
        $this->bindHub($this->fakeCredential());

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkInstagramHandle')
            ->once()
            ->andReturn(InstagramHandleCheckResult::fromArray([
                'status'         => 'found',
                'username'       => 'ionlaundry',
                'full_name'      => 'ION LAUNDRY',
                'follower_count' => 4135,
                'is_business'    => true,
                'is_private'     => false,
            ]));
        $this->app->instance(NemaWorkerClient::class, $worker);

        $response = $this->postJson('/check-handle/instagram', ['username' => 'ionlaundry']);

        $response->assertOk()->assertJson([
            'exists'         => true,
            'status'         => 'found',
            'display_name'   => 'ION LAUNDRY',
            'follower_count' => 4135,
            'is_business'    => true,
        ]);
        Http::assertNothingSent();
    }

    #[Test]
    public function instagram_not_found_via_worker(): void
    {
        Http::fake();
        $this->bindHub($this->fakeCredential());

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkInstagramHandle')
            ->once()
            ->andReturn(InstagramHandleCheckResult::fromArray([
                'status'   => 'not_found',
                'username' => 'zzz_not_a_real_handle',
            ]));
        $this->app->instance(NemaWorkerClient::class, $worker);

        $response = $this->postJson('/check-handle/instagram', ['username' => 'zzz_not_a_real_handle']);

        $response->assertOk()->assertJson(['exists' => false, 'status' => 'not_found']);
        Http::assertNothingSent();
    }

    #[Test]
    public function instagram_worker_error_falls_back_to_anonymous_probe(): void
    {
        // Worker raises (e.g. stale cookies) → checker falls back to the
        // anonymous probe, which here is rate-limited (429) → 'error'.
        $this->bindHub($this->fakeCredential());

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkInstagramHandle')
            ->once()
            ->andThrow(new ProfileAuditException(
                errorCode: 'login_wall_hit',
                httpStatus: 400,
                detail: 'stale cookies',
                retryAfterSeconds: null,
            ));
        $this->app->instance(NemaWorkerClient::class, $worker);

        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response('throttled', 429),
        ]);

        $response = $this->postJson('/check-handle/instagram', ['username' => 'somehandle']);

        $response->assertOk()->assertJson(['exists' => false, 'status' => 'error']);
        Http::assertSentCount(1); // fallback probe was attempted
    }

    // ─── Instagram anonymous fallback (web_profile_info) ─────────────

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

        // BB107.1 — second call must hit cache (Http::assertSentCount(1)) AND
        // return the same JSON shape (round-trip the array, not the object).
        $first  = $this->postJson('/check-handle/instagram', ['username' => 'nasa']);
        $second = $this->postJson('/check-handle/instagram', ['username' => 'nasa']);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json(), $second->json(), 'cache round-trip preserves shape');

        Http::assertSentCount(1);
    }

    #[Test]
    public function instagram_corrupt_cache_entry_is_evicted_and_refetched(): void
    {
        // BB107.1 — simulate a pre-fix cache entry that came back as a
        // serialized DTO object (or any non-array). The checker must
        // notice, evict, re-fetch, and re-cache as an array. Without
        // this defence, every request after the corrupt write would
        // crash with the TypeError that prompted BB107.1.
        \Illuminate\Support\Facades\Cache::put('ig-handle:nasa', 'corrupt-non-array-payload', 3600);

        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                $this->igFoundFixture(),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->postJson('/check-handle/instagram', ['username' => 'nasa']);

        $response->assertOk()->assertJson(['status' => 'found', 'exists' => true]);
        $this->assertIsArray(\Illuminate\Support\Facades\Cache::get('ig-handle:nasa'));
    }

    // ─── TikTok (Phase 12c.4 — oembed-first, api/user/detail fallback) ───
    //
    // BB134: TikTokHandleChecker now calls the public oembed endpoint FIRST
    // and only falls back to api/user/detail on a 5xx/429/transport error.
    // These cases target the user/detail parser, so each fakes oembed -> 503
    // to force the fallback — which also keeps the suite free of the stray
    // live oembed call the pre-BB134 fakes were leaking.

    #[Test]
    public function tiktok_found_parses_user_detail_json(): void
    {
        Http::fake([
            'tiktok.com/oembed*'          => Http::response('', 503),
            'tiktok.com/api/user/detail*' => Http::response(
                $this->ttFoundFixture(
                    nickname:      'Less Worry Laundry',
                    avatar:        'https://p16.tiktokcdn.com/lw.jpg',
                    followerCount: 1200,
                ),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->postJson('/check-handle/tiktok', ['username' => 'lessworry']);

        $response->assertOk()
            ->assertJson([
                'exists'         => true,
                'status'         => 'found',
                'display_name'   => 'Less Worry Laundry',
                'follower_count' => 1200,
            ]);
    }

    #[Test]
    public function tiktok_status_code_10221_returns_not_found(): void
    {
        Http::fake([
            'tiktok.com/oembed*'          => Http::response('', 503),
            'tiktok.com/api/user/detail*' => Http::response(
                json_encode(['statusCode' => 10221, 'statusMsg' => 'user_not_exist']),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $response = $this->postJson('/check-handle/tiktok', ['username' => 'ghost']);

        $response->assertOk()->assertJson(['exists' => false, 'status' => 'not_found']);
    }

    #[Test]
    public function tiktok_captcha_html_returns_error_not_not_found(): void
    {
        Http::fake([
            'tiktok.com/oembed*'          => Http::response('', 503),
            'tiktok.com/api/user/detail*' => Http::response(
                '<!DOCTYPE html><html><body>Captcha</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $response = $this->postJson('/check-handle/tiktok', ['username' => 'lessworry']);

        $response->assertOk()->assertJson(['exists' => false, 'status' => 'error']);
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

    /**
     * BB113: synthetic user/detail JSON shape matching the TikTok web
     * endpoint contract. statusCode = 0 means "found".
     */
    private function ttFoundFixture(string $nickname, string $avatar, int $followerCount): string
    {
        return (string) json_encode([
            'statusCode' => 0,
            'userInfo'   => [
                'user' => [
                    'id'           => '6900000000000000000',
                    'uniqueId'     => 'lessworry',
                    'nickname'     => $nickname,
                    'avatarLarger' => $avatar,
                    'avatarMedium' => $avatar,
                    'avatarThumb'  => $avatar,
                ],
                'stats' => [
                    'followerCount'  => $followerCount,
                    'followingCount' => 50,
                    'heartCount'     => 320,
                    'videoCount'     => 24,
                ],
            ],
        ]);
    }
}
