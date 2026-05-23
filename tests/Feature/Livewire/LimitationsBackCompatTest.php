<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Concerns\MakesCompletedIgAudit;
use Tests\TestCase;

/**
 * BB139 Fix C — Keterbatasan Analisis renders as tag-style cards for
 * both data shapes: the current string list and the forward-compatible
 * array-of-objects {what, impact} a future ticket may emit.
 */
class LimitationsBackCompatTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedIgAudit;

    public function test_string_list_shape_renders_as_cards(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'       => $this->igMeta(),
            'scorecard'   => [],
            'limitations' => ['Caption post #7-12 tidak ter-scrape sehingga analisis hanya berdasar 6 post pertama.'],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('limitation-card', $html);
        $this->assertStringContainsString('limitation-what', $html);
        $this->assertStringContainsString('Caption post #7-12 tidak ter-scrape', $html);
    }

    public function test_object_shape_renders_what_and_impact(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'       => $this->igMeta(),
            'scorecard'   => [],
            'limitations' => [
                ['what' => 'Tanggal publikasi semua post tidak diketahui', 'impact' => 'Frekuensi posting hanya estimasi'],
            ],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('limitation-what', $html);
        $this->assertStringContainsString('Tanggal publikasi semua post tidak diketahui', $html);
        $this->assertStringContainsString('limitation-impact', $html);
        $this->assertStringContainsString('Frekuensi posting hanya estimasi', $html);
    }
}
