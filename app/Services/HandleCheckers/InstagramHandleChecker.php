<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB100 — Instagram username availability check via direct HTTP.
 *
 * Hits https://www.instagram.com/{username}/ with a realistic UA, then
 * parses og:title / og:image / meta description tags out of the HTML.
 * Instagram's 404 page serves an "Page not found" sentinel inside
 * og:title so detection works without relying on status code (which
 * occasionally returns 200 with a soft-404 body).
 *
 * Caching: 1 hour per username. Cheap server-side, avoids hammering IG.
 *
 * Limitation accepted by the Phase 12c.1 spec: anonymous HTTP is
 * rate-limited and occasionally returns a login wall. The "error"
 * result lets the frontend gracefully degrade — the user can still
 * submit the audit. Worker delegation (Option B) is 12d backlog.
 */
final class InstagramHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 8;
    private const PROFILE_URL = 'https://www.instagram.com/%s/';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

    public function check(string $username): InstagramHandleResult
    {
        $normalized = $this->normalize($username);
        if ($normalized === null) {
            return InstagramHandleResult::error($username);
        }

        return Cache::remember(
            "ig-handle:{$normalized}",
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetchAndParse($normalized),
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

    private function fetchAndParse(string $username): InstagramHandleResult
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
            // 429 / 5xx / login wall — treat as transient error so the
            // frontend doesn't show a false "not found" verdict.
            return InstagramHandleResult::error($username);
        }

        $html = $response->body();
        $ogTitle = $this->extractMeta($html, 'og:title');

        // Instagram's soft-404 also returns 200 but with a "Page Not Found"
        // og:title — catch that branch explicitly.
        if ($ogTitle === null || str_contains(strtolower($ogTitle), 'page not found')) {
            return InstagramHandleResult::notFound($username);
        }

        $description = $this->extractMeta($html, 'og:description')
            ?? $this->extractMetaName($html, 'description');

        return new InstagramHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $this->parseDisplayName($ogTitle, $username),
            profilePicUrl: $this->extractMeta($html, 'og:image'),
            followerCount: $this->parseFollowerCount($description),
            isBusiness:    $this->detectBusinessHint($html),
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

    private function extractMetaName(string $html, string $name): ?string
    {
        $quoted = preg_quote($name, '/');
        if (preg_match(
            '/<meta\s+name=["\']' . $quoted . '["\']\s+content=["\']([^"\']+)["\']/i',
            $html,
            $m,
        )) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }
        return null;
    }

    /**
     * og:title is typically "Display Name (@username) • Instagram photos and videos"
     * or "@username • Instagram photos and videos" if no display name set.
     */
    private function parseDisplayName(string $ogTitle, string $username): ?string
    {
        // Strip the trailing " • Instagram..." suffix.
        $stripped = preg_replace('/\s+[•·]\s+Instagram.*$/u', '', $ogTitle) ?? $ogTitle;
        // Pull out "Display Name" from "Display Name (@username)".
        if (preg_match('/^(.+?)\s*\(@' . preg_quote($username, '/') . '\)\s*$/u', $stripped, $m)) {
            $name = trim($m[1]);
            return $name !== '' ? $name : null;
        }
        return null;
    }

    /**
     * Description usually reads "5,432 Followers, 123 Following, 89 Posts — ..."
     * Both en-US and id-ID locales use the same number-then-word pattern with
     * comma/dot thousand separators.
     */
    private function parseFollowerCount(?string $description): ?int
    {
        if ($description === null) {
            return null;
        }
        if (preg_match('/([\d,.]+)\s+Followers/i', $description, $m)) {
            $digits = preg_replace('/[^\d]/', '', $m[1]);
            return $digits === '' || $digits === null ? null : (int) $digits;
        }
        return null;
    }

    /**
     * IG's bundled SSR JSON contains "is_business_account":true on creator/
     * business accounts. Best-effort hint only; null when unknown.
     */
    private function detectBusinessHint(string $html): ?bool
    {
        if (preg_match('/"is_business_account":\s*(true|false)/', $html, $m)) {
            return $m[1] === 'true';
        }
        return null;
    }
}
