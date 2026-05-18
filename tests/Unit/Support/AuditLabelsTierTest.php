<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AuditLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 12c.2-rubric-alignment BB119 — tier variant mapping +
 * tierForRatio inference. Every PPT-rubric tier label must map
 * onto exactly one of {good, warning, bad} so the badge CSS
 * always picks a defined color.
 */
class AuditLabelsTierTest extends TestCase
{
    #[DataProvider('rubricTierLabels')]
    public function test_every_rubric_tier_label_maps_to_a_known_variant(string $tier): void
    {
        $variant = AuditLabels::tierVariant($tier);
        $this->assertContains($variant, ['good', 'warning', 'bad'], "Tier '{$tier}' should map to a known variant");
    }

    public static function rubricTierLabels(): iterable
    {
        return [
            'sempurna'           => ['sempurna'],
            'sangat baik'        => ['sangat baik'],
            'baik'               => ['baik'],
            'cukup'              => ['cukup'],
            'kurang'             => ['kurang'],
            'sangat kurang'      => ['sangat kurang'],
            'di bawah rata-rata' => ['di bawah rata-rata'],
            'tinggi'             => ['tinggi'],
            'sedang'             => ['sedang'],
            'rendah'             => ['rendah'],
            'sangat aktif'       => ['sangat aktif'],
            'aktif'              => ['aktif'],
            'jarang'             => ['jarang'],
            'tidak aktif'        => ['tidak aktif'],
            'sangat konsisten'   => ['sangat konsisten'],
            'cukup konsisten'    => ['cukup konsisten'],
            'kurang konsisten'   => ['kurang konsisten'],
            'tidak ada data'     => ['tidak ada data'],
        ];
    }

    public function test_unknown_tier_falls_back_to_warning(): void
    {
        $this->assertSame('warning', AuditLabels::tierVariant('unmapped-label'));
    }

    public function test_null_tier_falls_back_to_bad(): void
    {
        $this->assertSame('bad', AuditLabels::tierVariant(null));
    }

    public function test_tier_for_ratio_boundaries(): void
    {
        $this->assertSame('sempurna', AuditLabels::tierForRatio(1.0));
        $this->assertSame('sempurna', AuditLabels::tierForRatio(0.96));
        $this->assertSame('sangat baik', AuditLabels::tierForRatio(0.80));
        $this->assertSame('baik', AuditLabels::tierForRatio(0.61));
        $this->assertSame('cukup', AuditLabels::tierForRatio(0.40));
        $this->assertSame('kurang', AuditLabels::tierForRatio(0.10));
        $this->assertSame('tidak ada data', AuditLabels::tierForRatio(0.0));
    }

    public function test_pre_rubric_source_label_per_version(): void
    {
        $this->assertStringContainsString('v1', AuditLabels::preRubricSource('v1'));
        $this->assertStringContainsString('v2', AuditLabels::preRubricSource('v2'));
        $this->assertSame('Sumber: tidak tersedia', AuditLabels::preRubricSource(null));
    }
}
