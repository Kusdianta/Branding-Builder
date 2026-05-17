<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendation;

use App\Models\BrandAudit;
use App\Services\Recommendation\RecommendationGenerator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 9.1 BB45: parser + fallback + prompt-shape tests for the
 * RecommendationGenerator. Mirrors the
 * ClaudeServiceAnalysisNormalizationTest pattern — uses
 * newInstanceWithoutConstructor() to skip the Anthropic API key
 * requirement, then exercises the pure parseResponse() / fallback /
 * prompt-builder helpers via reflection.
 *
 * Live LLM calls are NOT tested here — that path is exercised by the
 * end-to-end smoke command (BB44). These tests lock the deterministic
 * shape transforms so a Claude response that PASSES JSON parsing but
 * deviates from the expected schema doesn't silently corrupt the PDF.
 */
class RecommendationGeneratorTest extends TestCase
{
    private function makeGenerator(): RecommendationGenerator
    {
        return (new ReflectionClass(RecommendationGenerator::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array<string,mixed>
     */
    private function parse(array $decoded): array
    {
        $gen    = $this->makeGenerator();
        $method = (new ReflectionClass($gen))->getMethod('parseResponse');
        $method->setAccessible(true);
        /** @var array<string,mixed> $out */
        $out = $method->invoke($gen, $decoded);
        return $out;
    }

    /** @return array<string,mixed> */
    private function fallback(): array
    {
        $gen    = $this->makeGenerator();
        $method = (new ReflectionClass($gen))->getMethod('fallbackPayload');
        $method->setAccessible(true);
        /** @var array<string,mixed> $out */
        $out = $method->invoke($gen);
        return $out;
    }

    private function systemPrompt(): string
    {
        $gen    = $this->makeGenerator();
        $method = (new ReflectionClass($gen))->getMethod('systemPrompt');
        $method->setAccessible(true);
        return (string) $method->invoke($gen);
    }

    private function userPrompt(BrandAudit $audit): string
    {
        $gen    = $this->makeGenerator();
        $method = (new ReflectionClass($gen))->getMethod('userPrompt');
        $method->setAccessible(true);
        return (string) $method->invoke($gen, $audit);
    }

    /** @return array<string,mixed> */
    private function validResponse(): array
    {
        $items = [];
        for ($i = 1; $i <= 5; $i++) {
            $items[] = [
                'rank'        => $i,
                'title'       => "Lengkapi Touchpoint #{$i}",
                'priority'    => $i === 1 ? 'TINGGI' : ($i <= 3 ? 'SEDANG' : 'RENDAH'),
                'effort'      => 'SEDANG',
                'impact'      => $i === 1 ? 'SANGAT TINGGI' : 'TINGGI',
                'description' => "Sentence one. Sentence two. Sentence three. Item {$i}.",
            ];
        }
        return ['recommendations' => $items];
    }

    #[Test]
    public function it_parses_valid_recommendation_response_into_5_cards(): void
    {
        $out = $this->parse($this->validResponse());

        $this->assertArrayHasKey('recommendations', $out);
        $this->assertCount(5, $out['recommendations']);
        foreach ($out['recommendations'] as $i => $card) {
            $this->assertSame($i + 1, $card['rank']);
            $this->assertSame("Lengkapi Touchpoint #" . ($i + 1), $card['title']);
            $this->assertNotEmpty($card['description']);
        }
    }

    #[Test]
    public function it_normalises_priority_effort_impact_enum_casing_and_invalid_values(): void
    {
        $payload = [
            'recommendations' => [
                [
                    'rank' => 1,
                    'title' => 'X',
                    'description' => 'Y.',
                    'priority' => 'tinggi',                  // lowercase -> TINGGI
                    'effort'   => '  sedang  ',               // whitespace + lowercase
                    'impact'   => 'sangat tinggi',            // lowercase phrase
                ],
                [
                    'rank' => 2,
                    'title' => 'X2',
                    'description' => 'Y.',
                    'priority' => 'GARBAGE',                  // unknown -> SEDANG default
                    'effort'   => 'XYZ',                      // unknown -> SEDANG default
                    'impact'   => 'NUCLEAR',                  // unknown -> SEDANG default
                ],
            ],
        ];

        $out = $this->parse($payload);

        $this->assertSame('TINGGI', $out['recommendations'][0]['priority']);
        $this->assertSame('SEDANG', $out['recommendations'][0]['effort']);
        $this->assertSame('SANGAT TINGGI', $out['recommendations'][0]['impact']);

        // Garbage values fall back to the deterministic 'SEDANG' middle
        // option so the PDF pill renderer always gets a known value.
        $this->assertSame('SEDANG', $out['recommendations'][1]['priority']);
        $this->assertSame('SEDANG', $out['recommendations'][1]['effort']);
        $this->assertSame('SEDANG', $out['recommendations'][1]['impact']);
    }

    #[Test]
    public function it_truncates_to_5_when_llm_returns_more_than_5(): void
    {
        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = [
                'rank' => $i,
                'title' => "Item {$i}",
                'description' => 'X.',
                'priority' => 'TINGGI',
                'effort'   => 'RENDAH',
                'impact'   => 'TINGGI',
            ];
        }

        $out = $this->parse(['recommendations' => $items]);

        $this->assertCount(5, $out['recommendations']);
        $this->assertSame('Item 1', $out['recommendations'][0]['title']);
        $this->assertSame('Item 5', $out['recommendations'][4]['title']);
    }

    #[Test]
    public function it_fallback_payload_returns_empty_recommendations_array(): void
    {
        $out = $this->fallback();

        $this->assertSame(['recommendations' => []], $out);
    }

    #[Test]
    public function it_skips_items_missing_title_or_description(): void
    {
        // BB37 contract: skip incomplete items so the PDF doesn't render
        // empty cards. Returns a partial list (under 5) when LLM gave us
        // a partial useful payload.
        $payload = [
            'recommendations' => [
                ['rank' => 1, 'title' => '',                 'description' => 'has body', 'priority' => 'T', 'effort' => 'R', 'impact' => 'T'],
                ['rank' => 2, 'title' => 'has title',        'description' => '',         'priority' => 'T', 'effort' => 'R', 'impact' => 'T'],
                ['rank' => 3, 'title' => 'real title 3',     'description' => 'real body 3.', 'priority' => 'TINGGI', 'effort' => 'RENDAH', 'impact' => 'TINGGI'],
            ],
        ];

        $out = $this->parse($payload);

        $this->assertCount(1, $out['recommendations']);
        $this->assertSame('real title 3', $out['recommendations'][0]['title']);
    }

    #[Test]
    public function it_handles_decoded_with_no_recommendations_key(): void
    {
        $this->assertSame(['recommendations' => []], $this->parse([]));
        $this->assertSame(['recommendations' => []], $this->parse(['something_else' => 'x']));
        $this->assertSame(['recommendations' => []], $this->parse(['recommendations' => 'not-an-array']));
    }

    #[Test]
    public function user_prompt_contains_pillar_scores_and_brand_context(): void
    {
        // Build an in-memory BrandAudit (no DB save) so we can test
        // userPrompt() output without persistence side-effects.
        $audit = new BrandAudit();
        $audit->brand_name    = 'Less Worry Laundry';
        $audit->city          = 'Bandung';
        $audit->service_type  = 'kiloan';
        $audit->overall_score = 53;
        $audit->touchpoints   = [
            'instagram_url' => 'https://www.instagram.com/lessworry.id/',
            'website_url'   => null,
            'gmaps_url'     => 'https://maps.app.goo.gl/x',
            'whatsapp_business_active' => false,
        ];
        $audit->pillar_scores = [
            'brand-recall'      => ['score' => 35],
            'brand-konsistensi' => ['score' => 70],
            'brand-experience'  => ['score' => 30],
            'digital-presence'  => ['score' => 60],
        ];
        $audit->sub_bucket_scores = ['brand-experience' => ['base' => 30]];

        $prompt = $this->userPrompt($audit);

        // Brand context surfaces.
        $this->assertStringContainsString('Less Worry Laundry', $prompt);
        $this->assertStringContainsString('Bandung', $prompt);
        $this->assertStringContainsString('53/100', $prompt);

        // All four pillar scores appear (so the LLM can rank by weakest).
        $this->assertStringContainsString('brand-recall: 35/100', $prompt);
        $this->assertStringContainsString('brand-konsistensi: 70/100', $prompt);
        $this->assertStringContainsString('brand-experience: 30/100', $prompt);
        $this->assertStringContainsString('digital-presence: 60/100', $prompt);

        // BB104: only present touchpoints surface in the prompt. The
        // legacy "website_url: (kosong)" / "whatsapp_business_active: false"
        // lines were removed because they triggered the LLM to
        // hallucinate absence commentary ("ketidakjelasan status
        // WhatsApp Business" etc). Recommendations like "Lengkapi
        // Website" are now derived from the pillar score gap (Digital
        // Presence == 60/100 says enough) rather than from a literal
        // "(kosong)" marker in the prompt body.
        $this->assertStringContainsString('Touchpoints AKTIF', $prompt);
        $this->assertStringContainsString('instagram_url: https://www.instagram.com/lessworry.id/', $prompt);
        $this->assertStringContainsString('gmaps_url: https://maps.app.goo.gl/x', $prompt);
        $this->assertStringNotContainsString('website_url',  $prompt);
        $this->assertStringNotContainsString('whatsapp_business_active', $prompt);
        $this->assertStringNotContainsString('(kosong)', $prompt);
    }

    #[Test]
    public function system_prompt_uses_indonesian_register_and_locks_schema(): void
    {
        $sp = $this->systemPrompt();

        // Indonesian saya/kita register — register check is the BB34
        // contract's "Bahasa Indonesia, register saya/kita" line.
        $this->assertStringContainsString('Bahasa Indonesia', $sp);
        $this->assertStringContainsString('register saya/kita', $sp);

        // Schema enforcement keywords the LLM must follow.
        $this->assertStringContainsString('TEPAT 5 rekomendasi', $sp);
        $this->assertStringContainsString('"priority"', $sp);
        $this->assertStringContainsString('"effort"', $sp);
        $this->assertStringContainsString('"impact"', $sp);

        // Anti-generic guard.
        $this->assertStringContainsString('JANGAN generic', $sp);
    }
}
