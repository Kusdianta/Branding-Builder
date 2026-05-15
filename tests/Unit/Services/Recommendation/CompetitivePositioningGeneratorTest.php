<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Recommendation;

use App\Models\BrandAudit;
use App\Services\Recommendation\CompetitivePositioningGenerator;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 9.1 BB47: parser + fallback coverage for the
 * CompetitivePositioningGenerator. Same reflection-bypass pattern
 * as BB45/BB46.
 *
 * Note on the original spec's
 * "test_fallback_to_deterministic_strongest_weakest_pillar_stitch"
 * test case: the deterministic stitch lives in the
 * pdf/sections/executive-summary.blade.php partial (it picks up
 * pillar_scores at render time when competitive_positioning.narrative
 * is empty), NOT in this generator. The generator's fallback returns
 * empty strings — the PDF layer is what decides what to show in their
 * place. We test the actual generator behaviour here and exercise the
 * PDF stitch separately in the GenerateInsightsJob integration test
 * (BB48) via the rendered template.
 */
class CompetitivePositioningGeneratorTest extends TestCase
{
    private function makeGenerator(): CompetitivePositioningGenerator
    {
        return (new ReflectionClass(CompetitivePositioningGenerator::class))
            ->newInstanceWithoutConstructor();
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

    private function userPrompt(BrandAudit $audit): string
    {
        $gen    = $this->makeGenerator();
        $method = (new ReflectionClass($gen))->getMethod('userPrompt');
        $method->setAccessible(true);
        return (string) $method->invoke($gen, $audit);
    }

    private function systemPrompt(): string
    {
        $gen    = $this->makeGenerator();
        $method = (new ReflectionClass($gen))->getMethod('systemPrompt');
        $method->setAccessible(true);
        return (string) $method->invoke($gen);
    }

    #[Test]
    public function it_parses_narrative_and_growth_opportunity(): void
    {
        $payload = [
            'narrative' => 'Less Worry Laundry menempati posisi yang menarik di pasar laundry kiloan Bandung. Brand recall-nya tergolong moderat, dan kekuatan utama berasal dari kehadiran konsisten di Google Maps + Instagram.',
            'growth_opportunity' => 'Dengan menstandarisasi SOP layanan dan komunikasi visual di semua touchpoint, Less Worry Laundry berpotensi naik ke tier "Brand Kuat" dalam 6-9 bulan.',
        ];

        $out = $this->parse($payload);

        $this->assertSame($payload['narrative'], $out['narrative']);
        $this->assertSame($payload['growth_opportunity'], $out['growth_opportunity']);
    }

    #[Test]
    public function it_trims_whitespace_around_narrative_and_growth(): void
    {
        $payload = [
            'narrative' => "   Narrative with leading and trailing whitespace.\n  ",
            'growth_opportunity' => "  Growth opportunity sentence.   ",
        ];

        $out = $this->parse($payload);

        $this->assertSame('Narrative with leading and trailing whitespace.', $out['narrative']);
        $this->assertSame('Growth opportunity sentence.', $out['growth_opportunity']);
    }

    #[Test]
    public function it_returns_fallback_when_narrative_is_empty(): void
    {
        $payload = [
            'narrative' => '',
            'growth_opportunity' => 'A growth opportunity that cannot be persisted alone.',
        ];

        // Both must be present — partial output is treated as failure
        // because the PDF callout box would render with no body.
        $this->assertSame(['narrative' => '', 'growth_opportunity' => ''], $this->parse($payload));
    }

    #[Test]
    public function it_returns_fallback_when_growth_is_empty(): void
    {
        $payload = [
            'narrative' => 'A narrative that cannot stand alone.',
            'growth_opportunity' => '',
        ];

        $this->assertSame(['narrative' => '', 'growth_opportunity' => ''], $this->parse($payload));
    }

    #[Test]
    public function it_handles_decoded_with_missing_keys(): void
    {
        $this->assertSame(['narrative' => '', 'growth_opportunity' => ''], $this->parse([]));
        $this->assertSame(['narrative' => '', 'growth_opportunity' => ''], $this->parse(['something_else' => 'x']));
    }

    #[Test]
    public function it_fallback_payload_returns_empty_strings_for_both_keys(): void
    {
        $this->assertSame(
            ['narrative' => '', 'growth_opportunity' => ''],
            $this->fallback(),
        );
    }

    #[Test]
    public function user_prompt_includes_pillar_scores_and_review_signal(): void
    {
        $audit = new BrandAudit();
        $audit->brand_name    = 'Less Worry Laundry';
        $audit->city          = 'Bandung';
        $audit->service_type  = 'kiloan';
        $audit->overall_score = 53;
        $audit->pillar_scores = [
            'brand-konsistensi' => ['score' => 70],
            'brand-recall'      => ['score' => 35],
            'brand-experience'  => ['score' => 30],
            'digital-presence'  => ['score' => 60],
        ];
        $audit->gmaps_reviews = [
            'rating' => 4.7,
            'total_review_count' => 198,
            'business_name' => 'Less Worry Laundry — Bandung',
            'reviews' => [['text' => 'long enough text to pass the array filter']],
        ];

        $prompt = $this->userPrompt($audit);

        // All four pillar scores present so the LLM can compose a
        // narrative grounded in actual data.
        $this->assertStringContainsString('brand-konsistensi: 70/100', $prompt);
        $this->assertStringContainsString('brand-recall: 35/100', $prompt);
        $this->assertStringContainsString('brand-experience: 30/100', $prompt);
        $this->assertStringContainsString('digital-presence: 60/100', $prompt);

        // Review signal surfaces — competitive positioning leans on
        // social proof volume + business name verification.
        $this->assertStringContainsString('rating 4.7', $prompt);
        $this->assertStringContainsString('total_reviews 198', $prompt);
        $this->assertStringContainsString('Less Worry Laundry — Bandung', $prompt);
    }

    #[Test]
    public function system_prompt_locks_apikprimadya_format_and_word_counts(): void
    {
        $sp = $this->systemPrompt();

        // Word counts and section structure that the apikprimadya PDF
        // reference uses — drift from these would break the PDF layout.
        $this->assertStringContainsString('120-180 kata', $sp);   // narrative target length
        $this->assertStringContainsString('15-30 kata', $sp);     // growth-opportunity sentence length
        $this->assertStringContainsString('Bahasa Indonesia', $sp);
        $this->assertStringContainsString('"narrative"', $sp);
        $this->assertStringContainsString('"growth_opportunity"', $sp);
        $this->assertStringContainsString('JANGAN generic', $sp);
    }
}
