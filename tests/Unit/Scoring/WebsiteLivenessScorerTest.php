<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\WebsiteLivenessScorer;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 12c.2-rubric-alignment BB116 — WebsiteLivenessScorer tests.
 * Uses Laravel's Http::fake so no real network traffic.
 */
class WebsiteLivenessScorerTest extends TestCase
{
    public function test_empty_url_returns_zero_with_unavailable_reason(): void
    {
        $result = (new WebsiteLivenessScorer())->check(null);
        $this->assertSame(0, $result['score']);
        $this->assertFalse($result['is_live']);
        $this->assertNotNull($result['unavailable_reason']);
        $this->assertStringContainsString('input form audit', $result['source']);
    }

    public function test_2xx_response_yields_full_score(): void
    {
        Http::fake([
            'https://laundrybersih.example.id/*' => Http::response('<html></html>', 200),
        ]);

        $result = (new WebsiteLivenessScorer())->check('https://laundrybersih.example.id/');
        $this->assertSame(20, $result['score']);
        $this->assertTrue($result['is_live']);
        $this->assertSame(200, $result['evidence']['http_status']);
    }

    public function test_404_response_yields_zero_with_status_evidence(): void
    {
        Http::fake([
            'https://gone.example.id/*' => Http::response('<html>Not Found</html>', 404),
        ]);

        $result = (new WebsiteLivenessScorer())->check('https://gone.example.id/');
        $this->assertSame(0, $result['score']);
        $this->assertFalse($result['is_live']);
        $this->assertSame(404, $result['evidence']['http_status']);
        $this->assertNotNull($result['unavailable_reason']);
    }

    public function test_source_attribution_is_always_present(): void
    {
        Http::fake([
            'https://*.example.id/*' => Http::response('<html></html>', 200),
        ]);

        foreach ([null, '', 'https://laundrybersih.example.id/'] as $url) {
            $result = (new WebsiteLivenessScorer())->check($url);
            $this->assertNotEmpty($result['source']);
            $this->assertStringStartsWith('Sumber:', $result['source']);
        }
    }
}
