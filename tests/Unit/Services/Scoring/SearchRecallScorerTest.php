<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Services\Scoring\SearchRecallScorer;
use App\Services\Scoring\Support\BrandSearchQuery;
use App\Services\Scoring\Support\LocationDetector;
use GuzzleHttp\Client as GuzzleClient;
use Nema\WorkerClient\NemaWorkerClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchRecallScorerTest extends TestCase
{
    private SearchRecallScorer $scorer;

    private BrandSearchQuery $brandSearchQuery;

    private LocationDetector $locationDetector;

    protected function setUp(): void
    {
        parent::setUp();

        // scoreFromSuggestions() bypasses the worker entirely. Instantiate a
        // real client against an unused base URL — no HTTP is performed by the
        // pure-scoring path under test.
        $worker = new NemaWorkerClient(
            baseUrl: 'http://127.0.0.1:0',
            apiKey: 'test',
            timeoutSeconds: 1.0,
            http: new GuzzleClient(),
        );

        $this->brandSearchQuery = new BrandSearchQuery();
        $this->locationDetector = new LocationDetector();
        $this->scorer = new SearchRecallScorer(
            $worker,
            $this->brandSearchQuery,
            $this->locationDetector,
        );
    }

    // ─── Brand recognition (cap 15) ────────────────────────────────────────────

    #[Test]
    public function brand_recognition_scores_15_when_stem_appears_in_top_three(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo laundry',
            stem: 'foo',
            suggestions: ['foo bar', 'foo qux', 'foo zzz'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(15, $result['breakdown']['signals']['brand_recognition']['score']);
        $this->assertSame(0, $result['breakdown']['signals']['brand_recognition']['first_match_position']);
    }

    #[Test]
    public function brand_recognition_scores_10_when_stem_first_appears_in_top_five_only(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo laundry',
            stem: 'foo',
            suggestions: ['bar', 'baz', 'qux', 'foo whatever', 'something'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(10, $result['breakdown']['signals']['brand_recognition']['score']);
        $this->assertSame(3, $result['breakdown']['signals']['brand_recognition']['first_match_position']);
    }

    #[Test]
    public function brand_recognition_scores_5_when_stem_first_appears_in_top_ten_only(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo laundry',
            stem: 'foo',
            suggestions: ['a', 'b', 'c', 'd', 'e', 'foo whatever', 'g', 'h', 'i', 'j'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(5, $result['breakdown']['signals']['brand_recognition']['score']);
        $this->assertSame(5, $result['breakdown']['signals']['brand_recognition']['first_match_position']);
    }

    #[Test]
    public function brand_recognition_scores_0_when_stem_absent_entirely(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo laundry',
            stem: 'foo',
            suggestions: ['nope', 'still nope', 'really not'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(0, $result['breakdown']['signals']['brand_recognition']['score']);
        $this->assertNull($result['breakdown']['signals']['brand_recognition']['first_match_position']);
    }

    // ─── Geographic spread (cap 15) ────────────────────────────────────────────

    #[Test]
    public function geographic_spread_scores_15_with_five_or_more_location_suggestions(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo',
            stem: 'foo',
            suggestions: [
                'foo kemang', 'foo tebet', 'foo jagakarsa',
                'foo lebak bulus', 'foo serpong',
            ],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(15, $result['breakdown']['signals']['geographic_spread']['score']);
        $this->assertSame(5, $result['breakdown']['signals']['geographic_spread']['count']);
    }

    #[Test]
    public function geographic_spread_scores_10_with_three_or_four_location_suggestions(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo',
            stem: 'foo',
            suggestions: ['foo kemang', 'foo tebet', 'foo jagakarsa', 'foo'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(10, $result['breakdown']['signals']['geographic_spread']['score']);
        $this->assertSame(3, $result['breakdown']['signals']['geographic_spread']['count']);
    }

    #[Test]
    public function geographic_spread_scores_5_with_one_or_two_location_suggestions(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo',
            stem: 'foo',
            suggestions: ['foo', 'foo tebet'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(5, $result['breakdown']['signals']['geographic_spread']['score']);
        $this->assertSame(1, $result['breakdown']['signals']['geographic_spread']['count']);
    }

    #[Test]
    public function geographic_spread_scores_0_with_no_location_suggestions(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo',
            stem: 'foo',
            suggestions: ['foo', 'foo something'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(0, $result['breakdown']['signals']['geographic_spread']['score']);
        $this->assertSame(0, $result['breakdown']['signals']['geographic_spread']['count']);
    }

    // ─── Variant coverage (cap 5) ──────────────────────────────────────────────

    #[Test]
    public function variant_coverage_scores_5_when_non_stem_non_location_word_present(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo',
            stem: 'foo',
            suggestions: ['foo artinya'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(5, $result['breakdown']['signals']['variant_coverage']['score']);
        $this->assertContains('artinya', $result['breakdown']['signals']['variant_coverage']['variants']);
    }

    #[Test]
    public function variant_coverage_scores_0_when_only_locations_and_generic_suffixes(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'foo',
            stem: 'foo',
            suggestions: ['foo laundry', 'foo kemang', 'foo tebet', 'foo laundry tebet'],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(0, $result['breakdown']['signals']['variant_coverage']['score']);
        $this->assertSame([], $result['breakdown']['signals']['variant_coverage']['variants']);
    }

    // ─── End-to-end Less Worry fixture ─────────────────────────────────────────

    #[Test]
    public function less_worry_full_autocomplete_scores_35_of_35(): void
    {
        $result = $this->scorer->scoreFromSuggestions(
            brandName: 'Less Worry Laundry',
            stem: 'less worry',
            suggestions: [
                'less worry laundry',
                'less worry artinya',
                'less worry',
                'less worry lebak bulus',
                'less worry kemang',
                'less worry park serpong',
                'less worry tebet',
                'less worry jagakarsa',
                'less worry laundry tebet',
                'less worry laundry jagakarsa',
            ],
            fetchedAt: '2026-05-11T00:00:00+00:00',
        );

        $this->assertSame(35, $result['score']);

        $signals = $result['breakdown']['signals'];
        $this->assertSame(15, $signals['brand_recognition']['score'], 'brand_recognition');
        $this->assertSame(0,  $signals['brand_recognition']['first_match_position']);

        $this->assertSame(15, $signals['geographic_spread']['score'], 'geographic_spread');
        $this->assertGreaterThanOrEqual(5, $signals['geographic_spread']['count']);

        $this->assertSame(5, $signals['variant_coverage']['score'], 'variant_coverage');
        $this->assertContains('artinya', $signals['variant_coverage']['variants']);
        $this->assertNotContains('laundry', $signals['variant_coverage']['variants']);
        $this->assertNotContains('park',    $signals['variant_coverage']['variants']);

        $this->assertSame('deterministic_signals', $result['breakdown']['formula']);
        $this->assertSame('search_recall_v1',      $result['breakdown']['explanation_id']);
        $this->assertNotEmpty($result['breakdown']['limitations']);
    }

    // ─── Helper sanity ─────────────────────────────────────────────────────────

    #[Test]
    public function brand_search_query_strips_generic_suffixes(): void
    {
        $this->assertSame('less worry',   $this->brandSearchQuery->normalizeBrandStem('Less Worry Laundry'));
        $this->assertSame('foo bar',      $this->brandSearchQuery->normalizeBrandStem('FOO Bar Express'));
        $this->assertSame('foo bar',      $this->brandSearchQuery->normalizeBrandStem('Foo Bar Dry Clean'));
        $this->assertSame('clean joy',    $this->brandSearchQuery->normalizeBrandStem('Clean Joy Wash'));
        $this->assertSame('',             $this->brandSearchQuery->normalizeBrandStem(''));
    }

    #[Test]
    public function location_detector_recognizes_singles_and_compounds(): void
    {
        $this->assertTrue($this->locationDetector->isLocationToken('kemang'));
        $this->assertTrue($this->locationDetector->isLocationToken('tebet'));
        $this->assertTrue($this->locationDetector->isLocationToken('jagakarsa'));
        $this->assertFalse($this->locationDetector->isLocationToken('artinya'));

        $this->assertTrue($this->locationDetector->containsLocation('foo lebak bulus'));
        $this->assertTrue($this->locationDetector->containsLocation('foo park serpong'));
        $this->assertTrue($this->locationDetector->containsLocation('foo kemang'));
        $this->assertFalse($this->locationDetector->containsLocation('foo artinya'));
    }
}
