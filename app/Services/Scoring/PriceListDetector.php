<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use Anthropic\Client as AnthropicClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 12c.2-rubric-alignment BB114 — Price-list detection.
 *
 * Two-stage detection, deterministic-first to keep costs near zero
 * on the common case (caption text is usually enough):
 *
 *   Stage 1 — Caption scan. Look for Indonesian price-list keywords
 *             across IG captions. If we see two or more hits, the
 *             brand transparently lists prices and we short-circuit
 *             at confidence 0.9. Free, deterministic, fast.
 *
 *   Stage 2 — Vision. When the caption scan returns < 2 hits, send
 *             up to six Places API photo URLs to Claude Haiku 4.5
 *             vision with a tight Indonesian prompt asking for
 *             readable rupiah / per-kg / tariff signage. Only IG
 *             image vision is skipped — those URLs require
 *             authenticated fetch via the worker, which costs an
 *             extra request hop we avoid in v1.
 *
 * Failure handling: every step has a graceful fallback. If the
 * Anthropic call errors, the caption result wins. If both fail,
 * detected = false, confidence = 0, method = 'fallback', and the
 * source attribution surfaces the unavailability honestly.
 *
 * Cost: ~$0.011 per audit at Haiku 4.5 rates (6 images × ~1500
 * input tokens + ~300 output tokens). Caption-only path costs $0.
 */
final class PriceListDetector
{
    private const VISION_MODEL = 'claude-haiku-4-5-20251001';
    private const MAX_IMAGES   = 6;
    private const MAX_CAPTIONS = 20;
    private const FETCH_TIMEOUT = 8;

    private const PRICE_KEYWORDS = [
        'harga', 'tarif', 'price list', 'pricelist', 'daftar harga',
        'rp ', 'rp.', 'rp/kg', '/kg', 'per kg', 'per kilo',
        'paket harga', 'biaya layanan',
    ];

    public function __construct(private readonly ?AnthropicClient $anthropic = null)
    {
    }

    /**
     * @param list<string>             $photoUrls
     * @param list<string>             $captions
     * @return array{
     *   detected: bool,
     *   confidence: float,
     *   method: string,
     *   evidence: list<array<string,mixed>>,
     *   source: string,
     *   unavailable_reason: string|null,
     * }
     */
    public function detect(array $photoUrls, array $captions): array
    {
        $captionEvidence = $this->scanCaptions($captions);

        if (count($captionEvidence) >= 2) {
            return [
                'detected'           => true,
                'confidence'         => 0.9,
                'method'             => 'caption_only',
                'evidence'           => array_slice($captionEvidence, 0, 3),
                'source'             => 'Sumber: scan keyword "harga / tarif / Rp / per kg" di caption Instagram',
                'unavailable_reason' => null,
            ];
        }

        // Caption hits are inconclusive (< 2). Try vision on public
        // Places API photos. IG photos are skipped here because they
        // sit behind authenticated CDN URLs we cannot fetch from PHP.
        $visionResult = $this->visionDetect(
            array_slice($photoUrls, 0, self::MAX_IMAGES),
        );

        if ($visionResult === null) {
            // Vision failed or skipped — fall back to caption result.
            if ($captionEvidence !== []) {
                return [
                    'detected'           => true,
                    'confidence'         => 0.55,
                    'method'             => 'caption_only_partial',
                    'evidence'           => $captionEvidence,
                    'source'             => 'Sumber: scan keyword "harga / tarif / Rp / per kg" di caption Instagram',
                    'unavailable_reason' => 'Vision tidak dijalankan; deteksi mengandalkan caption saja.',
                ];
            }
            return [
                'detected'           => false,
                'confidence'         => 0.0,
                'method'             => 'fallback',
                'evidence'           => [],
                'source'             => 'Sumber: tidak tersedia (caption + vision keduanya gagal)',
                'unavailable_reason' => 'Tidak ada caption Instagram dengan keyword harga; vision photo tidak bisa dijalankan.',
            ];
        }

        $merged = [...$captionEvidence, ...$visionResult['evidence']];
        return [
            'detected'           => $visionResult['detected'] || $captionEvidence !== [],
            'confidence'         => $visionResult['confidence'],
            'method'             => $captionEvidence !== [] ? 'caption+vision' : 'vision',
            'evidence'           => array_slice($merged, 0, 5),
            'source'             => $captionEvidence !== []
                ? 'Sumber: caption Instagram + analisis AI foto Google Places'
                : 'Sumber: analisis AI foto Google Places',
            'unavailable_reason' => $visionResult['detected'] || $captionEvidence !== []
                ? null
                : 'Tidak terlihat daftar harga di caption Instagram maupun foto Google Places.',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function scanCaptions(array $captions): array
    {
        $hits = [];
        foreach (array_slice($captions, 0, self::MAX_CAPTIONS) as $caption) {
            $captionStr = is_string($caption) ? $caption : '';
            if ($captionStr === '') {
                continue;
            }
            $lower   = mb_strtolower($captionStr);
            $matched = array_values(array_filter(
                self::PRICE_KEYWORDS,
                fn (string $kw) => str_contains($lower, $kw),
            ));
            if ($matched === []) {
                continue;
            }
            $hits[] = [
                'source'  => 'caption',
                'snippet' => mb_substr($captionStr, 0, 200),
                'matched' => $matched,
            ];
        }
        return $hits;
    }

    /**
     * @param list<string> $photoUrls
     * @return array{detected: bool, confidence: float, evidence: list<array<string,mixed>>}|null
     */
    private function visionDetect(array $photoUrls): ?array
    {
        if ($photoUrls === [] || $this->anthropic === null) {
            return null;
        }

        $images = [];
        foreach ($photoUrls as $url) {
            $b64 = $this->fetchAndEncode($url);
            if ($b64 !== null) {
                $images[] = ['url' => $url, 'b64' => $b64];
            }
        }
        if ($images === []) {
            return null;
        }

        try {
            $content = [];
            foreach ($images as $img) {
                $content[] = [
                    'type'   => 'image',
                    'source' => [
                        'type'       => 'base64',
                        'media_type' => 'image/jpeg',
                        'data'       => $img['b64'],
                    ],
                ];
            }
            $content[] = [
                'type' => 'text',
                'text' => 'Apakah ada salah satu foto yang menampilkan daftar harga laundry yang terbaca (mata uang rupiah, tarif per kg, atau menu layanan)? '
                    . 'Jawab JSON saja, tanpa markdown: '
                    . '{"detected": true|false, "which_image_indexes": [0,1,...], "reasoning": "<satu kalimat bahasa Indonesia>"}',
            ];

            $response = $this->anthropic->messages()->create([
                'model'      => self::VISION_MODEL,
                'max_tokens' => 300,
                'messages'   => [
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

            $raw = $response->content[0]->text ?? '';
            $decoded = $this->extractJson($raw);
            if ($decoded === null) {
                Log::info('PriceListDetector vision returned unparseable text', [
                    'len' => strlen($raw),
                ]);
                return null;
            }

            $detected = (bool) ($decoded['detected'] ?? false);
            $hitIdx   = array_filter(
                (array) ($decoded['which_image_indexes'] ?? []),
                fn ($v) => is_int($v) || is_numeric($v),
            );
            $reasoning = (string) ($decoded['reasoning'] ?? '');

            $evidence = [];
            foreach ($hitIdx as $idx) {
                $i = (int) $idx;
                if (! isset($images[$i])) {
                    continue;
                }
                $evidence[] = [
                    'source'    => 'photo_vision',
                    'url'       => $images[$i]['url'],
                    'reasoning' => $reasoning,
                ];
            }
            if ($evidence === [] && $detected && $reasoning !== '') {
                // Model marked detected but didn't return image indexes;
                // surface its reasoning so the operator can audit.
                $evidence[] = ['source' => 'photo_vision', 'reasoning' => $reasoning];
            }

            return [
                'detected'   => $detected,
                'confidence' => $detected ? 0.85 : 0.7,
                'evidence'   => $evidence,
            ];
        } catch (Throwable $e) {
            Log::warning('PriceListDetector vision call failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fetchAndEncode(string $url): ?string
    {
        try {
            $res = Http::timeout(self::FETCH_TIMEOUT)->get($url);
        } catch (Throwable $e) {
            Log::info('PriceListDetector image fetch failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        if (! $res->successful()) {
            return null;
        }
        $body = $res->body();
        // Cap each image at ~1.5 MB to keep request payload reasonable
        // and avoid blowing the Anthropic per-message size limit.
        if (strlen($body) > 1_500_000) {
            return null;
        }
        return base64_encode($body);
    }

    /** @return array<string,mixed>|null */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        // Some replies wrap JSON in ```json fences despite the prompt
        // forbidding it. Strip the wrappers defensively.
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/i', '', $text) ?? $text;
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }
}
