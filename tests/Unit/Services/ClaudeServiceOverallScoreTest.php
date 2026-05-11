<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ClaudeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Targeted tests for the post-analysis server-side overall-score recompute.
 *
 * Construction-bypass via reflection so we don't need a live Anthropic key
 * just to exercise pure helpers.
 */
class ClaudeServiceOverallScoreTest extends TestCase
{
    private function makeServiceWithoutConstructor(): ClaudeService
    {
        return (new ReflectionClass(ClaudeService::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param  array<string,mixed>  $scorecard
     * @return array<string,mixed>
     */
    private function compute(array $scorecard): array
    {
        $svc = $this->makeServiceWithoutConstructor();
        $method = (new ReflectionClass($svc))->getMethod('computeOverallScore');
        $method->setAccessible(true);

        /** @var array<string,mixed> $out */
        $out = $method->invoke($svc, ['scorecard' => $scorecard]);
        return $out['scorecard'];
    }

    #[Test]
    #[DataProvider('overallCases')]
    public function it_computes_overall_as_simple_average_with_grade_lookup(
        array $sevenScores,
        float $expectedScore,
        string $expectedGrade,
    ): void {
        $keys = [
            'profile_bio_optimization',
            'content_quality_variety',
            'visual_consistency_aesthetics',
            'niche_clarity_positioning',
            'engagement_strategy',
            'personal_brand_storytelling',
            'growth_potential',
        ];
        $scorecard = [];
        foreach ($keys as $i => $key) {
            $scorecard[$key] = ['score' => $sevenScores[$i], 'grade' => 'X'];
        }
        $scorecard['overall'] = ['score' => 0, 'grade' => 'F'];

        $out = $this->compute($scorecard);

        $this->assertSame($expectedScore, $out['overall']['score']);
        $this->assertSame($expectedGrade, $out['overall']['grade']);
    }

    /** @return array<string,array{0:list<int>,1:float,2:string}> */
    public static function overallCases(): array
    {
        return [
            'apikprimadya example' => [
                // (5.5+6.5+5.0+8.0+5.5+5.0+9.0) actually want integers; use closest pattern
                // average should land in C/B band; pick whole numbers
                [6, 7, 5, 8, 6, 5, 9],     // sum 46 / 7 = 6.571...
                6.6,
                'C',
            ],
            'all tens'  => [[10,10,10,10,10,10,10], 10.0, 'A'],
            'all zeros' => [[0,0,0,0,0,0,0],         0.0, 'F'],
            'border A/B' => [[9,9,9,9,9,9,9],         9.0, 'A'],
            'just below A' => [[8,9,9,9,9,9,8],       8.7, 'B'],
            'border B/C' => [[7,7,7,7,7,7,7],         7.0, 'B'],
            'border C/D' => [[5,5,5,5,5,5,5],         5.0, 'C'],
            'border D/F' => [[3,3,3,3,3,3,3],         3.0, 'D'],
        ];
    }

    #[Test]
    public function it_falls_back_to_zero_F_when_no_sub_scores_present(): void
    {
        $out = $this->compute([
            // overall present, but no sub-keys
            'overall' => ['score' => 0, 'grade' => 'F'],
        ]);

        $this->assertSame(0, $out['overall']['score']);
        $this->assertSame('F', $out['overall']['grade']);
    }

    #[Test]
    public function it_ignores_non_numeric_sub_scores(): void
    {
        $out = $this->compute([
            'profile_bio_optimization'      => ['score' => 'bad',   'grade' => 'C'],
            'content_quality_variety'       => ['score' => 8,        'grade' => 'B'],
            'visual_consistency_aesthetics' => ['score' => 6,        'grade' => 'C'],
            'niche_clarity_positioning'     => ['score' => null,     'grade' => 'D'],
            'engagement_strategy'           => ['score' => 7,        'grade' => 'B'],
            'personal_brand_storytelling'   => ['score' => 5,        'grade' => 'C'],
            'growth_potential'              => ['score' => 4,        'grade' => 'D'],
            'overall'                       => ['score' => 99, 'grade' => 'A'], // ignored / overwritten
        ]);

        // average of 8+6+7+5+4 = 30/5 = 6.0
        $this->assertSame(6.0, $out['overall']['score']);
        $this->assertSame('C', $out['overall']['grade']);
    }
}
