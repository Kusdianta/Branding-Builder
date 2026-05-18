<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\BrandAudit;
use App\Models\User;
use App\Services\PlatformHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB105 Part 3 — submit() health gate.
 *
 * When PlatformHealthChecker reports unhealthy, submit() must:
 *   - NOT create a BrandAudit row
 *   - NOT charge a credit
 *   - dispatch the `show-platform-unhealthy-modal` Livewire event
 *
 * When healthy, the gate is transparent — submit() proceeds to the
 * placeId / serviceType / credits checks (we don't construct a full
 * happy-path audit here; the assertion is "no health-related early
 * return + no modal event").
 */
class SubmitGatedByHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function mockHealth(bool $healthy): void
    {
        $payload = [
            'healthy'    => $healthy,
            'services'   => [
                'worker' => ['ok' => $healthy, 'message' => $healthy ? 'Worker aktif' : 'Worker tidak bisa dijangkau'],
                'queue'  => ['ok' => true, 'message' => 'Queue aktif'],
                'db'     => ['ok' => true, 'message' => 'Database aktif'],
                'places' => ['ok' => true, 'message' => 'Google Maps API key terkonfigurasi'],
            ],
            'checked_at' => now()->toIso8601String(),
        ];

        $this->mock(PlatformHealthChecker::class, function ($m) use ($payload) {
            $m->shouldReceive('check')->andReturn($payload);
        });
    }

    #[Test]
    public function unhealthy_blocks_submit_and_dispatches_modal_event(): void
    {
        $this->mockHealth(false);
        $user = User::factory()->create(['credits_balance' => 5]);

        Livewire::actingAs($user)
            ->test('brand-audit-wizard')
            ->set('placeId', 'ChIJplaceholder')
            ->set('placeName', 'Test Laundry')
            ->set('serviceType', 'kiloan')
            ->call('submit')
            ->assertDispatched('show-platform-unhealthy-modal');

        $this->assertSame(0, BrandAudit::count(), 'submit() must not create an audit when unhealthy');
        $this->assertSame(5, $user->fresh()->credits_balance, 'submit() must not charge a credit when unhealthy');
    }

    #[Test]
    public function healthy_does_not_dispatch_modal_event(): void
    {
        $this->mockHealth(true);
        $user = User::factory()->create(['credits_balance' => 5]);

        // We deliberately leave placeId null — the gate must pass through
        // to the placeId check, which short-circuits with a different
        // (validation) error. The point is: NO modal event fires.
        Livewire::actingAs($user)
            ->test('brand-audit-wizard')
            ->call('submit')
            ->assertNotDispatched('show-platform-unhealthy-modal');
    }

    #[Test]
    public function submit_force_refreshes_health_cache(): void
    {
        // Seed the cache with a stale "healthy" value, then mock the
        // checker to return UNHEALTHY. submit() must invalidate the
        // cache and call the checker fresh, surfacing the new state.
        Cache::put('platform-health', [
            'healthy'    => true,
            'services'   => [],
            'checked_at' => now()->subMinutes(5)->toIso8601String(),
        ], 60);

        $this->mockHealth(false);
        $user = User::factory()->create(['credits_balance' => 5]);

        Livewire::actingAs($user)
            ->test('brand-audit-wizard')
            ->set('placeId', 'ChIJplaceholder')
            ->set('serviceType', 'kiloan')
            ->call('submit')
            ->assertDispatched('show-platform-unhealthy-modal');
    }
}
