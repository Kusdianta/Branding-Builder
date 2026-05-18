<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\PriceListDetector;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12c.2-rubric-alignment BB114 — PriceListDetector caption-scan
 * path coverage. Vision-path tests live in feature/integration and
 * require ANTHROPIC_API_KEY (skipped in CI by default).
 */
class PriceListDetectorTest extends TestCase
{
    public function test_two_or_more_caption_hits_short_circuits_detection(): void
    {
        $captions = [
            'Diskon hari ini! Harga laundry kiloan mulai Rp 7.000/kg.',
            'Daftar harga lengkap, paket harga 5kg cuma Rp 30.000.',
            'Tidak ada keyword di sini.',
        ];

        $result = (new PriceListDetector())->detect([], $captions);

        $this->assertTrue($result['detected']);
        $this->assertSame('caption_only', $result['method']);
        $this->assertGreaterThanOrEqual(0.9, $result['confidence']);
        $this->assertNotEmpty($result['evidence']);
        $this->assertStringStartsWith('Sumber:', $result['source']);
        $this->assertNull($result['unavailable_reason']);
    }

    public function test_no_captions_no_photos_yields_fallback_with_unavailable_reason(): void
    {
        $result = (new PriceListDetector())->detect([], []);

        $this->assertFalse($result['detected']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertSame('fallback', $result['method']);
        $this->assertNotNull($result['unavailable_reason']);
    }

    public function test_single_caption_hit_is_partial_when_vision_skipped(): void
    {
        $result = (new PriceListDetector())->detect([], [
            'Cuci express, tarif tetap.',
        ]);

        // Single hit + no Anthropic client (null) + no photos → falls back
        // to caption-only partial path.
        $this->assertTrue($result['detected']);
        $this->assertSame('caption_only_partial', $result['method']);
        $this->assertNotNull($result['unavailable_reason']);
        $this->assertSame(0.55, $result['confidence']);
    }

    public function test_source_attribution_is_always_present(): void
    {
        $detector = new PriceListDetector();
        $cases = [
            ['photos' => [], 'captions' => []],
            ['photos' => [], 'captions' => ['Harga mulai Rp 7000']],
            ['photos' => [], 'captions' => ['Harga Rp 8000', 'tarif 5000', 'Rp 9000']],
        ];
        foreach ($cases as $case) {
            $result = $detector->detect($case['photos'], $case['captions']);
            $this->assertNotEmpty($result['source']);
            $this->assertStringStartsWith('Sumber:', $result['source']);
        }
    }
}
