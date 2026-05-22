<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Services\HubCredentialsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Nema\WorkerClient\NemaWorkerClient;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB135 — the unified Step 3 "Cek dulu" button (checkBothHandles) resolves
 * Instagram + TikTok in one action: via the worker's parallel check-both
 * when credentials exist, falling back per-platform when they don't.
 */
class Step3UnifiedCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // mount() probes platform health — pre-seed so tests stay hermetic.
        Cache::forever('platform-health', [
            'healthy'    => true,
            'services'   => [],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function bindHub(?array $credential): void
    {
        $hub = Mockery::mock(HubCredentialsClient::class);
        $hub->shouldReceive('getNextCredential')->andReturn($credential);
        $this->app->instance(HubCredentialsClient::class, $hub);
    }

    private function wizard()
    {
        return Livewire::actingAs(User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3);
    }

    #[Test]
    public function single_button_checks_both_via_worker(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkBothHandles')->once()->andReturn([
            'instagram' => ['status' => 'found', 'exists' => true, 'display_name' => 'ION LAUNDRY', 'follower_count' => 4135],
            'tiktok'    => ['status' => 'found', 'exists' => true, 'display_name' => 'Ion Laundry Surabaya', 'follower_count' => 4738],
            'elapsed_ms' => 5400,
        ]);
        $this->app->instance(NemaWorkerClient::class, $worker);

        $this->wizard()
            ->set('instagramUsername', 'ionlaundry')
            ->set('tiktokUsername', 'ionlaundry')
            ->call('checkBothHandles')
            ->assertSet('igCheckStatus', 'found')
            ->assertSet('igFollowerCount', 4135)
            ->assertSet('igDisplayName', 'ION LAUNDRY')
            ->assertSet('ttCheckStatus', 'found')
            ->assertSet('ttFollowerCount', 4738)
            ->assertSet('ttDisplayName', 'Ion Laundry Surabaya');
    }

    #[Test]
    public function partial_worker_failure_is_isolated(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);

        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkBothHandles')->once()->andReturn([
            'instagram' => ['status' => 'found', 'exists' => true, 'display_name' => 'ION', 'follower_count' => 4135],
            'tiktok'    => ['status' => 'error', 'exists' => false, 'display_name' => null, 'follower_count' => null, 'error' => 'captcha_hit'],
            'elapsed_ms' => 5400,
        ]);
        $this->app->instance(NemaWorkerClient::class, $worker);

        $this->wizard()
            ->set('instagramUsername', 'ionlaundry')
            ->set('tiktokUsername', 'ionlaundry')
            ->call('checkBothHandles')
            ->assertSet('igCheckStatus', 'found')
            ->assertSet('ttCheckStatus', 'error');
    }

    #[Test]
    public function falls_back_per_platform_when_no_credential(): void
    {
        // No credentials → IG uses the anonymous web_profile_info probe,
        // TikTok uses the legacy oembed probe. Fake both as found.
        $this->bindHub(null);

        Http::fake([
            'instagram.com/api/v1/users/web_profile_info*' => Http::response(
                json_encode(['data' => ['user' => [
                    'full_name'         => 'NASA',
                    'edge_followed_by'  => ['count' => 104352365],
                    'is_business_account' => true,
                ]]]),
                200,
                ['Content-Type' => 'application/json'],
            ),
            'tiktok.com/oembed*' => Http::response(
                json_encode(['author_name' => 'NASA', 'author_url' => 'https://www.tiktok.com/@nasa', 'title' => 'x']),
                200,
                ['Content-Type' => 'application/json'],
            ),
            'tiktok.com/api/user/detail*' => Http::response('', 503),
        ]);

        $this->wizard()
            ->set('instagramUsername', 'nasa')
            ->set('tiktokUsername', 'nasa')
            ->call('checkBothHandles')
            ->assertSet('igCheckStatus', 'found')
            ->assertSet('ttCheckStatus', 'found');
    }

    #[Test]
    public function empty_both_is_a_noop(): void
    {
        $this->bindHub(null);
        Http::fake(); // any call would record

        $this->wizard()
            ->call('checkBothHandles')
            ->assertSet('igCheckStatus', 'idle')
            ->assertSet('ttCheckStatus', 'idle');

        Http::assertNothingSent();
    }
}
