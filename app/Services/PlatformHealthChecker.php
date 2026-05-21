<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * BB105 Part 3 — platform health probe used by the wizard mount() banner
 * and the submit() hard gate.
 *
 * Four sub-checks:
 *   - worker : HTTP GET on the FastAPI /health endpoint (8 s timeout)
 *   - queue  : database queue — flags stuck ONLY when aged unreserved
 *              jobs exist AND no worker is actively draining the queue
 *   - db     : opens a PDO connection
 *   - places : presence of the Google Maps API key (no live call)
 *
 * All sub-checks return a uniform shape so the wizard modal can iterate
 * them generically:  ['ok' => bool, 'message' => string, ...].
 *
 * Not `final`: the wizard submit() gate is unit-tested by mocking this
 * service, which Mockery cannot do on a final class.
 */
class PlatformHealthChecker
{
    // 8s (was 3s): the head-full GMaps worker's /health can take ~4s
    // under load, and a 3s ceiling false-flagged a healthy worker as
    // down. 8s tolerates that without masking a genuinely dead worker.
    private const WORKER_TIMEOUT_SECONDS = 8;
    private const QUEUE_STUCK_THRESHOLD_SECONDS = 30;
    // Longest a single job may run before the queue worker (--timeout=600)
    // would reap it. A reservation newer than this means a worker is
    // alive and processing; older means the worker that took it is gone.
    private const JOB_MAX_RUNTIME_SECONDS = 600;

    /**
     * @return array{healthy: bool, services: array<string,array<string,mixed>>, checked_at: string}
     */
    public function check(): array
    {
        $services = [
            'worker' => $this->checkWorker(),
            'queue'  => $this->checkQueue(),
            'db'     => $this->checkDb(),
            'places' => $this->checkPlacesApi(),
        ];

        return [
            'healthy'    => $services['worker']['ok']
                && $services['queue']['ok']
                && $services['db']['ok']
                && $services['places']['ok'],
            'services'   => $services,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /** @return array{ok: bool, latency_ms: int|null, message: string} */
    private function checkWorker(): array
    {
        $baseUrl = (string) (config('services.nema_worker.url') ?: 'http://localhost:9878');
        try {
            $start = microtime(true);
            $r = Http::timeout(self::WORKER_TIMEOUT_SECONDS)
                ->get(rtrim($baseUrl, '/') . '/health');
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            return [
                'ok'         => $r->successful(),
                'latency_ms' => $latencyMs,
                'message'    => $r->successful()
                    ? 'Worker aktif (' . $latencyMs . ' ms)'
                    : 'Worker membalas dengan status ' . $r->status(),
            ];
        } catch (Throwable $e) {
            return [
                'ok'         => false,
                'latency_ms' => null,
                'message'    => 'Worker tidak bisa dijangkau di ' . $baseUrl,
            ];
        }
    }

    /** @return array{ok: bool, stuck_count?: int, message: string} */
    private function checkQueue(): array
    {
        try {
            $now = now()->timestamp;
            $staleThreshold = $now - self::QUEUE_STUCK_THRESHOLD_SECONDS;
            $reservedFloor  = $now - self::JOB_MAX_RUNTIME_SECONDS;

            // A worker actively draining the queue holds a reservation
            // whose reserved_at is recent (within the max job runtime). If
            // one exists, the worker is ALIVE — jobs queued behind a
            // long-running scrape (BB130 GMaps full-corpus, ~1-3 min) are
            // merely waiting, not stuck. This is the key false-positive
            // fix: previously any unreserved job older than 30s flipped the
            // banner to "workers inactive" even while the worker was busy.
            $liveReserved = DB::table('jobs')
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '>=', $reservedFloor)
                ->exists();

            // Stuck = unreserved AND aged past the stale threshold.
            $stuckCount = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<', $staleThreshold)
                ->count();

            $ok = $stuckCount === 0 || $liveReserved;

            if ($ok && $liveReserved && $stuckCount > 0) {
                $message = "Queue aktif — worker sedang memproses ({$stuckCount} job mengantre).";
            } elseif ($ok) {
                $message = 'Queue aktif';
            } else {
                // Genuinely no worker draining the queue — actionable, names
                // the queue worker explicitly (not the FastAPI worker).
                $message = "Ada {$stuckCount} job menumpuk dan tidak ada queue worker yang memprosesnya. "
                    . "Jalankan 'composer dev' atau 'php artisan queue:work --timeout=600'.";
            }

            return [
                'ok'          => $ok,
                'stuck_count' => $stuckCount,
                'message'     => $message,
            ];
        } catch (Throwable $e) {
            return [
                'ok'      => false,
                'message' => 'Tidak bisa cek status queue',
            ];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function checkDb(): array
    {
        try {
            DB::connection()->getPdo();
            return ['ok' => true, 'message' => 'Database aktif'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Database tidak bisa dijangkau'];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function checkPlacesApi(): array
    {
        $present = ! empty(config('services.google.maps_api_key'));

        return [
            'ok'      => $present,
            'message' => $present
                ? 'Google Maps API key terkonfigurasi'
                : 'GOOGLE_MAPS_API_KEY belum diset di .env',
        ];
    }
}
