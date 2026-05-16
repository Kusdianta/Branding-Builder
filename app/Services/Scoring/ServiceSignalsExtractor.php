<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Services\ClaudeService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB74 — Phase 11 service-signals extractor.
 *
 * Walks a brand's evidence layer (IG bio / captions / highlights, GMaps
 * reviews, website metadata, Places API attributes) and the operator's
 * self-reported declarations, then attributes confidence-scored signals
 * for the Brand Experience bonus sub-buckets BB75 ExperienceScorer
 * consumes (ekspres, antar_jemput, SOP keluhan, price list,
 * variasi_layanan).
 *
 * Hybrid architecture (per operator decision Concern #2):
 *
 *   Stage 1 — pure-PHP regex over each evidence source with
 *             source-weighted * specificity-weighted confidence:
 *             source_weight * specificity_factor → per-source score;
 *             aggregate confidence = max across sources.
 *
 *   Stage 2 — single batched Claude haiku call ONLY for signals whose
 *             aggregate confidence lands in the ambiguous band
 *             [AMBIGUOUS_LOW, AMBIGUOUS_HIGH]. Signals below the
 *             band are not_detected; signals above are detected.
 *             Stage 2 is skipped entirely when no signal is ambiguous.
 *
 * Returned signal payload (per the BB74 spec):
 *
 *   {
 *     "bonus_ekspres":      { detected, confidence, primary_source,
 *                              sources:[{source, snippet, weight,
 *                                         specificity, score}, ...],
 *                              verified_by_llm },
 *     "bonus_antar_jemput": { ... },
 *     "bonus_sop_keluhan":  { ... },
 *     "bonus_price_list":   { ... },
 *     "variasi_layanan":    { detected_variants: list<string>,
 *                              sources: { <variant>: [...] },
 *                              verified_by_llm }
 *   }
 *
 * Service contract is never-raise; LLM failures degrade Stage 2 to a
 * no-op (signals keep their Stage 1 scores; verified_by_llm=false).
 */
class ServiceSignalsExtractor
{
    // ── Source weights (per operator decision) ───────────────────────
    public const SOURCE_OPERATOR_DECLARATION = 'operator_declaration';
    public const SOURCE_IG_HIGHLIGHT_NAME    = 'ig_highlight_name';
    public const SOURCE_PLACES_API_ATTRIBUTE = 'places_api_attribute';
    public const SOURCE_IG_BIO               = 'ig_bio';
    public const SOURCE_WEBSITE_META         = 'website_meta';
    public const SOURCE_REVIEW_MENTION       = 'review_mention';
    public const SOURCE_IG_CAPTION           = 'ig_caption_snippet';

    private const SOURCE_WEIGHTS = [
        self::SOURCE_OPERATOR_DECLARATION => 1.0,
        self::SOURCE_IG_HIGHLIGHT_NAME    => 0.95,
        self::SOURCE_PLACES_API_ATTRIBUTE => 0.9,
        self::SOURCE_IG_BIO               => 0.85,
        self::SOURCE_WEBSITE_META         => 0.8,
        self::SOURCE_REVIEW_MENTION       => 0.7,
        self::SOURCE_IG_CAPTION           => 0.6,
    ];

    // ── Specificity factors ──────────────────────────────────────────
    private const SPEC_EXACT_PHRASE = 'exact_phrase';
    private const SPEC_PARTIAL      = 'partial';
    private const SPEC_FUZZY_TOKEN  = 'fuzzy_token';

    private const SPEC_FACTORS = [
        self::SPEC_EXACT_PHRASE => 1.0,
        self::SPEC_PARTIAL      => 0.7,
        self::SPEC_FUZZY_TOKEN  => 0.5,
    ];

    // ── Confidence bands ─────────────────────────────────────────────
    public const AMBIGUOUS_LOW  = 0.4;
    public const AMBIGUOUS_HIGH = 0.7;

    // ── Keyword clusters ─────────────────────────────────────────────
    // Exact phrases get specificity=exact_phrase, single-word stems get
    // specificity=fuzzy_token, multi-word fragments get partial. The
    // service tests against lowercased haystacks so patterns stay short.
    private const KEYWORDS = [
        'bonus_ekspres' => [
            self::SPEC_EXACT_PHRASE => [
                'layanan ekspres', 'same day', 'same-day', 'kilat 2 jam',
                'kilat 3 jam', 'ekspres 24 jam', 'cuci kilat', 'super kilat',
            ],
            self::SPEC_PARTIAL => [
                'ekspres', 'express', 'kilat', '2 jam', '3 jam',
                'instant laundry', 'fast laundry',
            ],
            self::SPEC_FUZZY_TOKEN => [
                'cepat', 'fast',
            ],
        ],
        'bonus_antar_jemput' => [
            self::SPEC_EXACT_PHRASE => [
                'antar jemput', 'antar-jemput', 'pickup gratis', 'pick up gratis',
                'gratis antar', 'free pickup', 'free delivery',
            ],
            self::SPEC_PARTIAL => [
                'pickup', 'pick-up', 'pick up', 'delivery', 'kurir',
                'diantar', 'dijemput', 'ojek',
            ],
            self::SPEC_FUZZY_TOKEN => [
                'jemput', 'antar',
            ],
        ],
        'bonus_sop_keluhan' => [
            self::SPEC_EXACT_PHRASE => [
                'sop keluhan', 'kebijakan keluhan', 'ganti rugi',
                'garansi kepuasan', 'jaminan kepuasan', 'satisfaction guarantee',
                'kompensasi', 'refund policy',
            ],
            self::SPEC_PARTIAL => [
                'jaminan', 'garansi', 'guarantee', 'refund',
                'kebijakan', 'sop',
            ],
            self::SPEC_FUZZY_TOKEN => [
                'keluhan', 'complaint', 'faq',
            ],
        ],
        'bonus_price_list' => [
            self::SPEC_EXACT_PHRASE => [
                'daftar harga', 'price list', 'pricelist', 'list harga',
                'tarif lengkap', 'harga mulai',
            ],
            self::SPEC_PARTIAL => [
                'tarif', 'harga', 'price', 'biaya',
            ],
            self::SPEC_FUZZY_TOKEN => [
                'rp ', 'rp.', '/kg', 'per kilo', 'per kg',
            ],
        ],
    ];

    // Variasi layanan — multi-value: each variant has its own keyword set.
    private const VARIANT_KEYWORDS = [
        'kiloan'       => ['kiloan', 'per kilo', 'per kg', 'kilogram'],
        'satuan'       => ['satuan', 'per item', 'per piece', 'item by item'],
        'dry_cleaning' => ['dry cleaning', 'dry-cleaning', 'drycleaning', 'cuci kering'],
        'sepatu'       => ['cuci sepatu', 'shoe laundry', 'shoe cleaning', 'sepatu'],
        'karpet'       => ['cuci karpet', 'karpet', 'carpet cleaning'],
        'bedding'      => ['bedding', 'sprei', 'selimut', 'bed cover', 'bantal'],
        'gaun_dress'   => ['gaun', 'dress', 'kebaya', 'wedding dress'],
        'boneka'       => ['boneka', 'doll cleaning', 'cuci boneka'],
    ];

    public function __construct(
        private readonly ClaudeService $claude,
    ) {}

    /**
     * Run Stage 1 + (conditional) Stage 2 against the evidence.
     *
     * @param array<string,mixed> $evidence              audit_evidence column
     * @param array<string,mixed>|null $operatorDecls    operator_declarations column
     * @param bool $useLlmBand                            disable Stage 2 (tests, smoke runs)
     * @return array<string,mixed>                       service_signals payload
     */
    public function extract(
        array $evidence,
        ?array $operatorDecls,
        bool $useLlmBand = true,
    ): array {
        $signals = [
            'bonus_ekspres'      => $this->emptySignal(),
            'bonus_antar_jemput' => $this->emptySignal(),
            'bonus_sop_keluhan'  => $this->emptySignal(),
            'bonus_price_list'   => $this->emptySignal(),
        ];

        $this->ingestOperatorDeclarations($signals, $operatorDecls);
        $this->ingestInstagramAnalysis($signals, $evidence);
        $this->ingestGMapsReviews($signals, $evidence);
        $this->ingestWebsite($signals, $evidence);
        $this->ingestPlacesApi($signals, $evidence);

        // Resolve detected/confidence after all sources contribute.
        foreach ($signals as $key => $payload) {
            $signals[$key] = $this->resolveSignal($payload);
        }

        // Variasi layanan is structurally different — multi-value detection.
        $variasi = $this->extractVariasi($evidence, $operatorDecls);

        // Stage 2 — single Claude call for ambiguous signals only.
        $ambiguous = array_filter(
            $signals,
            fn ($s) => $s['confidence'] >= self::AMBIGUOUS_LOW
                   && $s['confidence'] <= self::AMBIGUOUS_HIGH,
        );

        if ($useLlmBand && $ambiguous !== []) {
            try {
                $verification = $this->claude->verifyServiceSignals($ambiguous);
                foreach ($verification as $key => $verdict) {
                    if (! isset($signals[$key])) {
                        continue;
                    }
                    $signals[$key] = $this->applyLlmVerdict(
                        $signals[$key],
                        (bool) ($verdict['detected'] ?? false),
                        (string) ($verdict['reasoning'] ?? ''),
                    );
                }
            } catch (Throwable $e) {
                Log::warning('ServiceSignalsExtractor: Stage 2 LLM call failed; keeping Stage 1 scores', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $signals['variasi_layanan'] = $variasi;
        return $signals;
    }

    /** @return array<string,mixed> empty signal scaffolding */
    private function emptySignal(): array
    {
        return [
            'detected'        => false,
            'confidence'      => 0.0,
            'primary_source'  => null,
            'sources'         => [],
            'verified_by_llm' => false,
        ];
    }

    /**
     * Operator declarations contribute at source_weight=1.0,
     * specificity=exact_phrase (the operator literally typed a yes).
     */
    private function ingestOperatorDeclarations(array &$signals, ?array $decls): void
    {
        if ($decls === null) {
            return;
        }
        $map = [
            'has_ekspres'      => ['key' => 'bonus_ekspres',      'urlKey' => 'ekspres_url',      'snippet' => 'Operator menyatakan layanan ekspres tersedia.'],
            'has_antar_jemput' => ['key' => 'bonus_antar_jemput', 'urlKey' => 'antar_jemput_url', 'snippet' => 'Operator menyatakan layanan antar jemput tersedia.'],
            'has_sop_keluhan'  => ['key' => 'bonus_sop_keluhan',  'urlKey' => 'sop_keluhan_url',  'snippet' => 'Operator menyatakan SOP keluhan dipublikasikan.'],
            'has_price_list'   => ['key' => 'bonus_price_list',   'urlKey' => 'price_list_url',   'snippet' => 'Operator menyatakan price list publik tersedia.'],
        ];
        foreach ($map as $declKey => $cfg) {
            if (($decls[$declKey] ?? null) !== true) {
                continue;
            }
            $url = (string) ($decls[$cfg['urlKey']] ?? '');
            $this->addSource($signals[$cfg['key']], [
                'source'      => self::SOURCE_OPERATOR_DECLARATION,
                'snippet'     => $cfg['snippet'] . ($url !== '' ? " URL: {$url}" : ''),
                'weight'      => self::SOURCE_WEIGHTS[self::SOURCE_OPERATOR_DECLARATION],
                'specificity' => self::SPEC_EXACT_PHRASE,
                'score'       => self::SOURCE_WEIGHTS[self::SOURCE_OPERATOR_DECLARATION]
                                  * self::SPEC_FACTORS[self::SPEC_EXACT_PHRASE],
            ]);
        }
    }

    /**
     * IG analysis writes three signal-bearing text streams:
     *   - profile_branding.bio
     *   - content_analysis.* (caption snippets aggregated)
     *   - highlights[].name (when surfaced)
     */
    private function ingestInstagramAnalysis(array &$signals, array $evidence): void
    {
        $analysis = (array) ($evidence['instagram_analysis'] ?? []);

        // Bio
        $bio = (string) ($analysis['profile_branding']['bio'] ?? '');
        if ($bio === '') {
            $bio = (string) (($evidence['instagram_audit']['bio'] ?? '') ?: '');
        }
        if ($bio !== '') {
            $this->scanText($signals, $bio, self::SOURCE_IG_BIO);
        }

        // Highlight names — from analysis._meta if present, else raw slice
        $highlightNames = (array) (
            $analysis['_meta']['highlight_names']
            ?? $evidence['instagram_audit']['highlight_names']
            ?? []
        );
        foreach ($highlightNames as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            $this->scanText($signals, $name, self::SOURCE_IG_HIGHLIGHT_NAME);
        }

        // Captions — multiple sources in the analysis JSON. Best-effort
        // text aggregation; fall back to recent_posts captions if the
        // analysis layer didn't expose them.
        $captionsText = $this->joinCaptions($analysis);
        if ($captionsText !== '') {
            $this->scanText($signals, $captionsText, self::SOURCE_IG_CAPTION);
        }
    }

    private function joinCaptions(array $analysis): string
    {
        $parts = [];
        // The Phase 7-B analysis output sometimes embeds captions under
        // content_analysis.examples or .highlights — match liberally.
        $ca = (array) ($analysis['content_analysis'] ?? []);
        foreach (['examples', 'top_posts', 'recent_captions'] as $sub) {
            foreach ((array) ($ca[$sub] ?? []) as $entry) {
                if (is_string($entry)) {
                    $parts[] = $entry;
                } elseif (is_array($entry)) {
                    $parts[] = (string) ($entry['caption'] ?? $entry['text'] ?? '');
                }
            }
        }
        return implode("\n", array_filter($parts, fn ($s) => trim((string) $s) !== ''));
    }

    private function ingestGMapsReviews(array &$signals, array $evidence): void
    {
        $reviews = (array) ($evidence['gmaps_scrape']['reviews'] ?? []);
        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
            $text = (string) ($review['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $this->scanText($signals, $text, self::SOURCE_REVIEW_MENTION);
        }
    }

    private function ingestWebsite(array &$signals, array $evidence): void
    {
        $website = (array) ($evidence['website'] ?? []);
        if (($website['error'] ?? null) !== null) {
            return; // Worker error — nothing to scan.
        }

        // BB77 returns 4 booleans pre-computed plus body excerpt. Use
        // the booleans as exact-phrase hits (they were keyword-detected
        // in the page text already) plus scan the excerpt for any
        // signals the booleans don't cover.
        $bookkeeping = [
            'has_pricing_keywords' => [
                'signal_keys' => ['bonus_price_list'],
                'snippet'     => 'Pricing keywords detected on website',
            ],
            'has_pickup_keywords' => [
                'signal_keys' => ['bonus_antar_jemput'],
                'snippet'     => 'Pickup/delivery keywords detected on website',
            ],
            'has_express_keywords' => [
                'signal_keys' => ['bonus_ekspres'],
                'snippet'     => 'Express keywords detected on website',
            ],
            'has_complaint_policy_keywords' => [
                'signal_keys' => ['bonus_sop_keluhan'],
                'snippet'     => 'Complaint/policy keywords detected on website',
            ],
        ];
        foreach ($bookkeeping as $flag => $cfg) {
            if (! ($website[$flag] ?? false)) {
                continue;
            }
            foreach ($cfg['signal_keys'] as $signalKey) {
                $this->addSource($signals[$signalKey], [
                    'source'      => self::SOURCE_WEBSITE_META,
                    'snippet'     => $cfg['snippet'],
                    'weight'      => self::SOURCE_WEIGHTS[self::SOURCE_WEBSITE_META],
                    'specificity' => self::SPEC_PARTIAL,
                    'score'       => self::SOURCE_WEIGHTS[self::SOURCE_WEBSITE_META]
                                      * self::SPEC_FACTORS[self::SPEC_PARTIAL],
                ]);
            }
        }

        // Also scan title/meta/h1/excerpt for additional specificity.
        $haystack = trim(implode("\n", array_filter([
            (string) ($website['title'] ?? ''),
            (string) ($website['meta_description'] ?? ''),
            (string) ($website['h1_text'] ?? ''),
            implode(' ', (array) ($website['h2_texts'] ?? [])),
            (string) ($website['body_excerpt'] ?? ''),
        ])));
        if ($haystack !== '') {
            $this->scanText($signals, $haystack, self::SOURCE_WEBSITE_META);
        }
    }

    private function ingestPlacesApi(array &$signals, array $evidence): void
    {
        $places = (array) ($evidence['places_api'] ?? []);
        $attrs  = (array) ($places['attributes'] ?? []);
        // Google Places API "attributes" sometimes carries booleans
        // like delivery / takeout / curbside_pickup. Treat presence as
        // a strong signal for antar_jemput.
        $pickupAttrs = ['delivery', 'curbside_pickup', 'takeout', 'pickup'];
        foreach ($pickupAttrs as $attr) {
            if (! empty($attrs[$attr])) {
                $this->addSource($signals['bonus_antar_jemput'], [
                    'source'      => self::SOURCE_PLACES_API_ATTRIBUTE,
                    'snippet'     => "Places API attribute: {$attr}",
                    'weight'      => self::SOURCE_WEIGHTS[self::SOURCE_PLACES_API_ATTRIBUTE],
                    'specificity' => self::SPEC_EXACT_PHRASE,
                    'score'       => self::SOURCE_WEIGHTS[self::SOURCE_PLACES_API_ATTRIBUTE]
                                      * self::SPEC_FACTORS[self::SPEC_EXACT_PHRASE],
                ]);
                break; // One attribute is enough.
            }
        }
    }

    /**
     * Scan a free-text haystack against every signal's keyword clusters
     * at every specificity tier. First match per (signal, source) wins
     * — additional matches don't add more sources from the same origin,
     * because aggregate confidence is max-not-sum.
     */
    private function scanText(array &$signals, string $haystack, string $source): void
    {
        $lower = mb_strtolower($haystack);
        foreach (self::KEYWORDS as $signalKey => $tiers) {
            // Skip if we already have a source from this origin for
            // this signal — keep the highest specificity that already
            // matched.
            if ($this->hasSourceFromOrigin($signals[$signalKey], $source)) {
                continue;
            }
            foreach ($tiers as $specificity => $patterns) {
                $matched = $this->firstMatch($lower, $patterns);
                if ($matched === null) {
                    continue;
                }
                $weight = self::SOURCE_WEIGHTS[$source];
                $factor = self::SPEC_FACTORS[$specificity];
                $this->addSource($signals[$signalKey], [
                    'source'      => $source,
                    'snippet'     => $this->snippetAround($haystack, $matched),
                    'weight'      => $weight,
                    'specificity' => $specificity,
                    'score'       => $weight * $factor,
                ]);
                break;
            }
        }
    }

    /**
     * Variasi layanan: scan the same source set for each variant.
     * Combines hits + operator-declared variants into a deduped list
     * with per-variant source trails for BB76 transparency.
     */
    private function extractVariasi(array $evidence, ?array $operatorDecls): array
    {
        $perVariant = [];
        foreach (array_keys(self::VARIANT_KEYWORDS) as $variant) {
            $perVariant[$variant] = [];
        }

        // Operator-declared variants
        if ($operatorDecls !== null) {
            foreach ((array) ($operatorDecls['service_variants'] ?? []) as $variant) {
                if (is_string($variant) && isset($perVariant[$variant])) {
                    $perVariant[$variant][] = [
                        'source'  => self::SOURCE_OPERATOR_DECLARATION,
                        'snippet' => 'Operator menyatakan variasi tersedia.',
                    ];
                }
            }
        }

        // IG bio + analysis text + GMaps reviews + website meta
        $bio = (string) (
            ($evidence['instagram_analysis']['profile_branding']['bio'] ?? '')
            ?: ($evidence['instagram_audit']['bio'] ?? '')
        );
        $reviewsText = implode("\n", array_map(
            static fn ($r) => is_array($r) ? (string) ($r['text'] ?? '') : '',
            (array) ($evidence['gmaps_scrape']['reviews'] ?? []),
        ));
        $websiteText = trim(implode("\n", array_filter([
            (string) ($evidence['website']['title'] ?? ''),
            (string) ($evidence['website']['meta_description'] ?? ''),
            (string) ($evidence['website']['h1_text'] ?? ''),
            (string) ($evidence['website']['body_excerpt'] ?? ''),
        ])));

        $sources = array_filter([
            self::SOURCE_IG_BIO         => $bio,
            self::SOURCE_REVIEW_MENTION => $reviewsText,
            self::SOURCE_WEBSITE_META   => $websiteText,
        ]);

        foreach ($sources as $source => $text) {
            $lower = mb_strtolower($text);
            foreach (self::VARIANT_KEYWORDS as $variant => $patterns) {
                if ($perVariant[$variant] !== [] && $this->hasSourceFromOrigin([
                    'sources' => $perVariant[$variant],
                ], $source)) {
                    continue;
                }
                $matched = $this->firstMatch($lower, $patterns);
                if ($matched === null) {
                    continue;
                }
                $perVariant[$variant][] = [
                    'source'  => $source,
                    'snippet' => $this->snippetAround($text, $matched),
                ];
            }
        }

        $detected = array_values(array_filter(
            array_keys($perVariant),
            static fn ($v) => $perVariant[$v] !== [],
        ));

        return [
            'detected_variants' => $detected,
            'sources'           => $perVariant,
            'verified_by_llm'   => false,
        ];
    }

    private function addSource(array &$signal, array $contribution): void
    {
        $signal['sources'][] = $contribution;
    }

    private function hasSourceFromOrigin(array $signal, string $source): bool
    {
        foreach ($signal['sources'] ?? [] as $s) {
            if (($s['source'] ?? null) === $source) {
                return true;
            }
        }
        return false;
    }

    /**
     * After all sources land, compute aggregate confidence + flip the
     * detected/primary_source fields. If aggregate is in the ambiguous
     * band, leave detected=false; Stage 2 may overturn that.
     */
    private function resolveSignal(array $signal): array
    {
        $maxScore = 0.0;
        $primary  = null;
        foreach ($signal['sources'] as $s) {
            $score = (float) ($s['score'] ?? 0);
            if ($score > $maxScore) {
                $maxScore = $score;
                $primary  = (string) ($s['source'] ?? '');
            }
        }
        $signal['confidence']     = round($maxScore, 3);
        $signal['primary_source'] = $primary;
        $signal['detected']       = $maxScore >= self::AMBIGUOUS_HIGH;
        return $signal;
    }

    private function applyLlmVerdict(array $signal, bool $detected, string $reasoning): array
    {
        $signal['verified_by_llm']  = true;
        $signal['detected']         = $detected;
        $signal['llm_reasoning']    = $reasoning;
        // Don't overwrite Stage 1 confidence; tier classifier (BB75)
        // treats verified_by_llm+detected as authoritative regardless.
        return $signal;
    }

    /**
     * @param list<string> $patterns
     */
    private function firstMatch(string $haystackLower, array $patterns): ?string
    {
        foreach ($patterns as $needle) {
            $needleLower = mb_strtolower($needle);
            if ($needleLower !== '' && str_contains($haystackLower, $needleLower)) {
                return $needle;
            }
        }
        return null;
    }

    private function snippetAround(string $haystack, string $matched): string
    {
        $pos = mb_stripos($haystack, $matched);
        if ($pos === false) {
            return mb_substr($haystack, 0, 120);
        }
        $start = max(0, $pos - 40);
        $end   = min(mb_strlen($haystack), $pos + mb_strlen($matched) + 80);
        $snippet = mb_substr($haystack, $start, $end - $start);
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($end < mb_strlen($haystack)) {
            $snippet = $snippet . '…';
        }
        return trim($snippet);
    }
}
