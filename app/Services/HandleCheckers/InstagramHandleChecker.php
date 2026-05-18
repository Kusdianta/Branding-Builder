<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB100 → BB107 — Instagram username availability check.
 *
 * BB107 ROOT CAUSE FIX:
 * The original BB100 implementation hit https://www.instagram.com/{username}/
 * and parsed og:title from the HTML head. Instagram stopped serving og:title
 * (and any other profile-specific markup) in the unauthenticated HTML response
 * sometime in 2025–2026: real handles AND fake handles both return an
 * identical ~806KB SPA shell that loads profile data via authenticated JS.
 * Effect: every check returned "not_found", regardless of whether the handle
 * existed. HandleCheckTest stayed green because it fed the parser synthetic
 * HTML that still matched the old contract (fixture rot).
 *
 * BB107 fix: switch to Instagram's `web_profile_info` JSON endpoint:
 *   GET https://www.instagram.com/api/v1/users/web_profile_info/?username=X
 *   Header: x-ig-app-id: 936619743392459   (the public IG web app ID)
 *
 * Behaviour:
 *   - real handle  → 200 OK + JSON `{ data: { user: { ... } } }`
 *   - fake handle  → 200 OK + HTML body containing
 *                    "<title>Page Not Found • Instagram</title>"
 *   - rate-limited → 429 / 5xx / non-JSON / login wall → returns 'error'
 *                    (NOT 'not_found' — avoids false negatives, lets the
 *                    UI show "tidak bisa cek" while the operator can still
 *                    submit the audit).
 *
 * The endpoint is undocumented; if Instagram changes it, the
 * `RUN_LIVE_NETWORK_TESTS=true` smoke test in tests/Feature/Http/
 * InstagramLiveSmokeTest.php surfaces the break early.
 *
 * Caching: 1 hour per username. Cheap server-side, avoids hammering IG.
 */
final class InstagramHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const PROFILE_INFO_URL = 'https://www.instagram.com/api/v1/users/web_profile_info/?username=%s';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
    private const IG_APP_ID = '936619743392459';

    public function check(string $username): InstagramHandleResult
    {
        $normalized = $this->normalize($username);
        if ($normalized === null) {
            return InstagramHandleResult::error($username);
        }

        // BB107.1 — cache the toArray() shape, not the DTO object.
        // Serializing the DTO directly was producing __PHP_Incomplete_Class
        // on certain Herd PHP-FPM workers (autoload-classmap drift between
        // the writer process and a later reader process). A plain array
        // round-trips cleanly through native serialize/unserialize without
        // any class-resolution requirement at deserialize time, so the
        // cache is immune to that whole failure class.
        $payload = Cache::remember(
            "ig-handle:{$normalized}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchAndParse($normalized)->toArray(),
        );

        // Defensive: if a pre-BB107.1 entry (serialized DTO that came back
        // as __PHP_Incomplete_Class) slipped through Cache::remember as a
        // non-array, evict it and re-compute fresh. Belt-and-suspenders —
        // cache:clear at deploy should already have wiped these.
        if (! is_array($payload)) {
            Cache::forget("ig-handle:{$normalized}");
            $payload = $this->fetchAndParse($normalized)->toArray();
            Cache::put("ig-handle:{$normalized}", $payload, self::CACHE_TTL_SECONDS);
        }

        return InstagramHandleResult::fromArray($payload);
    }

    private function normalize(string $raw): ?string
    {
        $clean = ltrim(trim($raw), '@');
        if ($clean === '' || ! preg_match('/^[A-Za-z0-9._]{1,30}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    private function fetchAndParse(string $username): InstagramHandleResult
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

        // Fake handles return 200 with an HTML "Page Not Found" body — the
        // endpoint redirects to the standard 404 page rather than returning
        // a structured JSON 404. Sniff content-type + body so we don't try
        // to JSON-decode HTML.
        $contentType = strtolower($response->header('Content-Type') ?? '');
        $body        = $response->body();

        if (str_contains($contentType, 'text/html')
            || str_contains($body, 'Page Not Found')
        ) {
            return InstagramHandleResult::notFound($username);
        }

        $json = $response->json();
        if (! is_array($json)) {
            // Endpoint returned 2xx with non-JSON, non-HTML body. Login wall
            // / new sentinel page. Treat as inconclusive rather than 404.
            Log::info('InstagramHandleChecker unparseable body', [
                'username' => $username,
                'len'      => strlen($body),
            ]);
            return InstagramHandleResult::error($username);
        }

        $user = $json['data']['user'] ?? null;
        if (! is_array($user)) {
            // {data: {user: null}} would also be a soft-404 sentinel, though
            // in practice the endpoint emits the HTML 404 page instead.
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
