<?php

declare(strict_types=1);

namespace App\Services\Fetchers;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a website and extracts text content and key meta tags.
 * Returns null on any failure — callers treat missing data gracefully.
 * Result is cached by URL hash for 24 h to avoid re-fetching within a session.
 */
final class WebsiteFetcher
{
    private const TIMEOUT_SECONDS  = 8;
    private const MAX_REDIRECTS    = 3;
    private const TEXT_LIMIT_CHARS = 4000;
    private const CACHE_TTL        = 86400; // 24 h
    private const USER_AGENT       = 'NemaPlatformBot/1.0 (+https://nema.creativeapq.online)';

    /**
     * @return array{
     *     text_content: string,
     *     og_image_url: string|null,
     *     favicon_url: string|null,
     *     meta_description: string|null,
     * }|null
     */
    public function fetch(string $url): ?array
    {
        if (trim($url) === '') {
            return null;
        }

        return Cache::remember(
            'website_fetch:' . md5($url),
            self::CACHE_TTL,
            fn () => $this->doFetch($url),
        );
    }

    private function doFetch(string $url): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->withOptions([
                    'allow_redirects' => ['max' => self::MAX_REDIRECTS],
                    'verify'          => true,
                ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            return $this->parse($response->body(), $url);

        } catch (\Throwable) {
            // timeout, SSL cert error, connection refused — all treated as missing
            return null;
        }
    }

    private function parse(string $html, string $baseUrl): array
    {
        $doc = new DOMDocument();
        // Suppress malformed HTML warnings; force UTF-8 so loadHTML doesn't mangle it
        @$doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        $xpath = new DOMXPath($doc);

        return [
            'text_content'    => $this->extractText($xpath),
            'og_image_url'    => $this->extractMeta($xpath, 'property', 'og:image', 'content'),
            'favicon_url'     => $this->extractFavicon($xpath, $baseUrl),
            'meta_description' => $this->extractMeta($xpath, 'name', 'description', 'content'),
        ];
    }

    private function extractText(DOMXPath $xpath): string
    {
        // Remove noise elements before collecting text
        $noiseQuery = '//script|//style|//nav|//footer|//header|//aside|//noscript|//iframe';
        foreach ($xpath->query($noiseQuery) ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $bodies = $xpath->query('//body');
        if ($bodies === false || $bodies->length === 0) {
            return '';
        }

        $raw = $bodies->item(0)->textContent ?? '';

        // Collapse whitespace runs to single spaces
        $clean = (string) preg_replace('/\s+/', ' ', trim($raw));

        return mb_substr($clean, 0, self::TEXT_LIMIT_CHARS);
    }

    private function extractMeta(DOMXPath $xpath, string $attr, string $value, string $returnAttr): ?string
    {
        $nodes = $xpath->query("//meta[@{$attr}='{$value}']");
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $content = $nodes->item(0)->attributes?->getNamedItem($returnAttr)?->nodeValue;

        return ($content !== null && $content !== '') ? $content : null;
    }

    private function extractFavicon(DOMXPath $xpath, string $baseUrl): ?string
    {
        $nodes = $xpath->query('//link[contains(@rel, "icon")]');
        if ($nodes !== false && $nodes->length > 0) {
            $href = $nodes->item(0)->attributes?->getNamedItem('href')?->nodeValue;
            if ($href !== null && $href !== '') {
                // Resolve relative paths
                if (str_starts_with($href, 'http')) {
                    return $href;
                }
                $parsed = parse_url($baseUrl);
                $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

                return $origin . '/' . ltrim($href, '/');
            }
        }

        // Fall back to conventional location
        $parsed = parse_url($baseUrl);
        $origin = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');

        return $origin !== '://' ? $origin . '/favicon.ico' : null;
    }
}
