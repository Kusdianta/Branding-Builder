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
 *   - worker : HTTP GET on the FastAPI /health endpoint (3 s timeout)
 *   - queue  : database queue, looks for jobs queued but unreserved >30 s
 *   - db     : opens a PDO connection
 *   - places : presence of the Google Maps API key (no live call)
 *
 * All sub-checks return a uniform shape so the wizard modal can iterate
 * them generically:  ['ok' => bool, 'message' => string, ...].
 */
final class PlatformHealthChecker
{
    private const WORKER_TIMEOUT_SECONDS = 3;
    private const QUEUE_STUCK_THRESHOLD_SECONDS = 30;

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
            // available_at + reserved_at are the canonical Laravel database
            // queue columns. A job is "stuck" only if it's unreserved AND
            // its available_at unix timestamp is older than the threshold.
            // Checking created_at would false-positive on long-running
            // jobs the worker has already picked up.
            $threshold = now()->subSeconds(self::QUEUE_STUCK_THRESHOLD_SECONDS)->timestamp;

            $stuckCount = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<', $threshold)
                ->count();

            return [
                'ok'          => $stuckCount === 0,
                'stuck_count' => $stuckCount,
                'message'     => $stuckCount === 0
                    ? 'Queue aktif'
                    : "Ada {$stuckCount} job menumpuk. Queue worker mungkin mati.",
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
