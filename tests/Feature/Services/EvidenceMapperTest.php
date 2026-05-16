<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BrandAudit;
use App\Services\EvidenceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 10 BB54: EvidenceMapper coverage — verifies resolution precedence
 * (evidence first, then legacy column fallback, then empty defaults).
 */
class EvidenceMapperTest extends TestCase
{
    use RefreshDatabase;

    private EvidenceMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new EvidenceMapper();
    }

    private function audit(array $overrides = []): BrandAudit
    {
        return BrandAudit::create(array_merge([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_ANALYZING,
            'expires_at'    => now()->addDays(30),
        ], $overrides));
    }

    #[Test]
    public function places_api_returns_evidence_slice_when_present(): void
    {
        $a = $this->audit([
            'audit_evidence' => [
                'places_api' => [
                    'rating' => 4.7, 'review_count' => 142,
                    'keyword_hits' => ['positive' => ['bersih'], 'negative' => []],
                    'sampled_reviews' => [['author' => 'X', 'text' => 'good']],
                ],
            ],
        ]);

        $r = $this->mapper->placesApi($a);
        $this->assertSame(4.7, $r['rating']);
        $this->assertSame(142, $r['review_count']);
        $this->assertSame(['bersih'], $r['keyword_hits']['positive']);
        $this->assertCount(1, $r['sampled_reviews']);
    }

    #[Test]
    public function places_api_returns_empty_defaults_when_evidence_absent(): void
    {
        $a = $this->audit(['audit_evidence' => null]);
        $r = $this->mapper->placesApi($a);
        $this->assertSame(0.0, $r['rating']);
        $this->assertSame(0, $r['review_count']);
        $this->assertSame([], $r['sampled_reviews']);
    }

    #[Test]
    public function full_reviews_prefers_evidence_then_legacy_then_empty(): void
    {
        // Evidence path
        $aEv = $this->audit([
            'audit_evidence' => ['gmaps_scrape' => ['reviews' => [
                ['text' => 'good', 'rating_value' => 5],
                ['text' => '', 'rating_value' => 4], // dropped (empty text)
            ]]],
        ]);
        $this->assertCount(1, $this->mapper->fullReviews($aEv));

        // Legacy fallback path
        $aLegacy = $this->audit([
            'audit_evidence' => null,
            'gmaps_reviews'  => ['reviews' => [['text' => 'legacy', 'rating_value' => 4]]],
        ]);
        $reviews = $this->mapper->fullReviews($aLegacy);
        $this->assertCount(1, $reviews);
        $this->assertSame('legacy', $reviews[0]['text']);

        // Neither
        $aEmpty = $this->audit();
        $this->assertSame([], $this->mapper->fullReviews($aEmpty));
    }

    #[Test]
    public function instagram_raw_extracts_visual_paths(): void
    {
        $a = $this->audit([
            'audit_evidence' => [
                'instagram_audit' => [
                    'profile_pic_path'     => 'p/x.png',
                    'screenshot_path'      => 's/y.png',
                    'post_thumbnail_paths' => ['t/0.jpg', 't/1.jpg'],
                    'username'             => 'lessworry.id',
                ],
            ],
        ]);
        $r = $this->mapper->instagramRaw($a);
        $this->assertSame('p/x.png', $r['profile_pic_path']);
        $this->assertSame('s/y.png', $r['screenshot_path']);
        $this->assertCount(2, $r['post_thumbnail_paths']);
        $this->assertSame('lessworry.id', $r['username']);
    }

    #[Test]
    public function instagram_raw_returns_empty_when_evidence_missing_no_legacy_for_raw(): void
    {
        // BB51 migration comment: legacy instagram_audit column never
        // held the raw scrape (only the analysis). instagramRaw() has
        // no legacy fallback by design.
        $a = $this->audit(['instagram_audit' => ['scorecard' => ['x' => 1]]]);
        $r = $this->mapper->instagramRaw($a);
        $this->assertNull($r['profile_pic_path']);
        $this->assertNull($r['screenshot_path']);
        $this->assertSame([], $r['post_thumbnail_paths']);
    }

    #[Test]
    public function instagram_analysis_falls_back_to_legacy_column(): void
    {
        $a = $this->audit([
            'instagram_audit' => ['executive_summary' => 'legacy summary'],
        ]);
        $r = $this->mapper->instagramAnalysis($a);
        $this->assertSame('legacy summary', $r['executive_summary']);
    }

    #[Test]
    public function validation_helper_marks_low_confidence_as_warning(): void
    {
        $aWarn = $this->audit([
            'audit_evidence' => ['validation' => ['confidence' => 0.3]],
        ]);
        $r = $this->mapper->validation($aWarn);
        $this->assertTrue($r['has_warning']);

        $aOk = $this->audit([
            'audit_evidence' => ['validation' => ['confidence' => 0.9]],
        ]);
        $this->assertFalse($this->mapper->validation($aOk)['has_warning']);

        $aNone = $this->audit();
        $this->assertNull($this->mapper->validation($aNone)['validation']);
        $this->assertFalse($this->mapper->validation($aNone)['has_warning']);
    }
}
