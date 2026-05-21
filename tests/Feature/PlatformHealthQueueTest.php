<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\PlatformHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Queue sub-check of PlatformHealthChecker.
 *
 * The wizard banner ("Sistem belum siap") fires when this returns not-ok.
 * Regression guard for the false-positive that made the banner shout
 * "workers inactive" while the queue worker was simply busy on a long
 * BB130 GMaps scrape: a live reservation must count as "worker alive"
 * so jobs waiting behind it are not reported as stuck.
 */
class PlatformHealthQueueTest extends TestCase
{
    use RefreshDatabase;

    private function queueCheck(): array
    {
        $m = new ReflectionMethod(PlatformHealthChecker::class, 'checkQueue');
        $m->setAccessible(true);

        return $m->invoke(new PlatformHealthChecker());
    }

    private function insertJob(?int $reservedAt, int $availableAt): void
    {
        DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => json_encode(['displayName' => 'App\\Jobs\\AnalyzeBrand']),
            'attempts'     => 0,
            'reserved_at'  => $reservedAt,
            'available_at' => $availableAt,
            'created_at'   => now()->timestamp,
        ]);
    }

    #[Test]
    public function empty_queue_is_healthy(): void
    {
        $r = $this->queueCheck();
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['stuck_count']);
    }

    #[Test]
    public function fresh_unreserved_job_is_not_stuck(): void
    {
        // Just dispatched (available now) — younger than the 30s threshold.
        $this->insertJob(reservedAt: null, availableAt: now()->timestamp);
        $r = $this->queueCheck();
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['stuck_count']);
    }

    #[Test]
    public function aged_unreserved_job_with_no_worker_is_stuck(): void
    {
        // 2 min old, unreserved, nothing draining => genuinely stuck.
        $this->insertJob(reservedAt: null, availableAt: now()->subSeconds(120)->timestamp);
        $r = $this->queueCheck();
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $r['stuck_count']);
        $this->assertStringContainsStringIgnoringCase('queue worker', $r['message']);
    }

    #[Test]
    public function aged_unreserved_job_with_live_reservation_is_not_stuck(): void
    {
        // Worker is busy on a long job (reserved 10s ago) while the next
        // job waits — this must NOT flip the banner to unhealthy (BB130).
        $this->insertJob(reservedAt: now()->subSeconds(10)->timestamp, availableAt: now()->subSeconds(200)->timestamp);
        $this->insertJob(reservedAt: null, availableAt: now()->subSeconds(120)->timestamp);
        $r = $this->queueCheck();
        $this->assertTrue($r['ok'], 'A live reservation means the worker is draining; waiting jobs are not stuck');
        $this->assertSame(1, $r['stuck_count']);
    }

    #[Test]
    public function orphaned_reservation_does_not_count_as_alive(): void
    {
        // Reserved 700s ago (> JOB_MAX_RUNTIME 600) => the worker that
        // took it is gone; an aged unreserved job is therefore stuck.
        $this->insertJob(reservedAt: now()->subSeconds(700)->timestamp, availableAt: now()->subSeconds(800)->timestamp);
        $this->insertJob(reservedAt: null, availableAt: now()->subSeconds(120)->timestamp);
        $r = $this->queueCheck();
        $this->assertFalse($r['ok']);
    }
}
