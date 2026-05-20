<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use App\Services\HubCredentialsClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nema\WorkerClient\NemaWorkerClient;
use Throwable;

/**
 * BB100 → BB107 → BB131 — Instagram username availability check.
 *
 * BB131 ROOT CAUSE FIX (the one that makes "Cek dulu" actually work):
 * Instagram now IP-blocks the unauthenticated `web_profile_info` HTTP
 * endpoint from this server — it returns HTTP 429 (empty body) for both
 * anonymous AND cookie-authenticated raw calls. The BB107 direct-HTTP
 * approach therefore returns 'error' on every check, and the wizard shows
 * "Belum bisa cek otomatis (Instagram membatasi pengecekan tanpa login)".
 *
 * The only path that resolves a handle reliably from this infra is the
 * worker's real Chromium (full-browser navigation has a legitimate
 * fingerprint IG accepts). So the check is now WORKER-FIRST:
 *
 *   1. Claim a healthy IG session from the Hub (HubCredentialsClient).
 *   2. Call the worker's lightweight /v1/instagram/handle-check
 *      (NemaWorkerClient::checkInstagramHandle) — ~5-7s, header metadata
 *      only, no grid/posts/screenshot.
 *   3. Fall back to the legacy anonymous web_profile_info probe ONLY when
 *      no credential is available or the worker errors. That fallback
 *      almost always yields 'error' today (IP block), but it keeps the
 *      checker functional on a box where the worker/Hub aren't wired.
 *
 * Status meanings (InstagramHandleResult::$status):
 *   - "found"     : profile resolved (metadata + follower count present)
 *   - "not_found" : IG 404 / soft-404 "page isn't available" shell
 *   - "error"     : couldn't determine (rate limit, stale cookies,
 *                   worker down) — advisory, never blocks the audit.
 *
 * Caching: 1 hour per username, but ONLY for definitive results
 * (found / not_found). 'error' is never cached so a transient failure
 * doesn't lock the operator out of re-checking for an hour.
 */
final class InstagramHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const PROFILE_INFO_URL = 'https://www.instagram.com/api/v1/users/web_profile_info/?username=%s';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
    private const IG_APP_ID = '936619743392459';

    public function __construct(
        private readonly NemaWorkerClient $worker,
        private readonly HubCredentialsClient $hub,
    ) {}

    public function check(string $username): InstagramHandleResult
    {
        $normalized = $this->normalize($username);
        if ($normalized === null) {
            return InstagramHandleResult::error($username);
        }

        // BB107.1 — cache the toArray() shape, not the DTO object, so the
        // value round-trips cleanly through serialize/unserialize without
        // a class-resolution requirement (immune to autoload-classmap
        // drift between Herd PHP-FPM workers).
        $cacheKey = "ig-handle:{$normalized}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return InstagramHandleResult::fromArray($cached);
        }

        $result = $this->resolve($normalized);

        // BB131 — cache only definitive answers. 'error' (rate limit /
        // stale cookies / worker down) stays uncached so the operator can
        // retry immediately instead of being stuck with a stale
        // "tidak bisa cek" badge for an hour.
        if ($result->status === 'found' || $result->status === 'not_found') {
            Cache::put($cacheKey, $result->toArray(), self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    /**
     * Worker-first resolution with anonymous fallback.
     */
    private function resolve(string $username): InstagramHandleResult
    {
        $viaWorker = $this->checkViaWorker($username);
        if ($viaWorker !== null) {
            return $viaWorker;
        }

        return $this->fetchAndParseAnonymous($username);
    }

    /**
     * BB131 — resolve via the worker using a Hub-claimed IG session.
     *
     * Returns null (→ caller falls back to the anonymous probe) when no
     * healthy credential is available or the worker can't give a
     * definitive answer. Never throws.
     */
    private function checkViaWorker(string $username): ?InstagramHandleResult
    {
        try {
            $credential = $this->hub->getNextCredential('instagram');
        } catch (Throwable $e) {
            Log::info('InstagramHandleChecker: Hub credential fetch failed; anonymous fallback', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (! is_array($credential)) {
            return null; // no healthy credential — fall back
        }

        $cookies = $credential['session_cookies'] ?? null;
        if (! is_array($cookies) || $cookies === []) {
            return null;
        }

        try {
            $result = $this->worker->checkInstagramHandle($username, $cookies);
        } catch (Throwable $e) {
            // login_wall_hit / interstitial_blocked / timeout / worker down.
            // All advisory — fall back to the anonymous probe (which will
            // most likely surface 'error', shown as "tidak bisa cek").
            Log::info('InstagramHandleChecker: worker handle-check failed; anonymous fallback', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        if ($result->status === 'not_found') {
            return InstagramHandleResult::notFound($username);
        }

        return new InstagramHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $result->fullName,
            // The lightweight handle-check doesn't fetch the avatar (that
            // would add a CDN round-trip); the wizard only needs name +
            // follower count for its preview badge.
            profilePicUrl: null,
            followerCount: $result->followerCount,
            isBusiness:    $result->isBusiness,
            checkedAt:     now()->toIso8601String(),
        );
    }

    private function normalize(string $raw): ?string
    {
        $clean = ltrim(trim($raw), '@');
        if ($clean === '' || ! preg_match('/^[A-Za-z0-9._]{1,30}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    /**
     * Legacy BB107 anonymous probe — kept as the fallback path for boxes
     * where the worker / Hub aren't reachable. Hits Instagram's
     * `web_profile_info` JSON endpoint directly.
     *
     * Behaviour:
     *   - real handle  → 200 OK + JSON `{ data: { user: { ... } } }`
     *   - fake handle  → 200 OK + HTML "Page Not Found" body, or 404
     *   - rate-limited → 429 / 5xx / non-JSON / login wall → 'error'
     *     (NOT 'not_found' — avoids false negatives). Today IG IP-blocks
     *     this endpoint (429), so this path normally returns 'error'.
     */
    private function fetchAndParseAnonymous(string $username): InstagramHandleResult
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'x-ig-app-id'     => self::IG_APP_ID,
                    'Accept'          => 'application/json, text/plain, */*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::PROFILE_INFO_URL, $username));
        } catch (RequestException|Throwable $e) {
            Log::warning('InstagramHandleChecker transport error', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return InstagramHandleResult::error($username);
        }

        if ($response->status() === 404) {
            return InstagramHandleResult::notFound($username);
        }

        if (! $response->successful()) {
            // 429 / 5xx — transient. Do not claim not_found.
            Log::info('InstagramHandleChecker non-2xx', [
                'username' => $username,
                'status'   => $response->status(),
            ]);
            return InstagramHandleResult::error($username);
        }

        // Fake handles return 200 with an HTML "Page Not Found" body.
        $contentType = strtolower($response->header('Content-Type') ?? '');
        $body        = $response->body();

        if (str_contains($contentType, 'text/html')
            || str_contains($body, 'Page Not Found')
        ) {
            return InstagramHandleResult::notFound($username);
        }

        $json = $response->json();
        if (! is_array($json)) {
            Log::info('InstagramHandleChecker unparseable body', [
                'username' => $username,
                'len'      => strlen($body),
            ]);
            return InstagramHandleResult::error($username);
        }

        $user = $json['data']['user'] ?? null;
        if (! is_array($user)) {
            return InstagramHandleResult::notFound($username);
        }

        return new InstagramHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $this->stringOrNull($user['full_name'] ?? null),
            profilePicUrl: $this->stringOrNull(
                $user['profile_pic_url_hd'] ?? $user['profile_pic_url'] ?? null,
            ),
            followerCount: $this->intOrNull($user['edge_followed_by']['count'] ?? null),
            isBusiness:    isset($user['is_business_account'])
                ? (bool) $user['is_business_account']
                : null,
            checkedAt:     now()->toIso8601String(),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_string($value) ? $value : (string) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return is_numeric($value) ? (int) $value : null;
    }
}
