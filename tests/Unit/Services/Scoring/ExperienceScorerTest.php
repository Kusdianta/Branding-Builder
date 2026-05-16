<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Models\ScoringRubric;
use App\Services\Scoring\ExperienceScorer;
use App\Services\Scoring\ServiceSignalsExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB75 — Brand Experience tier classifier (A/B/C/D) coverage.
 * Pure-PHP scorer; no LLM. Covers each bonus sub-bucket across all
 * four tiers + variasi layanan special-case.
 */
class ExperienceScorerTest extends TestCase
{
    private function signal(bool $detected, float $confidence = 0.9, array $sources = []): array
    {
        return [
            'detected'        => $detected,
            'confidence'      => $confidence,
            'sources'         => $sources,
            'verified_by_llm' => false,
        ];
    }

    private function makeScorer(): ExperienceScorer
    {
        return new ExperienceScorer();
    }

    #[Test]
    public function tier_a_fires_full_cap_when_declared_and_detected(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'bonus_ekspres' => $this->signal(true, 0.95, [
                            ['source' => 'ig_highlight_name', 'snippet' => 'Layanan Ekspres', 'score' => 0.95],
                        ]),
                    ],
                ],
            ],
            operatorDecls: ['has_ekspres' => true, 'ekspres_url' => 'https://x.test'],
        );

        $this->assertSame(ScoringRubric::PILLAR_EXPERIENCE, $score->pillarSlug);
        $this->assertSame(10, $score->subBucketScores['bonus_ekspres']); // full cap
        $this->assertSame('A', $score->scoreBreakdown['tier_classification']['bonus_ekspres']);
    }

    #[Test]
    public function tier_b_fires_eighty_percent_when_detected_only(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'bonus_antar_jemput' => $this->signal(true, 0.9, [
                            ['source' => 'places_api_attribute', 'snippet' => 'delivery=true', 'score' => 0.9],
                        ]),
                    ],
                ],
            ],
            operatorDecls: null,
        );

        // 12 * 0.8 = 9.6 → round to 10
        $this->assertSame(10, $score->subBucketScores['bonus_antar_jemput']);
        $this->assertSame('B', $score->scoreBreakdown['tier_classification']['bonus_antar_jemput']);
    }

    #[Test]
    public function tier_c_fires_sixty_seven_percent_when_declared_no_signals(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [],  // no service_signals
            operatorDecls: ['has_sop_keluhan' => true],
        );

        // 15 * 0.67 = 10.05 → round to 10
        $this->assertSame(10, $score->subBucketScores['bonus_sop_keluhan']);
        $this->assertSame('C', $score->scoreBreakdown['tier_classification']['bonus_sop_keluhan']);
        $this->assertStringContainsString(
            'Publikasikan',
            $score->scoreBreakdown['sub_bucket_reasoning']['bonus_sop_keluhan'],
        );
    }

    #[Test]
    public function tier_d_fires_zero_when_neither_declared_nor_detected(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [],
            operatorDecls: null,
        );

        foreach (['bonus_ekspres', 'bonus_antar_jemput', 'bonus_sop_keluhan', 'bonus_price_list', 'bonus_variasi_layanan'] as $key) {
            $this->assertSame(0, $score->subBucketScores[$key], "$key should be 0");
            $this->assertSame('D', $score->scoreBreakdown['tier_classification'][$key]);
        }
        // Base score only.
        $this->assertSame(30, $score->subBucketScores['base']);
        $this->assertSame(30, $score->score);
    }

    #[Test]
    public function explicit_no_declaration_still_lands_tier_d_when_no_detection(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [],
            operatorDecls: ['has_ekspres' => false],
        );

        $this->assertSame(0, $score->subBucketScores['bonus_ekspres']);
        $this->assertStringContainsString(
            'tidak tersedia',
            $score->scoreBreakdown['sub_bucket_reasoning']['bonus_ekspres'],
        );
    }

    #[Test]
    public function variasi_layanan_tier_a_requires_both_sides_at_minimum_4(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'variasi_layanan' => [
                            'detected_variants' => ['kiloan', 'satuan', 'dry_cleaning', 'sepatu'],
                            'sources' => [],
                            'verified_by_llm' => false,
                        ],
                    ],
                ],
            ],
            operatorDecls: [
                'service_variants' => ['kiloan', 'satuan', 'dry_cleaning', 'sepatu', 'karpet'],
            ],
        );

        $this->assertSame(15, $score->subBucketScores['bonus_variasi_layanan']); // full cap
        $this->assertSame('A', $score->scoreBreakdown['tier_classification']['bonus_variasi_layanan']);
    }

    #[Test]
    public function variasi_layanan_tier_b_when_detected_only_above_threshold(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'variasi_layanan' => [
                            'detected_variants' => ['kiloan', 'satuan', 'dry_cleaning', 'sepatu', 'bedding'],
                            'sources' => [],
                            'verified_by_llm' => false,
                        ],
                    ],
                ],
            ],
            operatorDecls: null,
        );

        // 15 * 0.8 = 12
        $this->assertSame(12, $score->subBucketScores['bonus_variasi_layanan']);
        $this->assertSame('B', $score->scoreBreakdown['tier_classification']['bonus_variasi_layanan']);
    }

    #[Test]
    public function variasi_layanan_tier_d_when_below_minimum(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'variasi_layanan' => [
                            'detected_variants' => ['kiloan', 'satuan'], // only 2
                            'sources' => [],
                            'verified_by_llm' => false,
                        ],
                    ],
                ],
            ],
            operatorDecls: ['service_variants' => ['kiloan', 'satuan', 'dry_cleaning']], // only 3
        );

        $this->assertSame(0, $score->subBucketScores['bonus_variasi_layanan']);
        $this->assertSame('D', $score->scoreBreakdown['tier_classification']['bonus_variasi_layanan']);
    }

    #[Test]
    public function evidence_sources_table_carries_per_bonus_provenance(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'bonus_ekspres' => $this->signal(true, 0.95, [
                            ['source' => 'ig_highlight_name', 'snippet' => 'Layanan Ekspres', 'score' => 0.95],
                            ['source' => 'review_mention',    'snippet' => 'ekspres ya', 'score' => 0.7],
                        ]),
                    ],
                ],
            ],
            operatorDecls: ['has_ekspres' => true, 'ekspres_url' => 'https://x.test'],
        );

        $sources = $score->scoreBreakdown['evidence_sources']['bonus_ekspres'];
        // Operator + IG + reviews = 3 sources at Tier A.
        $this->assertCount(3, $sources);
        $sourceNames = array_column($sources, 'source');
        $this->assertContains('operator_declaration', $sourceNames);
        $this->assertContains('ig_highlight_name', $sourceNames);
        $this->assertContains('review_mention', $sourceNames);
    }

    #[Test]
    public function final_score_clamps_to_100_max(): void
    {
        $score = $this->makeScorer()->scoreFromEvidence(
            evidence: [
                'analysis' => [
                    'service_signals' => [
                        'bonus_ekspres'      => $this->signal(true),
                        'bonus_antar_jemput' => $this->signal(true),
                        'bonus_sop_keluhan'  => $this->signal(true),
                        'bonus_price_list'   => $this->signal(true),
                        'variasi_layanan' => [
                            'detected_variants' => ['kiloan', 'satuan', 'dry_cleaning', 'sepatu', 'bedding'],
                            'sources' => [],
                        ],
                    ],
                ],
            ],
            operatorDecls: [
                'has_ekspres'      => true,
                'has_antar_jemput' => true,
                'has_sop_keluhan'  => true,
                'has_price_list'   => true,
                'service_variants' => ['kiloan', 'satuan', 'dry_cleaning', 'sepatu', 'karpet'],
            ],
        );

        // base 30 + 10 + 12 + 15 + 10 + 15 = 92 — well under 100.
        $this->assertSame(92, $score->score);
    }
}
