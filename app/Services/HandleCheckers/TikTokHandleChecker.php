<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB113 — TikTok username availability check via JSON endpoint.
 *
 * BB101 → BB113 ROOT CAUSE FIX:
 * The BB101 implementation parsed `og:title` from the public profile
 * HTML. TikTok rotated their unauthenticated profile shell during
 * Phase 12c.1 and the `og:title` meta tag now resolves identically
 * for real and fake handles (mirrors the BB100 → BB107 Instagram
 * regression). Effect: every TikTok check returned 'error' or
 * 'not_found' regardless of whether the handle existed.
 *
 * BB113 fix: switch to TikTok's `user/detail` JSON endpoint, which
 * is what the desktop web app calls after the SPA shell loads:
 *
 *   GET https://www.tiktok.com/api/user/detail/?uniqueId={username}
 *
 * Behaviour:
 *   - real handle  → 200 OK + JSON `{ userInfo: { user: {...}, stats: {...} } }`
 *   - fake handle  → 200 OK + JSON `{ statusCode: 10221 }` (no user payload)
 *   - rate-limited → 429 / 5xx / CAPTCHA HTML / login wall → 'error'
 *                    (NOT 'not_found' — avoids false negatives. Per
 *                    BB113 spec, TikTok is bonus-only so 'error' never
 *                    blocks audit submission).
 *
 * Endpoint is undocumented; RUN_LIVE_NETWORK_TESTS=true smoke surfaces
 * any TikTok-side regression early. Caching: 1 hour per username,
 * stored as toArray() shape to avoid the __PHP_Incomplete_Class
 * regression that BB107.1 fixed for Instagram.
 */
final class TikTokHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const USER_DETAIL_URL = 'https://www.tiktok.com/api/user/detail/?uniqueId=%s';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

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
            Log::warning('TikTokHandleChecker transport error', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return TikTokHandleResult::error($username);
        }

        if ($response->status() === 404) {
            return TikTokHandleResult::notFound($username);
        }

        if (! $response->successful()) {
            Log::info('TikTokHandleChecker non-2xx', [
                'username' => $username,
                'status'   => $response->status(),
            ]);
            return TikTokHandleResult::error($username);
        }

        $contentType = strtolower($response->header('Content-Type') ?? '');
        $body        = $response->body();

        if (str_contains($contentType, 'text/html')) {
            Log::info('TikTokHandleChecker HTML response (captcha/login wall)', [
                'username' => $username,
            ]);
            return TikTokHandleResult::error($username);
        }

        $json = $response->json();
        if (! is_array($json)) {
            Log::info('TikTokHandleChecker unparseable body', [
                'username' => $username,
                'len'      => strlen($body),
            ]);
            return TikTokHandleResult::error($username);
        }

        // TikTok's not-found sentinel: statusCode 10221 (user_not_exist).
        // Any non-zero statusCode means "no profile" or "blocked".
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
