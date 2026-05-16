<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Services\ClaudeService;
use App\Services\Scoring\ServiceSignalsExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * BB74 — Stage 1 (pure-PHP regex) + Stage 2 (LLM verification band)
 * coverage. Stage 2 is mocked via the ClaudeService stub; we don't hit
 * the real Anthropic API in the test suite.
 */
class ServiceSignalsExtractorTest extends TestCase
{
    private ClaudeService&MockObject $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->claude = $this->createMock(ClaudeService::class);
    }

    private function makeExtractor(): ServiceSignalsExtractor
    {
        return new ServiceSignalsExtractor($this->claude);
    }

    #[Test]
    public function operator_declaration_alone_fires_signal_with_full_confidence(): void
    {
        $this->claude->expects($this->never())->method('verifyServiceSignals');

        $signals = $this->makeExtractor()->extract(
            evidence: [],
            operatorDecls: ['has_ekspres' => true, 'ekspres_url' => 'https://x.test'],
        );

        $this->assertTrue($signals['bonus_ekspres']['detected']);
        $this->assertEqualsWithDelta(1.0, $signals['bonus_ekspres']['confidence'], 0.001);
        $this->assertSame(
            ServiceSignalsExtractor::SOURCE_OPERATOR_DECLARATION,
            $signals['bonus_ekspres']['primary_source'],
        );
        $this->assertNotEmpty($signals['bonus_ekspres']['sources']);
    }

    #[Test]
    public function ig_highlight_name_match_fires_at_95_percent_confidence(): void
    {
        // "Tarif" deliberately omitted — it's a 'bonus_price_list' partial
        // hit at 0.665, which would route to Stage 2 (ambiguous band) and
        // break the "never" expectation. Antar Jemput is the focus here.
        // Allow Stage 2 to be optionally called; the test focuses on the
        // antar_jemput aggregate not on whether the ambiguous band fires.
        $this->claude->method('verifyServiceSignals')->willReturn([]);

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'instagram_audit' => [
                    'highlight_names' => ['Antar Jemput', 'Testimoni'],
                ],
            ],
            operatorDecls: null,
        );

        $this->assertTrue($signals['bonus_antar_jemput']['detected']);
        $this->assertEqualsWithDelta(0.95, $signals['bonus_antar_jemput']['confidence'], 0.001);
        $this->assertSame(
            ServiceSignalsExtractor::SOURCE_IG_HIGHLIGHT_NAME,
            $signals['bonus_antar_jemput']['primary_source'],
        );
    }

    #[Test]
    public function aggregate_confidence_is_max_across_sources_not_sum(): void
    {
        $this->claude->expects($this->never())->method('verifyServiceSignals');

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'instagram_audit' => [
                    // Highlight name = 0.95 * exact_phrase = 0.95
                    'highlight_names' => ['Layanan Ekspres'],
                ],
                'gmaps_scrape' => [
                    // Review mention = 0.7 * exact_phrase = 0.7
                    'reviews' => [
                        ['text' => 'Mereka punya layanan ekspres yang cepat banget.'],
                    ],
                ],
            ],
            operatorDecls: null,
        );

        // Max of (0.95, 0.7) = 0.95, not summed.
        $this->assertEqualsWithDelta(0.95, $signals['bonus_ekspres']['confidence'], 0.001);
        $this->assertCount(2, $signals['bonus_ekspres']['sources']);
    }

    #[Test]
    public function low_confidence_review_only_signal_stays_undetected_when_band_disabled(): void
    {
        // Caption-only mention at fuzzy specificity: 0.6 * 0.5 = 0.3 (below band).
        $signals = $this->makeExtractor()->extract(
            evidence: [
                'instagram_analysis' => [
                    'content_analysis' => [
                        'recent_captions' => ['cepat banget waktu cuci'],
                    ],
                ],
            ],
            operatorDecls: null,
            useLlmBand: false,
        );

        $this->assertLessThan(
            ServiceSignalsExtractor::AMBIGUOUS_LOW,
            $signals['bonus_ekspres']['confidence'],
        );
        $this->assertFalse($signals['bonus_ekspres']['detected']);
    }

    #[Test]
    public function ambiguous_band_signals_route_to_stage_2_llm_when_enabled(): void
    {
        // Review mention at partial specificity: 0.7 * 0.7 = 0.49 — ambiguous band.
        $this->claude->expects($this->once())
            ->method('verifyServiceSignals')
            ->willReturnCallback(function (array $ambiguous): array {
                // Expect bonus_ekspres in the ambiguous bucket.
                $this->assertArrayHasKey('bonus_ekspres', $ambiguous);
                return [
                    'bonus_ekspres' => ['detected' => true, 'reasoning' => 'OK'],
                ];
            });

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'gmaps_scrape' => [
                    'reviews' => [
                        ['text' => 'Layanan kilat, recommended.'],
                    ],
                ],
            ],
            operatorDecls: null,
        );

        $this->assertTrue($signals['bonus_ekspres']['detected']);
        $this->assertTrue($signals['bonus_ekspres']['verified_by_llm']);
        $this->assertSame('OK', $signals['bonus_ekspres']['llm_reasoning']);
    }

    #[Test]
    public function llm_failure_in_stage_2_keeps_stage_1_scores_intact(): void
    {
        $this->claude->method('verifyServiceSignals')
            ->willThrowException(new \RuntimeException('Anthropic down'));

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'gmaps_scrape' => [
                    'reviews' => [
                        ['text' => 'Pelayanan kilat sekali'],
                    ],
                ],
            ],
            operatorDecls: null,
        );

        // Stage 1 score preserved; detected remains false (was ambiguous).
        $this->assertFalse($signals['bonus_ekspres']['verified_by_llm']);
        $this->assertFalse($signals['bonus_ekspres']['detected']);
    }

    #[Test]
    public function website_keyword_booleans_contribute_partial_specificity(): void
    {
        $this->claude->method('verifyServiceSignals')->willReturn([]);

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'website' => [
                    'title' => 'Less Worry',
                    'has_pickup_keywords' => true,
                    'has_express_keywords' => true,
                ],
            ],
            operatorDecls: null,
        );

        // Website meta = 0.8, partial = 0.7 → score 0.56. In ambiguous band.
        $this->assertGreaterThanOrEqual(
            ServiceSignalsExtractor::AMBIGUOUS_LOW,
            $signals['bonus_antar_jemput']['confidence'],
        );
    }

    #[Test]
    public function places_api_pickup_attribute_fires_strong_signal(): void
    {
        $this->claude->expects($this->never())->method('verifyServiceSignals');

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'places_api' => [
                    'attributes' => ['delivery' => true],
                ],
            ],
            operatorDecls: null,
        );

        $this->assertTrue($signals['bonus_antar_jemput']['detected']);
        $this->assertEqualsWithDelta(0.9, $signals['bonus_antar_jemput']['confidence'], 0.001);
        $this->assertSame(
            ServiceSignalsExtractor::SOURCE_PLACES_API_ATTRIBUTE,
            $signals['bonus_antar_jemput']['primary_source'],
        );
    }

    #[Test]
    public function variasi_layanan_aggregates_multi_value_detection(): void
    {
        $this->claude->method('verifyServiceSignals')->willReturn([]);

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'instagram_audit' => ['bio' => 'Layanan kiloan, satuan, dan dry cleaning'],
                'gmaps_scrape' => [
                    'reviews' => [
                        ['text' => 'Bedding bersih banget setelah laundry'],
                    ],
                ],
            ],
            operatorDecls: ['service_variants' => ['boneka']],
        );

        $variasi = $signals['variasi_layanan'];
        $detected = $variasi['detected_variants'];
        $this->assertContains('kiloan', $detected);
        $this->assertContains('satuan', $detected);
        $this->assertContains('dry_cleaning', $detected);
        $this->assertContains('bedding', $detected);
        $this->assertContains('boneka', $detected);  // from operator declaration
    }

    #[Test]
    public function website_error_payload_skips_scan_gracefully(): void
    {
        $this->claude->expects($this->never())->method('verifyServiceSignals');

        $signals = $this->makeExtractor()->extract(
            evidence: [
                'website' => ['error' => 'unreachable', 'detail' => 'DNS'],
            ],
            operatorDecls: null,
        );

        // No signals fired from the broken website payload.
        $this->assertFalse($signals['bonus_ekspres']['detected']);
        $this->assertSame(0.0, $signals['bonus_ekspres']['confidence']);
    }
}
