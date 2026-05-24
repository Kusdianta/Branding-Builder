<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BB02/BB03/BB05 — spoke-side SSO. Replaces GoogleAuthControllerTest; the
 * Google OAuth itself now lives in (and is tested by) the Hub.
 */
class SsoCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sso.shared_secret' => self::SECRET,
            'sso.hub_sso_url'   => 'http://nema-hub.test/auth/sso/redirect',
            'sso.spoke_slug'    => 'branding-builder',
        ]);
    }

    /** Build a Hub-format signed token. @param array<string,mixed> $payload */
    private function token(array $payload, ?string $secret = null): string
    {
        $payload += [
            'issued_at'  => time(),
            'expires_at' => time() + 60,
            'nonce'      => 'n-' . uniqid(),
        ];

        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $encoded, $secret ?? self::SECRET);

        return $encoded . '.' . $sig;
    }

    public function test_callback_creates_new_user_with_one_free_credit_and_logs_in(): void
    {
        $token = $this->token([
            'hub_user_id' => 'hub-1', 'google_id' => 'g-1',
            'email' => 'new@test.local', 'name' => 'New User',
            'avatar' => 'https://lh3.googleusercontent.com/a.png',
        ]);

        $response = $this->get('/auth/sso/callback?sso_token=' . urlencode($token));

        $response->assertRedirect(route('audits.index'));
        $this->assertDatabaseCount('users', 1);

        $user = User::first();
        $this->assertSame('hub-1', $user->hub_user_id);
        $this->assertSame('g-1', $user->google_id);
        $this->assertSame('new@test.local', $user->email);
        $this->assertSame(1, $user->credits_balance);
        $this->assertSame(1, $user->credits_lifetime_earned);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_preserves_credits_for_existing_google_user_and_backfills_hub_id(): void
    {
        $existing = User::factory()->create([
            'google_id' => 'g-9', 'hub_user_id' => null,
            'email' => 'returning@test.local', 'credits_balance' => 5,
        ]);

        $token = $this->token([
            'hub_user_id' => 'hub-9', 'google_id' => 'g-9',
            'email' => 'returning@test.local', 'name' => 'Returning',
        ]);

        $this->get('/auth/sso/callback?sso_token=' . urlencode($token))
            ->assertRedirect(route('audits.index'));

        $existing->refresh();
        $this->assertSame(5, $existing->credits_balance, 'credits must not reset on SSO login');
        $this->assertSame('hub-9', $existing->hub_user_id, 'hub_user_id must be back-filled');
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($existing);
    }

    public function test_callback_reuses_user_matched_by_hub_user_id(): void
    {
        $existing = User::factory()->create([
            'hub_user_id' => 'hub-x', 'google_id' => 'g-x', 'credits_balance' => 3,
        ]);

        $token = $this->token([
            'hub_user_id' => 'hub-x', 'google_id' => 'g-x',
            'email' => 'x@test.local', 'name' => 'X',
        ]);

        $this->get('/auth/sso/callback?sso_token=' . urlencode($token));

        $existing->refresh();
        $this->assertSame(3, $existing->credits_balance);
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($existing);
    }

    public function test_callback_rejects_invalid_token(): void
    {
        $response = $this->get('/auth/sso/callback?sso_token=garbage.signature');

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('auth_error');
        $this->assertGuest();
    }

    public function test_callback_rejects_token_signed_with_wrong_secret(): void
    {
        $token = $this->token(['hub_user_id' => 'hub-1', 'google_id' => 'g-1', 'email' => 'a@b.c'], 'wrong-secret');

        $this->get('/auth/sso/callback?sso_token=' . urlencode($token))
            ->assertRedirect(route('home'))
            ->assertSessionHas('auth_error');
        $this->assertGuest();
    }

    public function test_login_route_bounces_to_hub_sso(): void
    {
        $response = $this->get('/auth/login');

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('http://nema-hub.test/auth/sso/redirect', $location);
        $this->assertStringContainsString('spoke=branding-builder', $location);
        $this->assertStringContainsString('callback=', $location);
    }

    public function test_logout_clears_local_session_and_notifies_hub(): void
    {
        Http::fake();
        config(['services.hub.url' => 'http://nema-hub.test']);

        $user = User::factory()->create(['hub_user_id' => 'hub-7']);
        $this->actingAs($user);

        $this->post('/auth/logout')->assertRedirect(route('home'));
        $this->assertGuest();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/sso/logout'));
    }

    public function test_inbound_logout_deletes_sessions_for_hub_user(): void
    {
        config(['services.hub.users_api_key' => 'inbound-key']);

        $user = User::factory()->create(['hub_user_id' => 'hub-z']);
        DB::table('sessions')->insert([
            'id' => 'sess-1', 'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer inbound-key'])
            ->postJson('/api/internal/auth/logout', ['hub_user_id' => 'hub-z'])
            ->assertOk()
            ->assertJson(['logged_out' => true, 'sessions_deleted' => 1]);

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-1']);
    }

    public function test_inbound_logout_requires_bearer(): void
    {
        $this->postJson('/api/internal/auth/logout', ['hub_user_id' => 'hub-z'])
            ->assertUnauthorized();
    }
}
