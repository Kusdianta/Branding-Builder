<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Concerns\MakesCompletedIgAudit;
use Tests\TestCase;

/**
 * BB139 Fix C — Positioning Kompetitif renders readably for both data
 * shapes: the current string payload (split into paragraphs) and the
 * forward-compatible structured object {headline, current_state,
 * opportunity, timeframe} a future ticket may emit.
 */
class PositioningBackCompatTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedIgAudit;

    public function test_string_shape_renders_as_paragraphs(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'                   => $this->igMeta(),
            'scorecard'               => [],
            'competitive_positioning' => 'Di pasar laundry Kota Malang brand ini belum menonjol. Diferensiasi potensial belum dikomunikasikan. Dengan perbaikan bio dan konten, posisi bisa naik dalam 3-6 bulan.',
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('positioning-card', $html);
        $this->assertStringContainsString('positioning-paragraph', $html);
        $this->assertStringContainsString('Di pasar laundry Kota Malang', $html);
    }

    public function test_structured_object_shape_renders_headline_and_fields(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'                   => $this->igMeta(),
            'scorecard'               => [],
            'competitive_positioning' => [
                'headline'      => 'Diferensiasi potensial yang belum dikomunikasikan',
                'current_state' => 'Di pasar laundry Kota Malang, brand belum punya posisi yang jelas.',
                'opportunity'   => 'Dengan perbaikan bio dan konten edukatif, brand bisa menonjol.',
                'timeframe'     => 'Potensi pencapaian: 3-6 bulan',
            ],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('positioning-headline', $html);
        $this->assertStringContainsString('Diferensiasi potensial yang belum dikomunikasikan', $html);
        $this->assertStringContainsString('Di pasar laundry Kota Malang', $html);
        $this->assertStringContainsString('Potensi pencapaian: 3-6 bulan', $html);
    }
}
