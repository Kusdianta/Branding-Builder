<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Concerns\MakesCompletedIgAudit;
use Tests\TestCase;

/**
 * BB139 Fix A — followers / following / total post render in one
 * horizontal stat row instead of a stacked vertical list.
 */
class IgStatsRowTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedIgAudit;

    public function test_stats_render_as_one_horizontal_row(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'     => $this->igMeta(['followers' => 1200, 'following' => 80, 'posts_count' => 45]),
            'scorecard' => [],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('ig-stats-row', $html);
        $this->assertStringContainsString('ig-stat-value', $html);
        $this->assertStringContainsString('ig-stat-label', $html);
    }

    public function test_stats_show_formatted_values_and_labels(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'     => $this->igMeta(['followers' => 1200, 'following' => 80, 'posts_count' => 45]),
            'scorecard' => [],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('Followers', $html);
        $this->assertStringContainsString('Following', $html);
        $this->assertStringContainsString('Total Post', $html);
        $this->assertStringContainsString('1,200', $html); // number_format(1200)
    }
}
