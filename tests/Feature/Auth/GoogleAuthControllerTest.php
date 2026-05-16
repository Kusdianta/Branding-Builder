<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_redirect_endpoint_sends_user_to_google(): void
    {
        config()->set('services.google.client_id', 'test-client');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.google.redirect', 'http://localhost/auth/google/callback');

        $response = $this->get(route('auth.google.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', (string) $response->headers->get('Location'));
    }

    public function test_callback_creates_new_user_with_one_free_credit_and_logs_in(): void
    {
        $this->mockSocialite('g-123', 'newuser@test.local', 'New User', 'https://lh3.googleusercontent.com/avatar.jpg');

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('audits.index'));
        $this->assertDatabaseCount('users', 1);

        $user = User::first();
        $this->assertSame('g-123', $user->google_id);
        $this->assertSame('newuser@test.local', $user->email);
        $this->assertSame(1, $user->credits_balance);
        $this->assertSame(1, $user->credits_lifetime_earned);
        $this->assertNotNull($user->last_login_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_for_existing_google_id_updates_last_login_at_without_resetting_credits(): void
    {
        $existing = User::factory()->create([
            'google_id'       => 'g-999',
            'email'           => 'returning@test.local',
            'credits_balance' => 5,
            'last_login_at'   => now()->subDays(7),
        ]);

        $this->mockSocialite('g-999', 'returning@test.local', 'Returning User', null);

        $this->get(route('auth.google.callback'));

        $existing->refresh();
        $this->assertSame(5, $existing->credits_balance, 'credits must not reset on subsequent logins');
        $this->assertTrue($existing->last_login_at->greaterThan(now()->subMinute()));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_callback_links_email_to_existing_account_when_google_id_is_null(): void
    {
        $existing = User::factory()->create([
            'google_id' => null,
            'email'     => 'seeded@test.local',
        ]);

        $this->mockSocialite('g-555', 'seeded@test.local', 'Seeded User', null);

        $this->get(route('auth.google.callback'));

        $existing->refresh();
        $this->assertSame('g-555', $existing->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_redirects_home_with_flash_on_oauth_failure(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \RuntimeException('oauth blew up'));

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home'));
        $response->assertSessionHas('auth_error');
        $this->assertGuest();
    }

    public function test_logout_clears_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->post(route('auth.logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest();
    }

    private function mockSocialite(string $id, ?string $email, ?string $name, ?string $avatar): void
    {
        $abstract = Mockery::mock(SocialiteUser::class);
        $abstract->shouldReceive('getId')->andReturn($id);
        $abstract->shouldReceive('getEmail')->andReturn($email);
        $abstract->shouldReceive('getName')->andReturn($name);
        $abstract->shouldReceive('getAvatar')->andReturn($avatar);

        Socialite::shouldReceive('driver->user')->andReturn($abstract);
    }
}
