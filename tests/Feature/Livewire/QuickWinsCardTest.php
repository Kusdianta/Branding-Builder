<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Concerns\MakesCompletedIgAudit;
use Tests\TestCase;

/**
 * BB139 Fix C — Quick Wins render as numbered action/detail cards.
 * The em-dash heuristic splits "Aksi — penjelasan" into a bold action
 * line plus a muted detail line; bullets without an em-dash put the
 * whole text in the action line and omit the detail.
 */
class QuickWinsCardTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedIgAudit;

    public function test_quick_wins_render_as_numbered_cards(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'      => $this->igMeta(),
            'scorecard'  => [],
            'quick_wins' => [
                'Ganti bio sekarang — bisa dilakukan dalam 5 menit tanpa biaya apapun.',
                'Pin 3 post terbaik ke top grid',
            ],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('quickwin-card', $html);
        $this->assertStringContainsString('quickwin-number', $html);
        $this->assertStringContainsString('quickwin-action', $html);
    }

    public function test_em_dash_splits_action_from_detail(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'      => $this->igMeta(),
            'scorecard'  => [],
            'quick_wins' => ['Ganti bio sekarang — bisa dilakukan dalam 5 menit tanpa biaya apapun.'],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        // Action line carries the verb phrase; detail line carries the rest.
        $this->assertStringContainsString('quickwin-detail', $html);
        $this->assertStringContainsString('Ganti bio sekarang', $html);
        $this->assertStringContainsString('bisa dilakukan dalam 5 menit tanpa biaya apapun.', $html);
    }

    public function test_bullet_without_em_dash_has_no_detail_line(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'      => $this->igMeta(),
            'scorecard'  => [],
            'quick_wins' => ['Pin 3 post terbaik ke top grid'],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('Pin 3 post terbaik ke top grid', $html);
        $this->assertStringNotContainsString('quickwin-detail', $html);
    }
}
