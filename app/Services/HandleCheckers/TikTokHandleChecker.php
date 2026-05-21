<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB113 → Phase 12c.4 — TikTok username availability check.
 *
 * History:
 *   BB101 parsed og:title from the public HTML shell; TikTok rotated
 *   the shell and og:title became identical for real and fake handles.
 *   BB113 switched to api/user/detail JSON endpoint; that endpoint is
 *   undocumented and returns HTML (captcha/login wall) for some
 *   user-agents, causing "Tidak bisa cek".
 *
 * Phase 12c.4 fix (FIX 1): the **oembed** endpoint is a public,
 * documented TikTok API that needs no login and gives a clean
 * found/not_found distinction:
 *
 *   GET https://www.tiktok.com/oembed?url=https://www.tiktok.com/@{username}
 *
 *   - real handle  → 200 OK + JSON {author_name, thumbnail_url, title, ...}
 *   - fake handle  → 400 + JSON   {status_msg: "Something went wrong"}
 *   - rate-limited → 429/5xx     → 'error' (never 'not_found' — TikTok
 *                                  is bonus-only, must not block submit)
 *
 * Fallback chain: if oembed returns a transport error or a 5xx, we
 * fall back to the api/user/detail endpoint (BB113 path) so we don't
 * regress against the small percentage of cases where oembed itself
 * is flaky. Both paths are cached together per username — once we
 * resolve found/not_found via either path, the second path is skipped
 * for the next hour.
 */
final class TikTokHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const OEMBED_URL      = 'https://www.tiktok.com/oembed?url=https://www.tiktok.com/@%s';
    private const USER_DETAIL_URL = 'https://www.tiktok.com/api/user/detail/?uniqueId=%s';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function check(string $username): TikTokHandleResult
    {
        $normalized = $this->normalize($username);
        if ($normalized === null) {
            return TikTokHandleResult::error($username);
        }

        $payload = Cache::remember(
            "tt-handle:{$normalized}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchAndParse($normalized)->toArray(),
        );

        if (! is_array($payload)) {
            Cache::forget("tt-handle:{$normalized}");
            $payload = $this->fetchAndParse($normalized)->toArray();
            Cache::put("tt-handle:{$normalized}", $payload, self::CACHE_TTL_SECONDS);
        }

        return TikTokHandleResult::fromArray($payload);
    }

    private function normalize(string $raw): ?string
    {
        $clean = ltrim(trim($raw), '@');
        if ($clean === '' || ! preg_match('/^[A-Za-z0-9._]{2,24}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    private function fetchAndParse(string $username): TikTokHandleResult
    {
        // Primary path: oembed. Public, documented, no login required,
        // and the only TikTok endpoint that distinguishes found/not-
        // found by HTTP code (200 vs 400) instead of by JSON sentinel.
        $oembed = $this->tryOembed($username);
        if ($oembed !== null) {
            return $oembed;
        }

        // Fallback path: api/user/detail (BB113 endpoint). Used only
        // when oembed errored at the transport layer or returned a
        // 5xx — the JSON-sentinel parsing here is more brittle but
        // covers the rare oembed flakiness window.
        return $this->tryUserDetail($username);
    }

    /**
     * Returns a result when oembed gave a definitive 200 (found) or
     * 400 (not_found). Returns null on 5xx / 429 / transport error so
     * the caller can fall back to user/detail.
     */
    private function tryOembed(string $username): ?TikTokHandleResult
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'Accept'          => 'application/json, text/plain, */*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::OEMBED_URL, $username));
        } catch (RequestException|Throwable $e) {
            Log::info('TikTokHandleChecker oembed transport error; will fall back', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        $status = $response->status();

        // 400 is TikTok's "no such profile" signal for oembed.
        if ($status === 400 || $status === 404) {
            return TikTokHandleResult::notFound($username);
        }

        // 429 / 5xx → indeterminate, defer to fallback.
        if ($status >= 500 || $status === 429) {
            Log::info('TikTokHandleChecker oembed non-success; will fall back', [
                'username' => $username,
                'status'   => $status,
            ]);
            return null;
        }

        if (! $response->successful()) {
            Log::info('TikTokHandleChecker oembed unexpected non-2xx; will fall back', [
                'username' => $username,
                'status'   => $status,
            ]);
            return null;
        }

        $json = $response->json();
        if (! is_array($json) || ! isset($json['author_name']) && ! isset($json['title'])) {
            // 200 with no profile fields → still treat as found (oembed
            // is permissive about field presence) only if we have any
            // author_url; otherwise fall back.
            if (! isset($json['author_url']) && ! isset($json['html'])) {
                return null;
            }
        }

        return new TikTokHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $this->stringOrNull($json['author_name'] ?? null),
            profilePicUrl: $this->stringOrNull($json['thumbnail_url'] ?? null),
            followerCount: null, // oembed doesn't expose follower count
            checkedAt:     now()->toIso8601String(),
        );
    }

    private function tryUserDetail(string $username): TikTokHandleResult
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->withHeaders([
                    'Accept'          => 'application/json, text/plain, */*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Referer'         => 'https://www.tiktok.com/',
                ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::USER_DETAIL_URL, $username));
        } catch (RequestException|Throwable $e) {
            Log::warning('TikTokHandleChecker user/detail transport error', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return TikTokHandleResult::error($username);
        }

        if ($response->status() === 404) {
            return TikTokHandleResult::notFound($username);
        }

        if (! $response->successful()) {
            return TikTokHandleResult::error($username);
        }

        $contentType = strtolower($response->header('Content-Type') ?? '');
        if (str_contains($contentType, 'text/html')) {
            return TikTokHandleResult::error($username);
        }

        $json = $response->json();
        if (! is_array($json)) {
            return TikTokHandleResult::error($username);
        }

        $statusCode = $json['statusCode'] ?? null;
        if ($statusCode !== null && (int) $statusCode !== 0) {
            return TikTokHandleResult::notFound($username);
        }

        $user  = $json['userInfo']['user']  ?? null;
        $stats = $json['userInfo']['stats'] ?? null;

        if (! is_array($user)) {
            return TikTokHandleResult::notFound($username);
        }

        return new TikTokHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $this->stringOrNull($user['nickname'] ?? null),
            profilePicUrl: $this->stringOrNull(
                $user['avatarLarger'] ?? $user['avatarMedium'] ?? $user['avatarThumb'] ?? null,
            ),
            followerCount: $this->intOrNull(
                is_array($stats) ? ($stats['followerCount'] ?? null) : null,
            ),
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
