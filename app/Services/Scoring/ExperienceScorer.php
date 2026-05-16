<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\EvidenceItem;
use App\DTO\PillarScore;
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
