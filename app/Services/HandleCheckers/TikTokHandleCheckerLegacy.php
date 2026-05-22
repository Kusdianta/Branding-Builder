<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB135 — LEGACY oembed-based TikTok username check (was TikTokHandleChecker
 * through BB136). Kept as the FALLBACK path for the worker-backed
 * {@see TikTokHandleChecker}: used only when no healthy TikTok credential is
 * available in the Hub, or when the authenticated worker scrape errors.
 *
 * History:
 *   BB101 parsed og:title from the public HTML shell; TikTok rotated the
 *   shell and og:title became identical for real and fake handles.
 *   BB113 switched to api/user/detail JSON. Phase 12c.4 (FIX 1) made oembed
 *   the primary path. BB136 enriched the follower count from api/user/detail.
 *
 * Why this is now only a fallback: TikTok serves an EMPTY body (HTTP 200) to
 * the unauthenticated spoke's api/user/detail probe, so this path can resolve
 * found/not_found but rarely a follower count. The authenticated Playwright
 * worker path (BB135) is the reliable source of follower counts.
 *
 *   GET https://www.tiktok.com/oembed?url=https://www.tiktok.com/@{username}
 *   - real handle  → 200 OK + JSON {author_name, thumbnail_url, title, ...}
 *   - fake handle  → 400 + JSON   {status_msg: "Something went wrong"}
 *   - rate-limited → 429/5xx     → 'error' (never 'not_found' — TikTok is
 *                                  bonus-only, must not block submit)
 */
final class TikTokHandleCheckerLegacy
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
            "tt-handle-legacy:{$normalized}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchAndParse($normalized)->toArray(),
        );

        if (! is_array($payload)) {
            Cache::forget("tt-handle-legacy:{$normalized}");
            $payload = $this->fetchAndParse($normalized)->toArray();
            Cache::put("tt-handle-legacy:{$normalized}", $payload, self::CACHE_TTL_SECONDS);
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
        $oembed = $this->tryOembed($username);
        if ($oembed !== null) {
            // BB136 — oembed gives a reliable found/not_found verdict but
            // never exposes follower stats. When the handle exists, enrich
            // the follower count from api/user/detail. Best-effort: a blocked
            // enrichment probe leaves the trusted oembed verdict intact.
            if ($oembed->status === 'found' && $oembed->followerCount === null) {
                return $this->enrichWithFollowerCount($oembed, $username);
            }

            return $oembed;
        }

        return $this->tryUserDetail($username);
    }

    private function enrichWithFollowerCount(TikTokHandleResult $base, string $username): TikTokHandleResult
    {
        $detail = $this->tryUserDetail($username);

        if ($detail->status !== 'found' || $detail->followerCount === null) {
            return $base;
        }

        return new TikTokHandleResult(
            username:      $base->username,
            status:        'found',
            exists:        true,
            displayName:   $base->displayName   ?? $detail->displayName,
            profilePicUrl: $base->profilePicUrl ?? $detail->profilePicUrl,
            followerCount: $detail->followerCount,
            checkedAt:     $base->checkedAt ?? now()->toIso8601String(),
        );
    }

    /**
     * Returns a result when oembed gave a definitive 200 (found) or 400
     * (not_found). Returns null on 5xx / 429 / transport error.
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
            Log::info('TikTokHandleCheckerLegacy oembed transport error; will fall back', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        $status = $response->status();

        if ($status === 400 || $status === 404) {
            return TikTokHandleResult::notFound($username);
        }

        if ($status >= 500 || $status === 429) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $json = $response->json();
        if (! is_array($json) || ! isset($json['author_name']) && ! isset($json['title'])) {
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
            followerCount: null,
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
            Log::warning('TikTokHandleCheckerLegacy user/detail transport error', [
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
