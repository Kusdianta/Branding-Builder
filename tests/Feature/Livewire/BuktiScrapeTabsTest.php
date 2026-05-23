<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Livewire\Concerns\MakesCompletedIgAudit;
use Tests\TestCase;

/**
 * BB139 Fix D — the Bukti Scrape "Feed" tab is removed (it duplicated
 * Profile). Only Profile + Reels render even when the worker still
 * persists a feed screenshot path.
 */
class BuktiScrapeTabsTest extends TestCase
{
    use RefreshDatabase;
    use MakesCompletedIgAudit;

    public function test_feed_tab_is_hidden_profile_and_reels_remain(): void
    {
        $audit = $this->makeIgAudit([
            '_meta' => $this->igMeta([
                'screenshot_paths' => [
                    'profile' => 'audits/1/instagram/profile.png',
                    'feed'    => 'audits/1/instagram/feed.png',
                    'reels'   => 'audits/1/instagram/reels.png',
                ],
            ]),
            'scorecard' => [],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('Bukti Scrape', $html);
        // Profile + Reels tabs present...
        $this->assertStringContainsString("proofTab = 'profile'", $html);
        $this->assertStringContainsString("proofTab = 'reels'", $html);
        // ...Feed tab gone even though the worker still wrote feed.png.
        $this->assertStringNotContainsString("proofTab = 'feed'", $html);
        $this->assertStringNotContainsString("proofTab === 'feed'", $html);
    }

    public function test_legacy_single_screenshot_renders_as_profile(): void
    {
        $audit = $this->makeIgAudit([
            '_meta'     => $this->igMeta(['screenshot_path' => 'audits/1/instagram/screenshot.png']),
            'scorecard' => [],
        ]);
        $this->actingAs($audit->user);

        $html = (string) Livewire::test('brand-audit-wizard', ['token' => $audit->session_token])->html();

        $this->assertStringContainsString('Bukti Scrape', $html);
        $this->assertStringNotContainsString("proofTab === 'feed'", $html);
    }
}
