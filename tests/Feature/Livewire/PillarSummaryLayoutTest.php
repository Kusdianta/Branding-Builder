<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Concerns\MakesCompletedIgAudit;
use Tests\TestCase;

/**
 * BB141 — "Rincian Skor" table and the "Profil Skor Pilar" radar render
 * side by side inside a single .pillar-summary-grid wrapper (50/50 on
 * desktop, stacking < 768px). Display-only restructure: this test pins
 * the wrapper + source order so the two blocks stay paired.
 */
class PillarSummaryLayoutTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedIgAudit;

    public function test_score_table_and_radar_render_inside_pillar_summary_grid_in_order(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'     => $this->igMeta(),
            'scorecard' => [],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        // The grid wrapper is present.
        $this->assertStringContainsString('pillar-summary-grid', $html);

        // Both blocks live after the grid opens, table before radar.
        $gridPos  = strpos($html, 'pillar-summary-grid');
        $tablePos = strpos($html, 'Rincian Skor');
        $radarPos = strpos($html, 'Profil Skor Pilar');

        $this->assertNotFalse($tablePos, 'Rincian Skor block must render.');
        $this->assertNotFalse($radarPos, 'Profil Skor Pilar block must render.');
        $this->assertLessThan($tablePos, $gridPos, 'Grid wrapper must open before the score table.');
        $this->assertLessThan($radarPos, $tablePos, 'Rincian Skor must render before Profil Skor Pilar.');
    }
}
