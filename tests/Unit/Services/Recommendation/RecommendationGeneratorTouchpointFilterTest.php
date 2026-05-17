<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendation;

use App\Models\BrandAudit;
use App\Services\Recommendation\RecommendationGenerator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * BB104 — when an audit's touchpoints array does not include a given
 * URL, RecommendationGenerator's user prompt MUST NOT mention it at
 * all. This is the structural fix for the "ketidakjelasan status
 * WhatsApp Business" hallucination pattern.
 *
 * We poke at the protected userPrompt() via reflection so we can
 * inspect the literal text the LLM would receive without making a
 * network call.
 */
class RecommendationGeneratorTouchpointFilterTest extends TestCase
{
    #[Test]
    public function absent_whatsapp_is_not_mentioned_in_user_prompt(): void
    {
        $prompt = $this->renderPrompt([
            'instagram_url' => 'https://instagram.com/foo',
            'gmaps_url'     => 'https://maps.google.com/?cid=1',
            // No whatsapp_url, no tiktok_url, no website_url.
        ]);

        $this->assertStringNotContainsString('whatsapp', strtolower($prompt));
        $this->assertStringNotContainsString('tiktok',   strtolower($prompt));
        $this->assertStringNotContainsString('website',  strtolower($prompt));
        $this->assertStringContainsString('instagram.com/foo', $prompt);
        $this->assertStringContainsString('Touchpoints AKTIF', $prompt);
    }

    #[Test]
    public function present_whatsapp_url_is_rendered_under_active_block(): void
    {
        $prompt = $this->renderPrompt([
            'instagram_url' => 'https://instagram.com/foo',
            'whatsapp_url'  => 'https://wa.me/628123456789',
        ]);

        $this->assertStringContainsString('whatsapp_url: https://wa.me/628123456789', $prompt);
    }

    #[Test]
    public function empty_touchpoints_render_explicit_fallback_line(): void
    {
        $prompt = $this->renderPrompt([]);

        $this->assertStringContainsString(
            '(tidak ada touchpoint digital aktif yang diberikan)',
            $prompt,
        );
    }

    private function renderPrompt(array $touchpoints): string
    {
        // BrandAudit has no factory wired up — hydrate a transient model
        // with just the fields userPrompt() reads. We never persist it,
        // so we can sidestep DB schema entirely (no RefreshDatabase needed
        // for the prompt rendering path under test).
        $audit = new BrandAudit();
        $audit->setRawAttributes([
            'brand_name'        => 'Test Brand',
            'city'              => 'Jakarta',
            'service_type'      => 'kiloan',
            'overall_score'     => 50,
            'touchpoints'       => json_encode($touchpoints),
            'pillar_scores'     => json_encode([]),
            'sub_bucket_scores' => json_encode([]),
            'gmaps_reviews'     => json_encode([]),
            'instagram_audit'   => json_encode([]),
        ], true);

        $generator = app(RecommendationGenerator::class);
        $method = new ReflectionMethod($generator, 'userPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($generator, $audit);
    }
}
