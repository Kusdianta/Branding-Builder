<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Phase 12c.2-rubric-alignment BB116 — Website liveness scorer.
 *
 * Issues a single 5-second HEAD-with-GET-fallback against the
 * website URL the operator selected (Places.website ⊃ touchpoint).
 * Returns 20 pts on any 2xx/3xx, 0 otherwise.
 *
 * Honest unavailability:
 *   - $url null/empty         → score 0, source = "input form audit",
 *                               unavailable_reason "tidak ada URL".
 *   - Timeout / connection    → score 0, source kept, unavailable_reason
 *                               explains the failure shape (not a 404).
 *   - 4xx / 5xx response      → score 0, evidence includes http_status
 *                               so the operator can see what came back.
 *
 * Caching is intentionally absent: liveness is a real-time signal
 * (sites go down between audits), and the cost is one HTTP request
 * per audit run.
 */
final class WebsiteLivenessScorer
{
    private const MAX_SCORE       = 20;
    private const TIMEOUT_SECONDS = 5;
    private const USER_AGENT      = 'Mozilla/5.0 (compatible; NemaBrandAuditBot/1.0; +https://nema.creativeapq.online)';

    /**
     * @return array{
     *   score: int,
     *   is_live: bool,
     *   evidence: array<string,mixed>,
     *   source: string,
     *   unavailable_reason: string|null,
     * }
     */
    public function check(?string $url): array
    {
        if ($url === null || trim($url) === '') {
            return [
                'score'              => 0,
                'is_live'            => false,
                'evidence'           => ['reason' => 'tidak ada URL website terdaftar'],
                'source'             => 'Sumber: input form audit (kolom website)',
                'unavailable_reason' => 'Operator tidak mengisi URL website di form audit.',
            ];
        }

        $startedAt = microtime(true);
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent(self::USER_AGENT)
                ->withHeaders(['Accept' => 'text/html,*/*;q=0.8'])
                ->get($url);
        } catch (ConnectionException $e) {
            return [
                'score'              => 0,
                'is_live'            => false,
                'evidence'           => ['url' => $url, 'error' => 'connection_failed'],
                'source'             => 'Sumber: cek HTTP langsung ke website',
                'unavailable_reason' => 'Website tidak bisa dijangkau (' . $this->shortError($e) . ').',
            ];
        } catch (Throwable $e) {
            return [
                'score'              => 0,
                'is_live'            => false,
                'evidence'           => ['url' => $url, 'error' => 'transport_error'],
                'source'             => 'Sumber: cek HTTP langsung ke website',
                'unavailable_reason' => 'Cek HTTP gagal: ' . $this->shortError($e),
            ];
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $isLive    = $response->successful() || $response->redirect();

        return [
            'score'              => $isLive ? self::MAX_SCORE : 0,
            'is_live'            => $isLive,
            'evidence'           => [
                'url'              => $url,
                'http_status'      => $response->status(),
                'response_time_ms' => $elapsedMs,
            ],
            'source'             => 'Sumber: cek HTTP langsung ke website',
            'unavailable_reason' => $isLive ? null : sprintf(
                'Website membalas HTTP %d (bukan 2xx/3xx).',
                $response->status(),
            ),
        ];
    }

    private function shortError(Throwable $e): string
    {
        $msg = $e->getMessage();
        return strlen($msg) > 120 ? substr($msg, 0, 117) . '…' : $msg;
    }
}
