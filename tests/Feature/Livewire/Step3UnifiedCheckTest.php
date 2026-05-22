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

    // ─────────────────────────────────────────────────────────────────
    // BB136 — the single bottom "Cek semua handle" button (checkAllHandles)
    // checks Instagram + TikTok (via checkBothHandles) AND the website (via
    // checkWebsite / WebsiteLivenessScorer) in ONE click. Website is
    // advisory — a dead site shows the badge but never blocks Lanjutkan.
    // ─────────────────────────────────────────────────────────────────

    /** Bind a worker mock that resolves IG + TikTok as "found". */
    private function bindFoundWorker(): void
    {
        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkBothHandles')->andReturn([
            'instagram' => ['status' => 'found', 'exists' => true, 'display_name' => 'ION LAUNDRY', 'follower_count' => 4135],
            'tiktok'    => ['status' => 'found', 'exists' => true, 'display_name' => 'Ion Laundry Surabaya', 'follower_count' => 4738],
        ]);
        $this->app->instance(NemaWorkerClient::class, $worker);
    }

    #[Test]
    public function unified_button_checks_only_filled_fields(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);
        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkBothHandles')->once()->andReturn([
            'instagram' => ['status' => 'found', 'exists' => true, 'display_name' => 'ION', 'follower_count' => 4135],
        ]);
        $this->app->instance(NemaWorkerClient::class, $worker);
        Http::fake(); // a website probe would record — assert none below

        $this->wizard()
            ->set('instagramUsername', 'ionlaundry')
            ->call('checkAllHandles')
            ->assertSet('igCheckStatus', 'found')
            ->assertSet('ttCheckStatus', 'idle')
            ->assertSet('websiteCheckStatus', 'idle');

        // TikTok + website were empty → no per-field probe fired (IG went
        // through the mocked worker, so no real HTTP at all).
        Http::assertNothingSent();
    }

    #[Test]
    public function unified_button_checks_all_three_in_one_call(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);
        $this->bindFoundWorker();
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $this->wizard()
            ->set('instagramUsername', 'ionlaundry')
            ->set('tiktokUsername', 'ionlaundry')
            ->set('wizardWebsiteUrl', 'https://ionlaundry.test')
            ->call('checkAllHandles')
            ->assertSet('igCheckStatus', 'found')
            ->assertSet('igFollowerCount', 4135)
            ->assertSet('ttCheckStatus', 'found')
            ->assertSet('ttFollowerCount', 4738)
            ->assertSet('websiteCheckStatus', 'live');
    }

    #[Test]
    public function gate_blocks_when_instagram_not_found_even_if_others_pass(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);
        $worker = Mockery::mock(NemaWorkerClient::class);
        $worker->shouldReceive('checkBothHandles')->once()->andReturn([
            'instagram' => ['status' => 'not_found', 'exists' => false, 'display_name' => null, 'follower_count' => null],
            'tiktok'    => ['status' => 'found', 'exists' => true, 'display_name' => 'TT', 'follower_count' => 10],
        ]);
        $this->app->instance(NemaWorkerClient::class, $worker);
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $this->wizard()
            ->set('instagramUsername', 'ghostzzz')
            ->set('tiktokUsername', 'realtt')
            ->set('wizardWebsiteUrl', 'https://ionlaundry.test')
            ->call('checkAllHandles')
            ->assertSet('igCheckStatus', 'not_found')
            ->assertSet('ttCheckStatus', 'found')
            ->assertSet('websiteCheckStatus', 'live')
            ->assertSet('canAdvanceFromStep3', false);
    }

    #[Test]
    public function all_three_passing_allows_advance(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);
        $this->bindFoundWorker();
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $this->wizard()
            ->set('instagramUsername', 'ionlaundry')
            ->set('tiktokUsername', 'ionlaundry')
            ->set('wizardWebsiteUrl', 'https://ionlaundry.test')
            ->call('checkAllHandles')
            ->assertSet('canAdvanceFromStep3', true);
    }

    #[Test]
    public function dead_website_is_advisory_and_does_not_block_advance(): void
    {
        $this->bindHub([
            'id'              => '01krcred',
            'session_cookies' => [['name' => 'sessionid', 'value' => 'x']],
        ]);
        $this->bindFoundWorker();
        Http::fake(['*' => Http::response('', 500)]);

        $this->wizard()
            ->set('instagramUsername', 'ionlaundry')
            ->set('tiktokUsername', 'ionlaundry')
            ->set('wizardWebsiteUrl', 'https://downsite.test')
            ->call('checkAllHandles')
            ->assertSet('websiteCheckStatus', 'dead')
            // Website is advisory — a dead site never blocks Lanjutkan.
            ->assertSet('canAdvanceFromStep3', true);
    }

    #[Test]
    public function unified_button_empty_all_is_a_noop(): void
    {
        $this->bindHub(null);
        Http::fake(); // any call would record

        $this->wizard()
            ->call('checkAllHandles')
            ->assertSet('igCheckStatus', 'idle')
            ->assertSet('ttCheckStatus', 'idle')
            ->assertSet('websiteCheckStatus', 'idle');

        Http::assertNothingSent();
    }
}
