<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB101 — TikTok username availability check via direct HTTP.
 *
 * Hits https://www.tiktok.com/@{username} with a realistic UA, then
 * parses og:title / og:image / SIGI_STATE JSON. TikTok's 404 page
 * returns a 200 with "Couldn't find this account" sentinel in the
 * HTML body — explicit substring detection covers it.
 *
 * Caching: 1 hour per username. Same rationale as InstagramHandleChecker.
 *
 * Per the Phase 12c.1 spec, TikTok is treated as a bonus signal, so
 * an "error" verdict here never blocks audit submission.
 */
final class TikTokHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const PROFILE_URL = 'https://www.tiktok.com/@%s';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

    public function check(string $username): TikTokHandleResult
    {
        $normalized = $this->normalize($username);
        if ($normalized === null) {
            return TikTokHandleResult::error($username);
        }

        return Cache::remember(
            "tt-handle:{$normalized}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchAndParse($normalized),
        );
    }

    private function normalize(string $raw): ?string
    {
        $clean = ltrim(trim($raw), '@');
        // TikTok allows 2–24 chars: letters, digits, underscore, period.
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
                    'Accept'          => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::PROFILE_URL, $username));
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
            return TikTokHandleResult::error($username);
        }

        $html = $response->body();

        // TikTok's soft-404 sentinel.
        if (
            str_contains($html, "Couldn't find this account")
            || str_contains($html, 'Page not available')
        ) {
            return TikTokHandleResult::notFound($username);
        }

        $ogTitle = $this->extractMeta($html, 'og:title');
        if ($ogTitle === null) {
            // No og:title and no sentinel — probably a captcha wall or
            // login redirect. Treat as transient error.
            return TikTokHandleResult::error($username);
        }

        $description = $this->extractMeta($html, 'og:description');

        return new TikTokHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $this->parseDisplayName($ogTitle, $username),
            profilePicUrl: $this->extractMeta($html, 'og:image'),
            followerCount: $this->parseFollowerCount($description),
            checkedAt:     now()->toIso8601String(),
        );
    }

    private function extractMeta(string $html, string $property): ?string
    {
        $quoted = preg_quote($property, '/');
        if (preg_match(
            '/<meta\s+property=["\']' . $quoted . '["\']\s+content=["\']([^"\']+)["\']/i',
            $html,
            $m,
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        if (preg_match(
            '/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']' . $quoted . '["\']/i',
            $html,
            $m,
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        return null;
    }

    /** og:title is "Display Name (@username) | TikTok" or just "@username | TikTok". */
    private function parseDisplayName(string $ogTitle, string $username): ?string
    {
        $stripped = preg_replace('/\s*\|\s*TikTok.*$/u', '', $ogTitle) ?? $ogTitle;
        if (preg_match('/^(.+?)\s*\(@' . preg_quote($username, '/') . '\)\s*$/u', $stripped, $m)) {
            $name = trim($m[1]);
            return $name !== '' ? $name : null;
        }
        return null;
    }

    /**
     * TikTok's og:description embeds counts at the start:
     *   "5.4K Followers, 123 Following, 89 Likes. Watch the latest video..."
     * Handle k/m/b suffixes since TikTok rounds large numbers.
     */
    private function parseFollowerCount(?string $description): ?int
    {
        if ($description === null) {
            return null;
        }
        if (preg_match('/([\d.,]+)\s*([KMBkmb]?)\s+Followers/i', $description, $m)) {
            $raw = (float) str_replace(',', '', $m[1]);
            $multiplier = match (strtoupper($m[2] ?? '')) {
                'K'     => 1_000,
                'M'     => 1_000_000,
                'B'     => 1_000_000_000,
                default => 1,
            };
            return (int) round($raw * $multiplier);
        }
        return null;
    }
}
