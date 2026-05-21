<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\EvidenceItem;
use App\DTO\PillarScore;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\ClaudeService;

/**
 * Phase 10 Konsistensi scorer (BB54 stub -> BB57 vision implementation
 * -> BB117 v3 PPT-rubric rewrite).
 *
 * Two scoring paths, selected by ``$context['_wizard_version']``:
 *
 *   Legacy (v1/v2): visual-only 4-sub-bucket vision path (weighted color
 *     35% + typo 15% + logo 25% + imagery 25%). Fallback (no visual
 *     assets) caps at 60/100.
 *
 *   V3 (BB117): PPT-rubric 4-sub-bucket structure —
 *     kehadiran_digital (40, deterministic on touchpoint count) +
 *     konsistensi_visual (35, vision overall rescaled from 0-100 or 0
 *     in fallback) + kelengkapan_layanan (15, deterministic on
 *     touchpoints.service_types.variety_count) + transparansi_harga
 *     (10, deterministic on audit_evidence.price_list_detection).
 *     Total caps at 100.
 */
class KonsistensiScorer
{
    /** BB58: visual analysis required for a top-tier score (legacy path). */
    private const FALLBACK_SCORE_CAP = 60;

    public function __construct(
        private readonly ClaudeService $claude,
    ) {}

    /**
     * Score from the legacy ScorePillarsJob input shape (text + outlet photos).
     *
     * @param array<string,mixed> $inputs
     */
    public function score(array $inputs): PillarScore
    {
        return $this->claude->scorePillar(ScoringRubric::PILLAR_KONSISTENSI, $inputs);
    }

    /**
     * Phase 10 entry: select V3 vs legacy, then VISION vs FALLBACK
     * inside each.
     *
     * @param array<string,mixed> $evidence  audit_evidence column.
     * @param array<string,mixed> $context   per-audit context.
     */
    public function scoreFromEvidence(array $evidence, array $context): PillarScore
    {
        $version = (string) ($context['_wizard_version'] ?? BrandAudit::WIZARD_V1);

        return $version === BrandAudit::WIZARD_V3
            ? $this->scoreV3($evidence, $context)
            : $this->scoreLegacy($evidence, $context);
    }

    /**
     * Legacy v1/v2 path — unchanged from pre-BB117 behaviour.
     *
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $context
     */
    private function scoreLegacy(array $evidence, array $context): PillarScore
    {
        $assets = $this->collectVisualAssets($evidence, $context);

        if (count($assets['paths']) === 0) {
            return $this->scoreFallback($evidence, $context, $assets['data_source']);
        }

        return $this->scoreVision($evidence, $context, $assets);
    }

    /**
     * V3 (BB117) path — PPT-rubric 4-sub-bucket scoring.
     *
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $context
     */
    private function scoreV3(array $evidence, array $context): PillarScore
    {
        $brandName    = (string) ($context['brand_name'] ?? '');
        $varietyCount = (int) ($context['variety_count'] ?? 1);
        $priceList    = (array) ($evidence['price_list_detection'] ?? []);
        $priceDetected= (bool) ($priceList['detected'] ?? false);
        // Phase 12c.4 FIX 2 — Tier 1 user-declared signal from the
        // BB137 wizard checkbox. When the operator confirms a public
        // price list, that is treated as full evidence (10/10) — the
        // user-facing checkbox is a Tier 1 source per the rubric and
        // takes priority over AI auto-detection.
        $touchpointsOp = (array) ($context['touchpoints_operational'] ?? []);
        $priceDeclared = (bool) ($touchpointsOp['price_list'] ?? false);

        // Sub-bucket 1: kehadiran_digital (cap 40). Count of present
        // touchpoints across IG, website, GMaps, WhatsApp, TikTok.
        [$kehadiranScore, $kehadiranBreakdown] = $this->scoreKehadiranDigital($context);

        // Sub-bucket 2: konsistensi_visual (cap 35). Vision overall
        // 0-100 → rescale to 0-35. Fallback: 0 (honest unavailability).
        [$visualScore, $visualBreakdown, $visualEvidence] = $this->scoreKonsistensiVisualV3(
            $evidence,
            $context,
            $brandName,
        );

        // Sub-bucket 3: kelengkapan_layanan (cap 15). Variant count tier.
        [$kelengkapanScore, $kelengkapanBreakdown] = $this->scoreKelengkapanLayanan($varietyCount);

        // Sub-bucket 4: transparansi_harga (cap 10). PriceListDetector
        // detected flag (Tier 2) OR operator-declared checkbox (Tier
        // 1) — declaration alone qualifies for full 10 pts.
        [$transparansiScore, $transparansiBreakdown] = $this->scoreTransparansiHarga($priceList, $priceDeclared);

        $subBuckets = [
            'kehadiran_digital'   => $kehadiranScore,
            'konsistensi_visual'  => $visualScore,
            'kelengkapan_layanan' => $kelengkapanScore,
            'transparansi_harga'  => $transparansiScore,
        ];
        $total = max(0, min(100, array_sum($subBuckets)));

        // Phase 12c.4 FIX F — per-sub-bucket "why not full" reasoning
        // so the dashboard can always explain a less-than-cap score.
        // Visual reasoning comes from the vision LLM; the other three
        // are deterministic so we compose a one-liner from the inputs.
        $touchpointsPresent = (int) ($kehadiranBreakdown['raw_inputs']['count'] ?? 0);
        $subBucketReasoning = [
            'kehadiran_digital'   => $kehadiranScore >= 40
                ? ''
                : "Hanya {$touchpointsPresent} dari 5 touchpoint (Instagram, Website, Google Maps, WhatsApp, TikTok) yang terisi. Setiap touchpoint tambahan menambah 8 pt.",
            'kelengkapan_layanan' => $kelengkapanScore >= 15
                ? ''
                : "Variasi layanan yang dicatat operator: {$varietyCount}. Skor penuh aktif saat operator mencatat ≥ 4 variasi (mis. kiloan + satuan + cuci sepatu + dry cleaning).",
            'transparansi_harga'  => $transparansiScore >= 10
                ? ''
                : 'Operator belum mencentang "Daftar harga dipublikasikan" di wizard Step 3. Centang ketika daftar harga sudah ditampilkan di IG, foto outlet, atau website.',
        ];

        $breakdown = [
            'analysis_path'        => 'v3_ppt_rubric',
            'data_source'          => $this->v3DataSources($evidence, $context, $priceDetected),
            'sub_bucket_scores'    => $subBuckets,
            'sub_bucket_reasoning' => $subBucketReasoning,
            'kehadiran_digital'    => $kehadiranBreakdown,
            'konsistensi_visual'   => $visualBreakdown,
            'kelengkapan_layanan'  => $kelengkapanBreakdown,
            'transparansi_harga'   => $transparansiBreakdown,
            'brand_name'           => $brandName,
        ];

        $reasoning = sprintf(
            'Brand Konsistensi skor %d/100 — kehadiran digital %d/40, konsistensi visual %d/35, kelengkapan layanan %d/15, transparansi harga %d/10.',
            $total,
            $kehadiranScore,
            $visualScore,
            $kelengkapanScore,
            $transparansiScore,
        );

        return new PillarScore(
            pillarSlug:      ScoringRubric::PILLAR_KONSISTENSI,
            score:           $total,
            evidence:        $visualEvidence,
            reasoning:       $reasoning,
            subBucketScores: $subBuckets,
            scoreBreakdown:  $breakdown,
        );
    }

    /**
     * BB57: multimodal vision path (legacy).
     *
     * @param array{paths: list<string>, data_source: list<string>, vision_payload: array<string,mixed>} $assets
     */
    private function scoreVision(array $evidence, array $context, array $assets): PillarScore
    {
        $brandName = (string) ($context['brand_name'] ?? '');

        $visionResult = $this->claude->analyzeBrandConsistency(array_merge(
            $assets['vision_payload'],
            ['brand_name' => $brandName],
        ));

        return $this->hydrateVisionPillarScore($visionResult, $brandName, $assets['data_source']);
    }

    /**
     * BB58: fallback text-only path with score cap (legacy).
     *
     * @param list<string> $dataSource
     */
    private function scoreFallback(array $evidence, array $context, array $dataSource): PillarScore
    {
        $inputs = [
            'brand_name'         => (string) ($context['brand_name'] ?? ''),
            'outlet_photo_paths' => (array) ($context['outlet_photo_paths'] ?? []),
        ];

        foreach ([
            'instagram_url' => $context['instagram_url'] ?? null,
            'website_url'   => $context['website_url']   ?? null,
            'gmaps_url'     => $context['gmaps_url']     ?? null,
            'tiktok_url'    => $context['tiktok_url']    ?? null,
        ] as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $inputs[$key] = $value;
            }
        }

        if ((bool) ($context['whatsapp_business_active'] ?? false)) {
            $inputs['whatsapp_business_active'] = true;
        }

        $score = $this->score($inputs);

        $cappedScore = min(self::FALLBACK_SCORE_CAP, $score->score);

        $breakdown = $score->scoreBreakdown;
        $breakdown['data_source']          = $dataSource ?: ['touchpoint_urls'];
        $breakdown['analysis_path']        = 'fallback_text_only';
        $breakdown['score_pre_cap']        = $score->score;
        $breakdown['fallback_cap_applied'] = $score->score > self::FALLBACK_SCORE_CAP;

        $limitation = 'Analisis konsistensi visual tidak tersedia — fallback ke pemeriksaan touchpoint berbasis teks. Skor dibatasi maksimum '
            . self::FALLBACK_SCORE_CAP . '/100.';

        return new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_KONSISTENSI,
            score: $cappedScore,
            evidence: $score->evidence,
            reasoning: $score->reasoning . "\n\n" . $limitation,
            subBucketScores: $score->subBucketScores,
            scoreBreakdown: $breakdown,
        );
    }

    /**
     * V3 sub-bucket — kehadiran_digital (cap 40).
     *
     * @param array<string,mixed> $context
     * @return array{0:int,1:array<string,mixed>}
     */
    private function scoreKehadiranDigital(array $context): array
    {
        $present = [
            'instagram' => is_string($context['instagram_url'] ?? null) && trim((string) $context['instagram_url']) !== '',
            'website'   => is_string($context['website_url']   ?? null) && trim((string) $context['website_url'])   !== '',
            'gmaps'     => is_string($context['gmaps_url']     ?? null) && trim((string) $context['gmaps_url'])     !== '',
            'whatsapp'  => (bool) ($context['whatsapp_business_active'] ?? false),
            'tiktok'    => is_string($context['tiktok_url']    ?? null) && trim((string) $context['tiktok_url'])    !== '',
        ];
        $count = count(array_filter($present));

        $score = match ($count) {
            5       => 40,
            4       => 32,
            3       => 24,
            2       => 16,
            1       => 8,
            default => 0,
        };

        return [$score, [
            'score'      => $score,
            'cap'        => 40,
            'tier'       => $count >= 4 ? 'sangat baik' : ($count >= 2 ? 'cukup' : 'kurang'),
            'raw_inputs' => [
                'touchpoints_present' => $present,
                'count'               => $count,
                'source'              => 'Sumber: kelengkapan touchpoint dari form audit',
            ],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '5/5', 'points' => 40, 'matched' => $count === 5],
                ['range' => '4/5', 'points' => 32, 'matched' => $count === 4],
                ['range' => '3/5', 'points' => 24, 'matched' => $count === 3],
                ['range' => '2/5', 'points' => 16, 'matched' => $count === 2],
                ['range' => '1/5', 'points' => 8,  'matched' => $count === 1],
                ['range' => '0/5', 'points' => 0,  'matched' => $count === 0],
            ],
            'explanation_id' => 'kehadiran_digital_v3',
        ]];
    }

    /**
     * V3 sub-bucket — konsistensi_visual (cap 35). Reuses the existing
     * vision pipeline; rescales the 0-100 overall score down to 0-35.
     * Fallback when no visual assets are available: score 0 (rather
     * than the legacy 60-cap; v3 has 3 other deterministic sub-buckets
     * that still contribute even without vision).
     *
     * @return array{0:int,1:array<string,mixed>,2:list<EvidenceItem>}
     */
    private function scoreKonsistensiVisualV3(array $evidence, array $context, string $brandName): array
    {
        $assets = $this->collectVisualAssets($evidence, $context);
        if ($assets['paths'] === []) {
            return [0, [
                'score'      => 0,
                'cap'        => 35,
                'raw_inputs' => [
                    'source' => 'Sumber: analisis AI atas screenshot Instagram + website + Google Maps',
                ],
                'formula'           => 'graded_vision',
                'unavailable_reason'=> 'Tidak ada aset visual yang tersedia — Instagram screenshot, foto Google Maps, dan upload outlet semua kosong.',
                'analysis_path'     => 'no_assets',
                'explanation_id'    => 'konsistensi_visual_v3',
            ], []];
        }

        $vision = $this->claude->analyzeBrandConsistency(array_merge(
            $assets['vision_payload'],
            ['brand_name' => $brandName],
        ));

        $color  = (int) ($vision['color_consistency']['score']       ?? 50);
        $typo   = (int) ($vision['typography_consistency']['score']  ?? 50);
        $logo   = (int) ($vision['logo_consistency']['score']        ?? 50);
        $imager = (int) ($vision['imagery_tone']['score']            ?? 50);

        $overall100 = (int) round(($color * 0.35) + ($typo * 0.15) + ($logo * 0.25) + ($imager * 0.25));
        $score = (int) round($overall100 * 0.35);

        $subBucketReasoning = [
            'color_consistency'      => (string) ($vision['color_consistency']['observations']       ?? ''),
            'typography_consistency' => (string) ($vision['typography_consistency']['observations']  ?? ''),
            'logo_consistency'       => (string) ($vision['logo_consistency']['observations']        ?? ''),
            'imagery_tone'           => (string) ($vision['imagery_tone']['observations']            ?? ''),
        ];
        $evidenceItems = [];
        foreach ($subBucketReasoning as $bucket => $observation) {
            if ($observation === '') {
                continue;
            }
            $bucketScore = ['color_consistency' => $color, 'typography_consistency' => $typo, 'logo_consistency' => $logo, 'imagery_tone' => $imager][$bucket] ?? 50;
            $impact = $bucketScore >= 70
                ? EvidenceItem::IMPACT_POSITIVE
                : ($bucketScore <= 40 ? EvidenceItem::IMPACT_NEGATIVE : EvidenceItem::IMPACT_NEUTRAL);
            $evidenceItems[] = new EvidenceItem(
                touchpoint:  $bucket,
                observation: $observation,
                impact:      $impact,
            );
        }

        return [$score, [
            'score'      => $score,
            'cap'        => 35,
            'raw_inputs' => [
                'vision_overall_0_100' => $overall100,
                'rescaled_to_0_35'     => $score,
                'sub_signals'          => [
                    'color_consistency'      => $color,
                    'typography_consistency' => $typo,
                    'logo_consistency'       => $logo,
                    'imagery_tone'           => $imager,
                ],
                'source'        => 'Sumber: analisis AI atas screenshot Instagram + website + Google Maps',
                'data_sources'  => $assets['data_source'],
            ],
            'formula'              => 'graded_vision',
            'analysis_path'        => 'vision_multimodal',
            'sub_bucket_reasoning' => $subBucketReasoning,
            'touchpoints_analyzed' => (array) ($vision['touchpoints_analyzed'] ?? []),
            'limitations'          => (array) ($vision['limitations'] ?? []),
            'explanation_id'       => 'konsistensi_visual_v3',
        ], $evidenceItems];
    }

    /**
     * V3 sub-bucket — kelengkapan_layanan (cap 15). Deterministic on
     * variety_count (count of distinct service_types declared by the
     * operator in wizard Step 2).
     *
     * @return array{0:int,1:array<string,mixed>}
     */
    private function scoreKelengkapanLayanan(int $variety): array
    {
        $score = match (true) {
            $variety >= 4 => 15,
            $variety === 3 => 10,
            $variety === 2 => 5,
            default        => 0,
        };

        return [$score, [
            'score'      => $score,
            'cap'        => 15,
            'tier'       => $variety >= 4 ? 'sangat baik' : ($variety >= 2 ? 'cukup' : 'kurang'),
            'raw_inputs' => [
                'variety_count' => $variety,
                'source'        => 'Sumber: deklarasi operator (wizard Step 2 — variasi layanan)',
            ],
            'formula'    => 'deterministic_threshold',
            'tier_table' => [
                ['range' => '≥4 variasi', 'points' => 15, 'matched' => $variety >= 4],
                ['range' => '3 variasi',  'points' => 10, 'matched' => $variety === 3],
                ['range' => '2 variasi',  'points' => 5,  'matched' => $variety === 2],
                ['range' => '≤1 variasi', 'points' => 0,  'matched' => $variety <= 1],
            ],
            'explanation_id' => 'kelengkapan_layanan_v3',
        ]];
    }

    /**
     * V3 sub-bucket — transparansi_harga (cap 10).
     *
     * Phase 12c.4 FIX A (round 3) — Single source of truth: the
     * operator's wizard checkbox. AI auto-detection (PriceListDetector)
     * is no longer consulted — it caused false zeros for brands that
     * legitimately published prices but in formats the detector
     * couldn't recognise (handwritten signage, watermarked photos,
     * non-Indo keywords). The operator declaration is fast, reliable,
     * and a fair Tier 1 signal.
     *
     *   declared = true  → 10/10, tier "Dinyatakan oleh pemilik"
     *   declared = false → 0/10,  tier "Tidak dinyatakan"
     *
     * The legacy ``$priceList`` arg is kept (signature compat with
     * scoreV3 caller) but only its presence is surfaced in raw_inputs
     * for auditability — it does NOT influence the score.
     *
     * @param array<string,mixed> $priceList  legacy auto-detection payload (informational only)
     * @return array{0:int,1:array<string,mixed>}
     */
    private function scoreTransparansiHarga(array $priceList, bool $priceDeclared = false): array
    {
        $score = $priceDeclared ? 10 : 0;
        $tier  = $priceDeclared ? 'Dinyatakan oleh pemilik' : 'Tidak dinyatakan';

        return [$score, [
            'score'      => $score,
            'cap'        => 10,
            'tier'       => $tier,
            'raw_inputs' => [
                'declared' => $priceDeclared,
                'source'   => 'Sumber: deklarasi operator (wizard Step 3 — checkbox "Daftar harga dipublikasikan")',
            ],
            'formula'    => 'operator_declaration',
            'tier_table' => [
                ['range' => 'Dinyatakan oleh pemilik', 'points' => 10, 'matched' => $priceDeclared],
                ['range' => 'Tidak dinyatakan',        'points' => 0,  'matched' => ! $priceDeclared],
            ],
            'explanation_id' => 'transparansi_harga_v3',
        ]];
    }

    /** @return list<string> */
    private function v3DataSources(array $evidence, array $context, bool $priceDetected): array
    {
        $sources = ['touchpoint_urls'];
        if (! empty($evidence['instagram_audit'])) {
            $sources[] = 'instagram_audit';
        }
        if (! empty($evidence['gmaps_scrape'])) {
            $sources[] = 'gmaps_scrape';
        }
        if (! empty($evidence['places_api']['photos'] ?? [])) {
            $sources[] = 'places_api_photos';
        }
        if ($priceDetected) {
            $sources[] = 'price_list_detection';
        }
        return array_values(array_unique($sources));
    }

    /**
     * Collect available visual asset paths from evidence + context.
     *
     * @return array{paths: list<string>, data_source: list<string>, vision_payload: array<string,mixed>}
     */
    private function collectVisualAssets(array $evidence, array $context): array
    {
        $igRaw    = (array) ($evidence['instagram_audit'] ?? []);
        $gmaps    = (array) ($evidence['gmaps_scrape'] ?? []);
        $places   = (array) ($evidence['places_api'] ?? []);

        $igProfilePic = $igRaw['profile_pic_path']     ?? null;
        $igGrid       = $igRaw['screenshot_path']      ?? null;
        $gmapsShot    = $gmaps['gmaps_screenshot_path'] ?? null;

        $placesPhotos = [];
        foreach ((array) ($places['photos'] ?? []) as $p) {
            $path = is_string($p) ? $p : ((string) ($p['path'] ?? ''));
            if ($path !== '') {
                $placesPhotos[] = $path;
            }
        }

        $paths      = [];
        $dataSource = ['touchpoint_urls'];

        if (is_string($igProfilePic) && $igProfilePic !== '') {
            $paths[] = $igProfilePic;
            $dataSource[] = 'instagram_profile_pic';
        }
        if (is_string($igGrid) && $igGrid !== '') {
            $paths[] = $igGrid;
            $dataSource[] = 'instagram_screenshot';
        }
        if (is_string($gmapsShot) && $gmapsShot !== '') {
            $paths[] = $gmapsShot;
            $dataSource[] = 'gmaps_screenshot';
        }
        if ($placesPhotos !== []) {
            $paths = array_merge($paths, array_slice($placesPhotos, 0, 2));
            $dataSource[] = 'places_api_photos';
        }

        $visionPayload = [
            'instagram_profile_pic_path' => is_string($igProfilePic) ? $igProfilePic : null,
            'instagram_screenshot_path'  => is_string($igGrid)       ? $igGrid       : null,
            'gmaps_screenshot_path'      => is_string($gmapsShot)    ? $gmapsShot    : null,
            'places_photo_paths'         => $placesPhotos,
        ];

        return [
            'paths'          => $paths,
            'data_source'    => $dataSource,
            'vision_payload' => $visionPayload,
        ];
    }

    /**
     * Convert the structured vision response into a PillarScore (legacy
     * path). Weights: color 35%, typography 15%, logo 25%, imagery 25%.
     *
     * @param array<string,mixed> $vision
     * @param list<string>        $dataSource
     */
    private function hydrateVisionPillarScore(array $vision, string $brandName, array $dataSource): PillarScore
    {
        $color  = (int) ($vision['color_consistency']['score']       ?? 50);
        $typo   = (int) ($vision['typography_consistency']['score']  ?? 50);
        $logo   = (int) ($vision['logo_consistency']['score']        ?? 50);
        $imager = (int) ($vision['imagery_tone']['score']            ?? 50);

        $weighted = ($color * 0.35) + ($typo * 0.15) + ($logo * 0.25) + ($imager * 0.25);
        $overall  = (int) round($weighted);

        $subBucketScores = [
            'color_consistency'      => $color,
            'typography_consistency' => $typo,
            'logo_consistency'       => $logo,
            'imagery_tone'           => $imager,
        ];

        $subBucketReasoning = [
            'color_consistency'      => (string) ($vision['color_consistency']['observations']       ?? ''),
            'typography_consistency' => (string) ($vision['typography_consistency']['observations']  ?? ''),
            'logo_consistency'       => (string) ($vision['logo_consistency']['observations']        ?? ''),
            'imagery_tone'           => (string) ($vision['imagery_tone']['observations']            ?? ''),
        ];

        $evidenceItems = [];
        foreach ($subBucketReasoning as $bucket => $observation) {
            if ($observation === '') {
                continue;
            }
            $score = $subBucketScores[$bucket] ?? 50;
            $impact = $score >= 70
                ? EvidenceItem::IMPACT_POSITIVE
                : ($score <= 40 ? EvidenceItem::IMPACT_NEGATIVE : EvidenceItem::IMPACT_NEUTRAL);
            $evidenceItems[] = new EvidenceItem(
                touchpoint:  $bucket,
                observation: $observation,
                impact:      $impact,
            );
        }

        $reasoning = (string) ($vision['overall_visual_coherence']['summary'] ?? '')
            ?: 'Analisis vision-Konsistensi selesai.';

        $breakdown = [
            'data_source'           => $dataSource,
            'analysis_path'         => 'vision_multimodal',
            'sub_bucket_scores'     => $subBucketScores,
            'sub_bucket_reasoning'  => $subBucketReasoning,
            'touchpoints_analyzed'  => (array) ($vision['touchpoints_analyzed'] ?? []),
            'limitations'           => (array) ($vision['limitations'] ?? []),
            'overall_visual_score'  => (int) ($vision['overall_visual_coherence']['score'] ?? $overall),
            'brand_name'            => $brandName,
        ];

        return new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_KONSISTENSI,
            score: $overall,
            evidence: $evidenceItems,
            reasoning: $reasoning,
            subBucketScores: $subBucketScores,
            scoreBreakdown: $breakdown,
        );
    }
}
