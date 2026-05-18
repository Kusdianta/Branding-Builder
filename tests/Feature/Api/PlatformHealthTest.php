<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB105 Part 3 — GET /api/health/platform contract.
 *
 * Auth-gated. Returns a 4-service health snapshot:
 *   - worker  : HTTP probe of the FastAPI /health endpoint
 *   - queue   : count of unreserved jobs older than 30 s (database driver)
 *   - db      : PDO connection
 *   - places  : presence of GOOGLE_MAPS_API_KEY
 *
 * Each sub-check returns ['ok' => bool, 'message' => string, ...].
 * The envelope returns 200 regardless of sub-check status; failure is
 * encoded in the `healthy` boolean + per-service `ok` fields.
 */
class PlatformHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.nema_worker.url', 'http://localhost:9878');
        Config::set('services.google.maps_api_key', 'fake-maps-key');
    }

    #[Test]
    public function guests_get_401(): void
    {
        $this->getJson('/api/health/platform')->assertUnauthorized();
    }

    #[Test]
    public function all_green_when_worker_reachable_and_queue_clean_and_db_up_and_places_key_present(): void
    {
        Http::fake([
            'localhost:9878/health' => Http::response(['status' => 'ok'], 200),
        ]);

        $this->actingAs(User::factory()->create())
            ->getJson('/api/health/platform')
            ->assertOk()
            ->assertJson([
                'healthy' => true,
                'services' => [
                    'worker' => ['ok' => true],
                    'queue'  => ['ok' => true],
                    'db'     => ['ok' => true],
                    'places' => ['ok' => true],
                ],
            ]);
    }

    #[Test]
    public function worker_down_flips_healthy_false(): void
    {
        // ConnectionException-style failure simulated by an exception fake.
        Http::fake([
            'localhost:9878/health' => fn () => throw new \RuntimeException('connection refused'),
        ]);

        $r = $this->actingAs(User::factory()->create())
            ->getJson('/api/health/platform');

        $r->assertOk();
        $this->assertFalse($r->json('healthy'));
        $this->assertFalse($r->json('services.worker.ok'));
        $this->assertNull($r->json('services.worker.latency_ms'));
    }

    #[Test]
    public function queue_stuck_flips_healthy_false(): void
    {
        Http::fake(['localhost:9878/health' => Http::response('ok', 200)]);

        // Insert a job whose available_at is older than 30 s + unreserved.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->subSeconds(120)->timestamp,
            'created_at'   => now()->subSeconds(120)->timestamp,
        ]);

        $r = $this->actingAs(User::factory()->create())
            ->getJson('/api/health/platform');

        $r->assertOk();
        $this->assertFalse($r->json('healthy'));
        $this->assertFalse($r->json('services.queue.ok'));
        $this->assertSame(1, $r->json('services.queue.stuck_count'));
    }

    #[Test]
    public function places_key_missing_flips_healthy_false(): void
    {
        Http::fake(['localhost:9878/health' => Http::response('ok', 200)]);
        Config::set('services.google.maps_api_key', null);

        $r = $this->actingAs(User::factory()->create())
            ->getJson('/api/health/platform');

        $r->assertOk();
        $this->assertFalse($r->json('healthy'));
        $this->assertFalse($r->json('services.places.ok'));
    }

    #[Test]
    public function recently_queued_jobs_do_not_count_as_stuck(): void
    {
        Http::fake(['localhost:9878/health' => Http::response('ok', 200)]);

        // Fresh job, under the 30 s threshold — should NOT trip the alarm.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->timestamp,
            'created_at'   => now()->timestamp,
        ]);

        $r = $this->actingAs(User::factory()->create())
            ->getJson('/api/health/platform');

        $r->assertOk();
        $this->assertTrue($r->json('services.queue.ok'));
    }

    #[Test]
    public function reserved_jobs_do_not_count_as_stuck(): void
    {
        Http::fake(['localhost:9878/health' => Http::response('ok', 200)]);

        // Reserved by a worker, long-running — should NOT trip the alarm
        // even though available_at is old.
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{}',
            'attempts'     => 1,
            'reserved_at'  => now()->subSeconds(45)->timestamp,
            'available_at' => now()->subSeconds(180)->timestamp,
            'created_at'   => now()->subSeconds(180)->timestamp,
        ]);

        $r = $this->actingAs(User::factory()->create())
            ->getJson('/api/health/platform');

        $r->assertOk();
        $this->assertTrue($r->json('services.queue.ok'));
    }
}
