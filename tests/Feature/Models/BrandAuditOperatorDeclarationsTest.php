<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB73 — verifies the operator_declarations column persists JSON and
 * casts cleanly between writes and reads. ExperienceScorer (BB75) relies
 * on this shape for its tier classifier.
 */
class BrandAuditOperatorDeclarationsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(array $overrides = []): BrandAudit
    {
        return BrandAudit::create(array_merge([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['instagram_url' => 'https://instagram.com/lessworry.id'],
            'status'        => BrandAudit::STATUS_PENDING,
            'expires_at'    => now()->addDays(30),
        ], $overrides));
    }

    #[Test]
    public function operator_declarations_defaults_to_null(): void
    {
        $audit = $this->makeAudit();
        $audit->refresh();

        $this->assertNull($audit->operator_declarations);
    }

    #[Test]
    public function operator_declarations_persists_full_shape(): void
    {
        $declarations = [
            'has_ekspres'      => true,
            'ekspres_url'      => 'https://instagram.com/p/example',
            'has_antar_jemput' => true,
            'antar_jemput_url' => null,
            'service_variants' => ['kiloan', 'satuan', 'dry_cleaning'],
            'has_sop_keluhan'  => false,
            'sop_keluhan_url'  => null,
            'has_price_list'   => null,
            'price_list_url'   => null,
        ];

        $audit = $this->makeAudit(['operator_declarations' => $declarations]);
        $audit->refresh();

        $this->assertSame($declarations, $audit->operator_declarations);
        $this->assertIsArray($audit->operator_declarations);
        $this->assertTrue($audit->operator_declarations['has_ekspres']);
        $this->assertFalse($audit->operator_declarations['has_sop_keluhan']);
        $this->assertNull($audit->operator_declarations['has_price_list']);
        $this->assertSame(
            ['kiloan', 'satuan', 'dry_cleaning'],
            $audit->operator_declarations['service_variants'],
        );
    }

    #[Test]
    public function operator_declarations_accepts_partial_shape(): void
    {
        // Operator filled in only ekspres + variants; the rest remain
        // null. ExperienceScorer (BB75) tier classifier should treat
        // missing keys identically to declared-null.
        $partial = [
            'has_ekspres'      => true,
            'service_variants' => ['kiloan'],
        ];

        $audit = $this->makeAudit(['operator_declarations' => $partial]);
        $audit->refresh();

        $this->assertSame($partial, $audit->operator_declarations);
    }
}
