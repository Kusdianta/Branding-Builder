<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\EvidenceItem;
use App\DTO\PillarScore;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;

/**
 * BB75 — Phase 11 Brand Experience scorer with multi-source evidence
 * tier classifier.
 *
 * Replaces the prior LLM-only Experience scoring (which was blind to
 * service signals because it only saw URLs + keyword_hits). New pipeline:
 *
 *   1. Base score = 30 (unchanged).
 *   2. For each bonus sub-bucket, classify tier from
 *      (operator_declaration, service_signals.<bonus_key>):
 *
 *        Tier A — declared + signals verify        → 100% cap
 *        Tier B — detected only, no declaration    →  80% cap
 *        Tier C — declared but no signals          →  67% cap (clamped)
 *        Tier D — neither                          →   0
 *
 *   3. Penalties remain deterministic — applied by
 *      ExperiencePenaltyDetector at the ScorePillarsJob layer (BB26),
 *      not here. This class is bonus-only; the penalty subtraction
 *      happens after pillar score persistence.
 *
 *   4. Each sub-bucket carries:
 *        - score (int)
 *        - tier ('A'|'B'|'C'|'D')
 *        - confidence (float from Stage 1 + Stage 2)
 *        - evidence_sources (list of source-name strings with snippets)
 *        - limitation (operator-facing message when tier=C: "publicize
 *          to be auto-verifiable")
 *
 * Variasi layanan is structurally distinct — fires at full cap when
 * detected_variants count >= MIN_VARIANTS_FOR_BONUS regardless of
 * declaration overlap (it's hard to mis-claim variant breadth).
 */
class ExperienceScorer
{
    private const BASE_SCORE = 30;

    /** @var array<string,int> sub-bucket key => cap (max bonus when Tier A). */
    private const BONUS_CAPS = [
        'bonus_ekspres'         => 10,
        'bonus_antar_jemput'    => 12,
        'bonus_sop_keluhan'     => 15,
        'bonus_price_list'      => 10,
        'bonus_variasi_layanan' => 15,
    ];

    /** Tier multipliers (per operator decision Concern #3). */
    private const TIER_A_MULT = 1.0;   // declared + verified
    private const TIER_B_MULT = 0.8;   // detected only
    private const TIER_C_MULT = 0.67;  // declared, no signals
    private const TIER_D_MULT = 0.0;

    /** Variasi qualifies for bonus when this many variants are detected. */
    private const MIN_VARIANTS_FOR_BONUS = 4;

    /** Confidence threshold above which signals.detected counts as "verifying". */
    private const VERIFY_THRESHOLD = ServiceSignalsExtractor::AMBIGUOUS_HIGH;

    /** Mapping from bonus key → operator_declarations boolean key. */
    private const DECL_KEY_MAP = [
        'bonus_ekspres'      => 'has_ekspres',
        'bonus_antar_jemput' => 'has_antar_jemput',
        'bonus_sop_keluhan'  => 'has_sop_keluhan',
        'bonus_price_list'   => 'has_price_list',
    ];

    /**
     * Score the Experience pillar from the full evidence layer +
     * operator declarations.
     *
     * @param array<string,mixed>      $evidence        audit_evidence column
     * @param array<string,mixed>|null $operatorDecls   operator_declarations column
     * @param array<string,mixed>      $context         per-audit context (brand_name, service_type)
     */
    public function scoreFromEvidence(
        array $evidence,
        ?array $operatorDecls,
        array $context = [],
    ): PillarScore {
        // BB117 — v3 audits route to a simpler bonus model that reads
        // declarations from $audit->touchpoints.operational (BB112
        // wizard step 3) and $audit->touchpoints.service_types.variety_count
        // (BB111 wizard step 2) instead of the legacy
        // operator_declarations column + ServiceSignalsExtractor pipeline.
        $version = (string) ($context['_wizard_version'] ?? BrandAudit::WIZARD_V1);
        if ($version === BrandAudit::WIZARD_V3) {
            return $this->scoreV3($evidence, $operatorDecls, $context);
        }

        $signals = (array) ($evidence['analysis']['service_signals'] ?? []);

        $subBucketScores    = ['base' => self::BASE_SCORE];
        $subBucketReasoning = ['base' => 'Setiap brand mulai dari skor dasar 30 sebelum bonus dan penalti.'];
        $sourcesTable       = [];
        $tiersTable         = [];

        // ── Per-bonus tier classification ────────────────────────────
        foreach (self::DECL_KEY_MAP as $bonusKey => $declKey) {
            $declared = $this->declarationToBool($operatorDecls, $declKey);
            $signal   = (array) ($signals[$bonusKey] ?? []);
            $detected = $this->signalDetected($signal);

            $tier  = $this->classifyTier($declared, $detected);
            $cap   = self::BONUS_CAPS[$bonusKey];
            $score = (int) round($cap * $this->tierMultiplier($tier));

            $subBucketScores[$bonusKey]    = $score;
            $subBucketReasoning[$bonusKey] = $this->renderReasoning($bonusKey, $tier, $signal, $declared);
            $sourcesTable[$bonusKey]       = $this->collectSources($tier, $signal, $declared, $operatorDecls, $declKey);
            $tiersTable[$bonusKey]         = $tier;
        }

        // ── Variasi layanan — distinct shape, count-based ────────────
        $variasi = $this->scoreVariasi(
            (array) ($signals['variasi_layanan'] ?? []),
            $operatorDecls,
        );
        $subBucketScores['bonus_variasi_layanan']    = $variasi['score'];
        $subBucketReasoning['bonus_variasi_layanan'] = $variasi['reasoning'];
        $sourcesTable['bonus_variasi_layanan']       = $variasi['sources'];
        $tiersTable['bonus_variasi_layanan']         = $variasi['tier'];

        // Penalty sub-buckets are populated by ScorePillarsJob's
        // ExperiencePenaltyDetector path post-scoring. Seed them with
        // zero so the persisted shape is stable.
        foreach (['penalty_keterlambatan', 'penalty_pakaian_hilang', 'penalty_no_response_wa'] as $penaltyKey) {
            $subBucketScores[$penaltyKey]    = 0;
            $subBucketReasoning[$penaltyKey] = 'Penalti diaplikasikan setelah pillar score di-render (lihat ExperiencePenaltyDetector).';
        }

        $total = array_sum(array_filter(
            $subBucketScores,
            static fn ($v, $k) => ! str_starts_with($k, 'penalty_'),
            ARRAY_FILTER_USE_BOTH,
        ));
        $total = max(0, min(100, (int) $total));

        $evidenceItems = $this->renderEvidenceItems($sourcesTable);

        $breakdown = [
            'data_source'        => $this->topDataSources($sourcesTable),
            'analysis_path'      => 'tier_classifier_v1',
            'sub_bucket_scores'  => $subBucketScores,
            'sub_bucket_reasoning' => $subBucketReasoning,
            'sub_bucket_caps'    => array_merge(
                ['base' => self::BASE_SCORE],
                self::BONUS_CAPS,
                ['penalty_keterlambatan' => 8, 'penalty_pakaian_hilang' => 10, 'penalty_no_response_wa' => 8],
            ),
            'evidence_sources'   => $sourcesTable,
            'tier_classification' => $tiersTable,
            'evidence_consumed'  => [
                'evidence.analysis.service_signals',
                'operator_declarations',
            ],
            'brand_name'         => (string) ($context['brand_name'] ?? ''),
        ];

        $reasoning = $this->summarizeReasoning($total, $sourcesTable);

        return new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_EXPERIENCE,
            score: $total,
            evidence: $evidenceItems,
            reasoning: $reasoning,
            subBucketScores: $subBucketScores,
            scoreBreakdown: $breakdown,
        );
    }

    /**
     * BB117 v3 path — PPT-rubric Experience scoring with deterministic
     * bonus assignment from wizard step inputs + price-list detection.
     * Penalties remain handled post-persistence by ExperiencePenaltyDetector.
     *
     * Bonus model (additive, no tier classifier):
     *   - Base 30
     *   - Bonus ekspres   +10  if touchpoints.operational.express_service
     *   - Bonus antar     +12  if touchpoints.operational.pickup_delivery
     *   - Bonus variasi   +15  if touchpoints.service_types.variety_count ≥ 4
     *   - Bonus SOP       +15 (declared AND owner_reply_rate ≥ 0.5)
     *                     +8  (declared only)
     *   - Bonus price     +10  if audit_evidence.price_list_detection.detected
     *
     * @param array<string,mixed>      $evidence
     * @param array<string,mixed>|null $operatorDecls  legacy bag, kept for compat
     * @param array<string,mixed>      $context        per-audit context
     */
    private function scoreV3(array $evidence, ?array $operatorDecls, array $context): PillarScore
    {
        $touchpointsOp = (array) ($context['touchpoints_operational'] ?? []);
        $varietyCount  = (int)   ($context['variety_count'] ?? 1);
        $replyRate     = (float) ($context['owner_reply_rate'] ?? 0.0);
        $priceDetection= (array) ($evidence['price_list_detection'] ?? []);
        $priceDetected = (bool)  ($priceDetection['detected'] ?? false);

        $expressDeclared = (bool) ($touchpointsOp['express_service']  ?? false);
        $pickupDeclared  = (bool) ($touchpointsOp['pickup_delivery']  ?? false);
        $sopDeclared     = (bool) ($touchpointsOp['complaint_sop']    ?? false);
        // BB139 → Phase 12c.4 FIX 2 — operator-declared price-list
        // signal (BB137 wizard checkbox). Treated as Tier 1 evidence:
        // a confirmed operator declaration unlocks the full +10
        // regardless of whether the AI auto-detector also fired. The
        // earlier "partial +6 for declaration alone" path was demoted
        // in Phase 12c.4 because the operator checkbox is now the
        // canonical source for price transparency — the AI detector
        // remains a verification path, not the primary signal.
        $priceDeclared = (bool) ($touchpointsOp['price_list']         ?? false);

        $base       = self::BASE_SCORE;
        $express    = $expressDeclared ? 10 : 0;
        $pickup     = $pickupDeclared  ? 12 : 0;
        $variasi    = $varietyCount >= 4 ? 15 : 0;
        // SOP rule: declared+verified (≥50% reply rate) = +15; declared
        // alone = +8 (partial). Undeclared = 0.
        $sop = match (true) {
            $sopDeclared && $replyRate >= 0.50 => 15,
            $sopDeclared                       => 8,
            default                            => 0,
        };
        $price = match (true) {
            $priceDetected || $priceDeclared => 10,  // Tier 1 (declared) or Tier 2 (detected): full bonus
            default                          => 0,
        };

        $subBucketScores = [
            'base'                  => $base,
            'bonus_ekspres'         => $express,
            'bonus_antar_jemput'    => $pickup,
            'bonus_variasi_layanan' => $variasi,
            'bonus_sop_keluhan'     => $sop,
            'bonus_price_list'      => $price,
            // penalties populated post-persistence by ExperiencePenaltyDetector
            'penalty_keterlambatan'   => 0,
            'penalty_pakaian_hilang'  => 0,
            'penalty_no_response_wa'  => 0,
        ];

        $subBucketReasoning = [
            'base'                  => 'Setiap brand mulai dari skor dasar 30 sebelum bonus dan penalti.',
            'bonus_ekspres'         => $express > 0
                ? 'Operator menyatakan layanan ekspres tersedia di wizard Step 3.'
                : 'Operator tidak mengaktifkan layanan ekspres di wizard Step 3.',
            'bonus_antar_jemput'    => $pickup > 0
                ? 'Operator menyatakan antar jemput tersedia di wizard Step 3.'
                : 'Operator tidak mengaktifkan antar jemput di wizard Step 3.',
            'bonus_variasi_layanan' => $variasi > 0
                ? "Operator menyatakan {$varietyCount} variasi layanan di wizard Step 2 (cukup ≥4 untuk bonus penuh)."
                : "Operator menyatakan {$varietyCount} variasi layanan di wizard Step 2 — belum mencapai ambang ≥4 untuk bonus.",
            'bonus_sop_keluhan'     => match ($sop) {
                15      => 'SOP keluhan dideklarasikan dan terverifikasi oleh tingkat balasan pemilik ≥ 50%.',
                8       => 'SOP keluhan dideklarasikan, tetapi tingkat balasan pemilik di Google Maps < 50% — bonus parsial.',
                default => 'SOP keluhan tidak dideklarasikan operator.',
            },
            'bonus_price_list'      => match (true) {
                $priceDetected => 'Daftar harga terdeteksi via ' . (string) ($priceDetection['method'] ?? 'tidak diketahui') . '.',
                $priceDeclared => 'Operator menyatakan daftar harga dipublikasikan (Tier 1 evidence) — bonus penuh.',
                default        => 'Tidak ada daftar harga publik terdeteksi maupun dideklarasikan.',
            },
            'penalty_keterlambatan'  => 'Penalti diaplikasikan setelah pillar score di-render (lihat ExperiencePenaltyDetector).',
            'penalty_pakaian_hilang' => 'Penalti diaplikasikan setelah pillar score di-render (lihat ExperiencePenaltyDetector).',
            'penalty_no_response_wa' => 'Penalti diaplikasikan setelah pillar score di-render (lihat ExperiencePenaltyDetector).',
        ];

        $total = max(0, min(100, $base + $express + $pickup + $variasi + $sop + $price));

        $sourcesTable = [
            'bonus_ekspres'         => $express > 0 ? [['source' => 'wizard_step_3.operational.express_service', 'snippet' => 'Operator menyatakan layanan ekspres tersedia.', 'verified' => true]] : [],
            'bonus_antar_jemput'    => $pickup  > 0 ? [['source' => 'wizard_step_3.operational.pickup_delivery', 'snippet' => 'Operator menyatakan antar jemput tersedia.', 'verified' => true]] : [],
            'bonus_variasi_layanan' => $variasi > 0 ? [['source' => 'wizard_step_2.service_types', 'snippet' => "Operator menyatakan {$varietyCount} variasi layanan.", 'verified' => true]] : [],
            'bonus_sop_keluhan'     => $sop > 0
                ? [
                    ['source' => 'wizard_step_3.operational.complaint_sop', 'snippet' => 'Operator menyatakan SOP keluhan ada.', 'verified' => $sop === 15],
                ]
                : [],
            'bonus_price_list'      => match (true) {
                $priceDetected => [['source' => 'audit_evidence.price_list_detection', 'snippet' => 'Daftar harga terdeteksi (' . (string) ($priceDetection['method'] ?? '') . ').', 'verified' => true]],
                $priceDeclared => [['source' => 'wizard_step_3.operational.price_list', 'snippet' => 'Operator menyatakan daftar harga dipublikasikan (Tier 1 evidence).', 'verified' => true]],
                default        => [],
            },
        ];

        $evidenceItems = $this->renderEvidenceItems($sourcesTable);

        $breakdown = [
            'data_source'            => $this->v3DataSources($expressDeclared, $pickupDeclared, $sopDeclared, $varietyCount, $priceDetected),
            'analysis_path'          => 'v3_ppt_rubric',
            'sub_bucket_scores'      => $subBucketScores,
            'sub_bucket_reasoning'   => $subBucketReasoning,
            'sub_bucket_caps'        => array_merge(
                ['base' => self::BASE_SCORE],
                self::BONUS_CAPS,
                ['penalty_keterlambatan' => 8, 'penalty_pakaian_hilang' => 10, 'penalty_no_response_wa' => 8],
            ),
            'evidence_sources'       => $sourcesTable,
            'evidence_consumed'      => [
                'touchpoints.operational',
                'touchpoints.service_types',
                'audit_evidence.price_list_detection',
                'owner_reply_rate (computed from gmaps_scrape)',
            ],
            'owner_reply_rate'       => $replyRate,
            'sop_partial_bonus_applied' => $sop === 8,
            'brand_name'             => (string) ($context['brand_name'] ?? ''),
        ];

        $reasoning = sprintf(
            'Brand Experience skor %d/100 (sebelum penalti). Base 30, bonus ekspres %d, antar jemput %d, variasi %d, SOP %d, price list %d.',
            $total,
            $express,
            $pickup,
            $variasi,
            $sop,
            $price,
        );

        return new PillarScore(
            pillarSlug:      ScoringRubric::PILLAR_EXPERIENCE,
            score:           $total,
            evidence:        $evidenceItems,
            reasoning:       $reasoning,
            subBucketScores: $subBucketScores,
            scoreBreakdown:  $breakdown,
        );
    }

    /** @return list<string> */
    private function v3DataSources(bool $express, bool $pickup, bool $sop, int $variety, bool $price): array
    {
        $out = ['touchpoints'];
        if ($express || $pickup || $sop) {
            $out[] = 'touchpoints.operational';
        }
        if ($variety >= 2) {
            $out[] = 'touchpoints.service_types';
        }
        if ($price) {
            $out[] = 'audit_evidence.price_list_detection';
        }
        return array_values(array_unique($out));
    }

    private function declarationToBool(?array $decls, string $key): ?bool
    {
        if ($decls === null) {
            return null;
        }
        $v = $decls[$key] ?? null;
        return is_bool($v) ? $v : null;
    }

    private function signalDetected(array $signal): bool
    {
        if ($signal === []) {
            return false;
        }
        // BB74 ServiceSignalsExtractor sets detected=true above Stage-1
        // threshold OR after Stage-2 LLM verification. Either path counts.
        return (bool) ($signal['detected'] ?? false);
    }

    private function classifyTier(?bool $declared, bool $detected): string
    {
        if ($declared === true && $detected) {
            return 'A';
        }
        if ($declared !== true && $detected) {
            return 'B';
        }
        if ($declared === true && ! $detected) {
            return 'C';
        }
        return 'D';
    }

    private function tierMultiplier(string $tier): float
    {
        return match ($tier) {
            'A'     => self::TIER_A_MULT,
            'B'     => self::TIER_B_MULT,
            'C'     => self::TIER_C_MULT,
            default => self::TIER_D_MULT,
        };
    }

    private function renderReasoning(string $bonusKey, string $tier, array $signal, ?bool $declared): string
    {
        $label = $this->humanLabel($bonusKey);
        $confidence = (float) ($signal['confidence'] ?? 0);

        return match ($tier) {
            'A' => "Bonus {$label} diberikan penuh — operator menyatakan tersedia DAN terdeteksi di touchpoint publik (confidence " . number_format($confidence, 2) . ").",
            'B' => "Bonus {$label} diberikan 80% — terdeteksi di touchpoint publik (confidence " . number_format($confidence, 2) . "), namun operator tidak menyatakan secara eksplisit.",
            'C' => "Bonus {$label} diberikan 67% — operator menyatakan tersedia, tetapi tidak terdeteksi di touchpoint publik. Publikasikan layanan ini agar terverifikasi otomatis.",
            default => $declared === false
                ? "Tidak ada bonus {$label} — operator menyatakan tidak tersedia."
                : "Tidak ada bonus {$label} — tidak ada bukti dari operator maupun touchpoint publik.",
        };
    }

    private function humanLabel(string $bonusKey): string
    {
        return match ($bonusKey) {
            'bonus_ekspres'      => 'layanan ekspres',
            'bonus_antar_jemput' => 'antar jemput',
            'bonus_sop_keluhan'  => 'SOP keluhan',
            'bonus_price_list'   => 'price list publik',
            'bonus_variasi_layanan' => 'variasi layanan',
            default              => $bonusKey,
        };
    }

    /** @return list<array<string,mixed>> */
    private function collectSources(string $tier, array $signal, ?bool $declared, ?array $decls, string $declKey): array
    {
        $out = [];
        if ($tier === 'D') {
            return $out;
        }
        if ($tier === 'A' || $tier === 'C') {
            $urlKey = preg_replace('/^has_/', '', $declKey) . '_url';
            $url = is_array($decls) ? (string) ($decls[$urlKey] ?? '') : '';
            $out[] = [
                'source'   => ServiceSignalsExtractor::SOURCE_OPERATOR_DECLARATION,
                'snippet'  => 'Operator menyatakan tersedia.' . ($url !== '' ? " URL: {$url}" : ''),
                'verified' => $tier === 'A',
            ];
        }
        if ($tier === 'A' || $tier === 'B') {
            foreach ((array) ($signal['sources'] ?? []) as $s) {
                if (! is_array($s)) {
                    continue;
                }
                if (($s['source'] ?? null) === ServiceSignalsExtractor::SOURCE_OPERATOR_DECLARATION) {
                    continue; // already represented above for Tier A
                }
                $out[] = [
                    'source'  => (string) ($s['source'] ?? ''),
                    'snippet' => (string) ($s['snippet'] ?? ''),
                    'score'   => (float) ($s['score'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /**
     * Variasi layanan tier logic:
     *   A — operator declared >= MIN variants AND detected >= MIN
     *   B — detected >= MIN regardless of declarations
     *   C — operator declared >= MIN, detected < MIN
     *   D — neither side reaches MIN
     *
     * @return array{score:int, reasoning:string, sources:list<array<string,mixed>>}
     */
    private function scoreVariasi(array $signal, ?array $decls): array
    {
        $detected = array_values((array) ($signal['detected_variants'] ?? []));
        $declared = is_array($decls) ? array_values((array) ($decls['service_variants'] ?? [])) : [];
        $cap = self::BONUS_CAPS['bonus_variasi_layanan'];

        $detectedCount = count(array_unique($detected));
        $declaredCount = count(array_unique($declared));

        if ($detectedCount >= self::MIN_VARIANTS_FOR_BONUS && $declaredCount >= self::MIN_VARIANTS_FOR_BONUS) {
            $tier = 'A';
        } elseif ($detectedCount >= self::MIN_VARIANTS_FOR_BONUS) {
            $tier = 'B';
        } elseif ($declaredCount >= self::MIN_VARIANTS_FOR_BONUS) {
            $tier = 'C';
        } else {
            $tier = 'D';
        }

        $score = (int) round($cap * $this->tierMultiplier($tier));

        $reasoning = match ($tier) {
            'A' => "Bonus variasi layanan penuh — operator mencatat " . $declaredCount . " variasi dan " . $detectedCount . " teridentifikasi otomatis.",
            'B' => "Bonus variasi layanan 80% — terdeteksi " . $detectedCount . " variasi di touchpoint publik tanpa pencatatan operator.",
            'C' => "Bonus variasi layanan 67% — operator mencatat " . $declaredCount . " variasi, tetapi hanya " . $detectedCount . " yang terdeteksi di publik. Tampilkan variasi lengkap di IG/website.",
            default => "Tidak ada bonus variasi layanan — kurang dari " . self::MIN_VARIANTS_FOR_BONUS . " variasi terkonfirmasi.",
        };

        $sources = [];
        if ($tier === 'A' || $tier === 'C') {
            $sources[] = [
                'source'  => ServiceSignalsExtractor::SOURCE_OPERATOR_DECLARATION,
                'snippet' => 'Operator mencatat variasi: ' . implode(', ', $declared),
                'verified' => $tier === 'A',
            ];
        }
        if ($tier === 'A' || $tier === 'B') {
            foreach ((array) ($signal['sources'] ?? []) as $variant => $contributions) {
                if (! is_array($contributions)) {
                    continue;
                }
                foreach ($contributions as $c) {
                    if (! is_array($c)) {
                        continue;
                    }
                    if (($c['source'] ?? null) === ServiceSignalsExtractor::SOURCE_OPERATOR_DECLARATION) {
                        continue;
                    }
                    $sources[] = [
                        'source'  => (string) ($c['source'] ?? ''),
                        'snippet' => "[{$variant}] " . (string) ($c['snippet'] ?? ''),
                    ];
                }
            }
        }

        return [
            'score'     => $score,
            'reasoning' => $reasoning,
            'sources'   => $sources,
            'tier'      => $tier,
        ];
    }

    /** @return list<EvidenceItem> */
    private function renderEvidenceItems(array $sourcesTable): array
    {
        $items = [];
        foreach ($sourcesTable as $bonusKey => $sources) {
            if ($sources === []) {
                continue;
            }
            $primary = $sources[0];
            $items[] = new EvidenceItem(
                touchpoint:  $bonusKey,
                observation: (string) ($primary['snippet'] ?? ''),
                impact:      EvidenceItem::IMPACT_POSITIVE,
            );
        }
        return $items;
    }

    /** @return array<string,string> */
    private function summarizeTiers(array $sourcesTable): array
    {
        $out = [];
        foreach ($sourcesTable as $key => $sources) {
            if ($sources === []) {
                $out[$key] = 'D';
                continue;
            }
            // Heuristic: first source verified=true → A; first source
            // operator_declaration not verified → C; else B.
            $first = $sources[0];
            if (($first['source'] ?? null) === ServiceSignalsExtractor::SOURCE_OPERATOR_DECLARATION) {
                $out[$key] = ($first['verified'] ?? false) ? 'A' : 'C';
            } else {
                $out[$key] = 'B';
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function topDataSources(array $sourcesTable): array
    {
        $seen = [];
        foreach ($sourcesTable as $sources) {
            foreach ($sources as $s) {
                if (! isset($s['source'])) {
                    continue;
                }
                $seen[(string) $s['source']] = true;
            }
        }
        return array_keys($seen);
    }

    private function summarizeReasoning(int $total, array $sourcesTable): string
    {
        $fired = array_keys(array_filter($sourcesTable, static fn ($s) => $s !== []));
        if ($fired === []) {
            return 'Brand Experience pada skor dasar 30: tidak ada bonus yang terverifikasi dari touchpoint publik maupun deklarasi operator.';
        }
        $list = implode(', ', array_map(fn ($k) => $this->humanLabel($k), $fired));
        return "Brand Experience skor {$total}/100. Bonus terverifikasi: {$list}.";
    }
}
