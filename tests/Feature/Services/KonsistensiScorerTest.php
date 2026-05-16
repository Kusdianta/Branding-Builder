<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\DTO\EvidenceItem;
use App\DTO\PillarScore;
use App\Models\ScoringRubric;
use App\Services\ClaudeService;
use App\Services\Scoring\KonsistensiScorer;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10 BB57 + BB58 — vision + fallback scorer coverage.
 *
 * ClaudeService is fully mocked so no live Anthropic calls hit CI.
 * Verifies path selection (vision vs fallback), score hydration from
 * vision response, and the 60-point cap on the fallback path.
 */
class KonsistensiScorerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function context(): array
    {
        return [
            'brand_name'               => 'Less Worry',
            'instagram_url'            => 'https://www.instagram.com/lessworry.id/',
            'website_url'              => '',
            'gmaps_url'                => 'https://maps.app.goo.gl/x',
            'whatsapp_business_active' => true,
            'tiktok_url'               => '',
            'outlet_photo_paths'       => [],
        ];
    }

    private function evidenceWithAssets(): array
    {
        return [
            'instagram_audit' => [
                'profile_pic_path' => 'audits/x/profile_pic.png',
                'screenshot_path'  => 'audits/x/screenshot.png',
            ],
            'gmaps_scrape' => [
                'gmaps_screenshot_path' => 'audits/x/gmaps_screenshot.png',
            ],
            'places_api' => null,
        ];
    }

    private function visionResponse(): array
    {
        return [
            'color_consistency'        => ['score' => 85, 'observations' => 'Palet warna hijau-putih konsisten antar touchpoint.'],
            'typography_consistency'   => ['score' => 70, 'observations' => 'Tipografi sans-serif konsisten di IG dan GMaps header.'],
            'logo_consistency'         => ['score' => 90, 'observations' => 'Logo "LW" muncul di profile pic dan grid cover photos.'],
            'imagery_tone'             => ['score' => 65, 'observations' => 'Nada imagery mix lifestyle dan operational.'],
            'overall_visual_coherence' => ['score' => 80, 'summary' => 'Visual brand cukup konsisten dengan ruang untuk perbaikan imagery.'],
            'touchpoints_analyzed'     => ['instagram_profile_pic', 'instagram_grid', 'gmaps_page'],
            'limitations'              => [],
        ];
    }

    #[Test]
    public function vision_path_engages_when_at_least_one_visual_asset_is_present(): void
    {
        $claude = Mockery::mock(ClaudeService::class);
        $claude->shouldReceive('analyzeBrandConsistency')
            ->once()
            ->andReturn($this->visionResponse());

        $scorer = new KonsistensiScorer($claude);

        $score = $scorer->scoreFromEvidence($this->evidenceWithAssets(), $this->context());

        $this->assertInstanceOf(PillarScore::class, $score);
        $this->assertSame(ScoringRubric::PILLAR_KONSISTENSI, $score->pillarSlug);

        // Weighted average: 85*0.35 + 70*0.15 + 90*0.25 + 65*0.25 = 29.75 + 10.5 + 22.5 + 16.25 = 79
        $this->assertSame(79, $score->score);
        $this->assertSame('vision_multimodal', $score->scoreBreakdown['analysis_path']);
        $this->assertSame(85, $score->subBucketScores['color_consistency']);
        $this->assertSame(['instagram_profile_pic', 'instagram_grid', 'gmaps_page'], $score->scoreBreakdown['touchpoints_analyzed']);
        $this->assertContains('instagram_screenshot', $score->scoreBreakdown['data_source']);
        $this->assertContains('gmaps_screenshot', $score->scoreBreakdown['data_source']);
    }

    #[Test]
    public function vision_path_produces_evidence_items_with_impact_polarity(): void
    {
        $claude = Mockery::mock(ClaudeService::class);
        $claude->shouldReceive('analyzeBrandConsistency')->once()->andReturn($this->visionResponse());

        $score = (new KonsistensiScorer($claude))->scoreFromEvidence(
            $this->evidenceWithAssets(),
            $this->context(),
        );

        $impacts = collect($score->evidence)->mapWithKeys(
            fn (EvidenceItem $e) => [$e->touchpoint => $e->impact],
        )->all();

        // color=85 (>=70) => positive; logo=90 => positive
        $this->assertSame(EvidenceItem::IMPACT_POSITIVE, $impacts['color_consistency']);
        $this->assertSame(EvidenceItem::IMPACT_POSITIVE, $impacts['logo_consistency']);
        // imagery=65 (40 < x < 70) => neutral
        $this->assertSame(EvidenceItem::IMPACT_NEUTRAL, $impacts['imagery_tone']);
    }

    #[Test]
    public function fallback_path_engages_when_no_visual_assets_present(): void
    {
        $textOnlyPillar = new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_KONSISTENSI,
            score: 75,
            evidence: [],
            reasoning: 'Touchpoint URLs present.',
            subBucketScores: ['kehadiran_digital' => 30],
        );

        $claude = Mockery::mock(ClaudeService::class);
        // Vision path NEVER called when assets missing.
        $claude->shouldNotReceive('analyzeBrandConsistency');
        // Fallback delegates to scorePillar.
        $claude->shouldReceive('scorePillar')
            ->with(ScoringRubric::PILLAR_KONSISTENSI, Mockery::any())
            ->once()
            ->andReturn($textOnlyPillar);

        $scorer = new KonsistensiScorer($claude);

        $score = $scorer->scoreFromEvidence(
            evidence: [
                'instagram_audit' => null,
                'gmaps_scrape'    => null,
                'places_api'      => null,
            ],
            context: $this->context(),
        );

        $this->assertSame('fallback_text_only', $score->scoreBreakdown['analysis_path']);
        $this->assertTrue($score->scoreBreakdown['fallback_cap_applied'], 'score 75 should be capped to 60');
        $this->assertSame(75, $score->scoreBreakdown['score_pre_cap']);
        $this->assertSame(60, $score->score);
        $this->assertStringContainsString('fallback', $score->reasoning);
        $this->assertStringContainsString('60/100', $score->reasoning);
    }

    #[Test]
    public function fallback_path_does_not_cap_when_underlying_score_is_already_below_60(): void
    {
        $textOnlyPillar = new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_KONSISTENSI,
            score: 42,
            evidence: [],
            reasoning: 'Weak touchpoint coverage.',
        );

        $claude = Mockery::mock(ClaudeService::class);
        $claude->shouldReceive('scorePillar')->andReturn($textOnlyPillar);

        $score = (new KonsistensiScorer($claude))->scoreFromEvidence(
            evidence: ['instagram_audit' => null, 'gmaps_scrape' => null, 'places_api' => null],
            context: $this->context(),
        );

        $this->assertSame(42, $score->score);
        $this->assertFalse($score->scoreBreakdown['fallback_cap_applied']);
    }

    #[Test]
    public function vision_path_engages_when_only_gmaps_screenshot_is_present(): void
    {
        $claude = Mockery::mock(ClaudeService::class);
        $claude->shouldReceive('analyzeBrandConsistency')->once()->andReturn($this->visionResponse());

        $score = (new KonsistensiScorer($claude))->scoreFromEvidence(
            evidence: [
                'instagram_audit' => null,
                'gmaps_scrape'    => ['gmaps_screenshot_path' => 'audits/x/gmaps_screenshot.png'],
                'places_api'      => null,
            ],
            context: $this->context(),
        );

        $this->assertSame('vision_multimodal', $score->scoreBreakdown['analysis_path']);
        $this->assertContains('gmaps_screenshot', $score->scoreBreakdown['data_source']);
    }
}
