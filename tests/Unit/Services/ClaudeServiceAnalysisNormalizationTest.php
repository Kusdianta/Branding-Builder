<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ClaudeService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * W7.1 item 8: targeted tests for analyzeInstagramProfile's normalization
 * pass. The normalizer recovers two common Claude output deviations
 * (nested-wrapping + missing top-level keys) without burning a retry —
 * dropping the rate at which the strict missingAnalysisKeys check
 * triggers the retry loop on essentially-correct responses.
 *
 * Construction-bypass via reflection so we don't need a live Anthropic
 * key just to exercise pure helpers.
 */
class ClaudeServiceAnalysisNormalizationTest extends TestCase
{
    private function makeServiceWithoutConstructor(): ClaudeService
    {
        return (new ReflectionClass(ClaudeService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalize(array $payload): array
    {
        $svc    = $this->makeServiceWithoutConstructor();
        $method = (new ReflectionClass($svc))->getMethod('normalizeAnalysisResponse');
        $method->setAccessible(true);

        /** @var array<string,mixed> $out */
        $out = $method->invoke($svc, $payload);
        return $out;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function missingKeys(array $payload): array
    {
        $svc    = $this->makeServiceWithoutConstructor();
        $method = (new ReflectionClass($svc))->getMethod('missingAnalysisKeys');
        $method->setAccessible(true);

        /** @var list<string> $out */
        $out = $method->invoke($svc, $payload);
        return $out;
    }

    /** @return array<string,mixed> */
    private function emptyDefaults(): array
    {
        $svc    = $this->makeServiceWithoutConstructor();
        $method = (new ReflectionClass($svc))->getMethod('emptyAnalysisDefaults');
        $method->setAccessible(true);

        /** @var array<string,mixed> $out */
        $out = $method->invoke($svc);
        return $out;
    }

    #[Test]
    public function it_unwraps_nested_wrapping_pattern(): void
    {
        // Claude wrapped the structure under a single 'analysis' key.
        // The normalizer must unwrap before processing so downstream
        // logic sees the real top-level keys at root.
        $inner = $this->emptyDefaults();
        $inner['executive_summary'] = 'Less Worry Laundry adalah brand laundry di Bandung dengan 2.930 followers...';
        $inner['competitive_positioning'] = 'Positioning lokal kuat di segmen rumah tangga Bandung.';

        $wrapped = ['analysis' => $inner];

        $out = $this->normalize($wrapped);

        $this->assertSame(
            'Less Worry Laundry adalah brand laundry di Bandung dengan 2.930 followers...',
            $out['executive_summary'],
        );
        $this->assertSame(
            'Positioning lokal kuat di segmen rumah tangga Bandung.',
            $out['competitive_positioning'],
        );
        $this->assertArrayHasKey('profile_branding', $out);
        $this->assertArrayHasKey('scorecard', $out);
        // After unwrap, the payload should pass schema validation —
        // no top-level keys missing.
        $this->assertSame([], $this->missingKeys($out));
    }

    #[Test]
    public function it_backfills_missing_top_level_keys_with_defaults_and_appends_limitations(): void
    {
        // Claude returned a partial structure — only 3 of the 11 keys
        // present. The normalizer must fill in the other 8 with shape-
        // appropriate empties AND append per-key auto-backfill notes
        // into limitations[].
        $partial = [
            'executive_summary'       => 'Partial analysis text',
            'priority_recommendations' => [
                ['priority' => 'tinggi', 'title' => 'X', 'description' => 'Y', 'effort' => 'rendah', 'impact' => 'tinggi'],
            ],
            'limitations' => ['Pre-existing limitation'],
        ];

        $out = $this->normalize($partial);

        // All 11 keys present; schema validation passes.
        $this->assertSame([], $this->missingKeys($out));

        // Pre-existing values preserved.
        $this->assertSame('Partial analysis text', $out['executive_summary']);
        $this->assertCount(1, $out['priority_recommendations']);

        // Backfilled keys carry the schema-appropriate empty shape.
        $this->assertSame(
            ['reels' => 0, 'carousel' => 0, 'static' => 0],
            $out['content_analysis']['content_type_breakdown'],
        );
        $this->assertSame([], $out['content_gaps']);
        $this->assertSame([], $out['quick_wins']);
        $this->assertSame('', $out['competitive_positioning']);

        // Pre-existing limitation is preserved, auto-backfill notes
        // are appended — one entry per backfilled top-level key.
        $this->assertContains('Pre-existing limitation', $out['limitations']);
        $backfillNotes = array_filter(
            $out['limitations'],
            fn ($e) => is_string($e) && str_starts_with($e, 'Auto-backfilled missing top-level key'),
        );
        // 8 keys were missing (11 expected − 3 present): profile_branding,
        // content_analysis, engagement_analysis, growth_positioning,
        // content_gaps, quick_wins, competitive_positioning, scorecard.
        $this->assertCount(8, $backfillNotes);
        $this->assertNotEmpty(array_filter(
            $backfillNotes,
            fn ($e) => str_contains($e, "'competitive_positioning'"),
        ));
    }

    #[Test]
    public function it_preserves_valid_response_with_no_modifications(): void
    {
        // All 11 keys present and well-shaped — normalizer should pass
        // through unchanged (no backfill notes added, no unwrap).
        $valid                          = $this->emptyDefaults();
        $valid['executive_summary']     = 'A real executive summary.';
        $valid['competitive_positioning'] = 'Real positioning text.';
        $valid['limitations']           = ['Real pre-existing limitation entry.'];

        $out = $this->normalize($valid);

        $this->assertSame($valid, $out);
        // limitations should be the input list — NO auto-backfill notes
        // since nothing was backfilled.
        $this->assertSame(['Real pre-existing limitation entry.'], $out['limitations']);
    }

    #[Test]
    public function it_does_not_unwrap_when_match_threshold_not_met(): void
    {
        // Only 2 of the 11 expected keys appear inside the root — below
        // the 5-key threshold. The wrapper is more likely a coincidence
        // than a real nested-analysis pattern; normalizer must NOT
        // unwrap, but the backfill pass will still fill in everything
        // missing at the actual root level.
        $shallow = [
            'someUnknownWrapper' => [
                'executive_summary' => 'a',
                'limitations'       => [],
            ],
        ];

        $out = $this->normalize($shallow);

        // The wrapper key stays at root (it's an extra/unknown key the
        // validator doesn't care about). The 11 expected keys are
        // backfilled at root with defaults.
        $this->assertArrayHasKey('someUnknownWrapper', $out);
        $this->assertArrayHasKey('executive_summary', $out);
        // BB131 — executive_summary is now a structured object; the
        // backfill default is the empty-structured shape, not ''.
        $this->assertSame(
            ['headline' => '', 'kekuatan' => [], 'area_perbaikan' => [], 'konteks' => ''],
            $out['executive_summary'],
        );
        // Schema validation passes because all 11 keys are present at root.
        $this->assertSame([], $this->missingKeys($out));
    }

    #[Test]
    public function it_backfills_scorecard_overall_when_outer_scorecard_present_but_inner_missing(): void
    {
        // Edge case: Claude returned scorecard with the 7 sub-scores
        // but forgot the 'overall' leaf. missingAnalysisKeys flags
        // scorecard.overall specifically; the normalizer must patch
        // the leaf in place so retry isn't triggered.
        $defaults                 = $this->emptyDefaults();
        $payload                  = $defaults;
        $payload['scorecard']     = $defaults['scorecard'];
        unset($payload['scorecard']['overall']);

        $out = $this->normalize($payload);

        $this->assertArrayHasKey('overall', $out['scorecard']);
        $this->assertSame(['score' => 0, 'grade' => 'F'], $out['scorecard']['overall']);
        $this->assertSame([], $this->missingKeys($out));
    }
}
