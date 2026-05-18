<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Services\ClaudeService;
use App\Services\Scoring\KonsistensiScorer;
use App\Models\BrandAudit;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB117 — KonsistensiScorer v3 path tests for the deterministic
 * sub-buckets. Vision branch is verified via the existing
 * KonsistensiScorerTest (DB-driven feature spec) and live re-audit;
 * here we focus on:
 *   - kehadiran_digital count → tier mapping
 *   - kelengkapan_layanan variety_count → tier mapping
 *   - transparansi_harga reads detected from evidence
 *
 * The vision call is bypassed by passing no visual assets in evidence
 * (collectVisualAssets returns paths=[]); scoreKonsistensiVisualV3
 * returns 0 in that case without invoking Claude.
 */
class KonsistensiScorerV3Test extends TestCase
{
    private KonsistensiScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $claude = Mockery::mock(ClaudeService::class);
        // No expectation set — vision branch should not be reached when
        // evidence carries no visual assets.
        $this->scorer = new KonsistensiScorer($claude);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function emptyEvidence(array $priceList = ['detected' => false, 'method' => 'fallback', 'confidence' => 0.0]): array
    {
        return [
            'instagram_audit'      => [],
            'gmaps_scrape'         => [],
            'places_api'           => ['photos' => []],
            'price_list_detection' => $priceList,
        ];
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            '_wizard_version'           => BrandAudit::WIZARD_V3,
            'brand_name'                => 'Test Laundry',
            'variety_count'             => 1,
            'instagram_url'             => '',
            'website_url'               => '',
            'gmaps_url'                 => '',
            'tiktok_url'                => '',
            'whatsapp_business_active'  => false,
            'outlet_photo_paths'        => [],
        ], $overrides);
    }

    #[Test]
    public function v3_kehadiran_full_five_touchpoints_awards_40(): void
    {
        $score = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context([
            'instagram_url'             => 'https://instagram.com/test',
            'website_url'               => 'https://test.com',
            'gmaps_url'                 => 'https://maps.google.com/test',
            'tiktok_url'                => 'https://tiktok.com/@test',
            'whatsapp_business_active'  => true,
        ]));
        $this->assertSame(40, $score->subBucketScores['kehadiran_digital']);
    }

    #[Test]
    public function v3_kehadiran_no_touchpoints_awards_zero(): void
    {
        $score = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context());
        $this->assertSame(0, $score->subBucketScores['kehadiran_digital']);
    }

    #[Test]
    public function v3_kelengkapan_layanan_uses_variety_count_tiers(): void
    {
        $four = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context(['variety_count' => 4]));
        $this->assertSame(15, $four->subBucketScores['kelengkapan_layanan']);

        $three = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context(['variety_count' => 3]));
        $this->assertSame(10, $three->subBucketScores['kelengkapan_layanan']);

        $two = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context(['variety_count' => 2]));
        $this->assertSame(5, $two->subBucketScores['kelengkapan_layanan']);

        $one = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context(['variety_count' => 1]));
        $this->assertSame(0, $one->subBucketScores['kelengkapan_layanan']);
    }

    #[Test]
    public function v3_transparansi_harga_detects_when_evidence_flagged(): void
    {
        $detected = $this->scorer->scoreFromEvidence(
            $this->emptyEvidence(['detected' => true, 'method' => 'caption_only', 'confidence' => 0.9]),
            $this->context(),
        );
        $this->assertSame(10, $detected->subBucketScores['transparansi_harga']);

        $undetected = $this->scorer->scoreFromEvidence(
            $this->emptyEvidence(['detected' => false, 'method' => 'fallback', 'confidence' => 0.0]),
            $this->context(),
        );
        $this->assertSame(0, $undetected->subBucketScores['transparansi_harga']);
    }

    #[Test]
    public function v3_visual_score_is_zero_when_no_assets_available(): void
    {
        $score = $this->scorer->scoreFromEvidence($this->emptyEvidence(), $this->context());
        $this->assertSame(0, $score->subBucketScores['konsistensi_visual']);
        $this->assertSame('no_assets', $score->scoreBreakdown['konsistensi_visual']['analysis_path']);
    }

    #[Test]
    public function v3_total_caps_at_100(): void
    {
        $score = $this->scorer->scoreFromEvidence(
            $this->emptyEvidence(['detected' => true, 'method' => 'caption_only', 'confidence' => 0.9]),
            $this->context([
                'variety_count'             => 5,
                'instagram_url'             => 'https://instagram.com/test',
                'website_url'               => 'https://test.com',
                'gmaps_url'                 => 'https://maps.google.com/test',
                'tiktok_url'                => 'https://tiktok.com/@test',
                'whatsapp_business_active'  => true,
            ]),
        );
        // 40 + 0 (no vision) + 15 + 10 = 65
        $this->assertSame(65, $score->score);
        $this->assertLessThanOrEqual(100, $score->score);
    }
}
