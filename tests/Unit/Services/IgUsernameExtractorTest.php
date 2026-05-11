<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\IgUsernameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IgUsernameExtractorTest extends TestCase
{
    private IgUsernameExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new IgUsernameExtractor();
    }

    #[Test]
    #[DataProvider('happyPathInputs')]
    public function it_extracts_canonical_username_from_valid_inputs(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->extractor->extract($input));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function happyPathInputs(): array
    {
        return [
            'https url with trailing slash' => ['https://instagram.com/lessworry.id/', 'lessworry.id'],
            'http url no trailing slash'    => ['http://instagram.com/lessworry.id', 'lessworry.id'],
            'www subdomain'                  => ['https://www.instagram.com/lessworry.id/', 'lessworry.id'],
            'leading @'                      => ['@lessworry.id', 'lessworry.id'],
            'bare username'                  => ['lessworry.id', 'lessworry.id'],
            'with query string'              => ['https://instagram.com/lessworry.id?utm_source=share', 'lessworry.id'],
            'with fragment'                  => ['https://instagram.com/lessworry.id#bio', 'lessworry.id'],
            'with whitespace'                => ['  @lessworry.id  ', 'lessworry.id'],
            'underscore username'            => ['@cocacola_id', 'cocacola_id'],
            'numeric segments'               => ['user.123', 'user.123'],
            'mixed case preserved'           => ['NaufalK', 'NaufalK'],
            'with extra path segments'       => ['https://instagram.com/lessworry.id/p/abc123/', 'lessworry.id'],
            'instagram host bare'            => ['instagram.com/lessworry.id', 'lessworry.id'],
            'bare username with trailing path' => ['user/name', 'user'],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_returns_null_for_unparseable_inputs(string $input): void
    {
        $this->assertNull($this->extractor->extract($input));
    }

    /** @return array<string,array{0:string}> */
    public static function invalidInputs(): array
    {
        return [
            'empty string'         => [''],
            'whitespace only'      => ['   '],
            'illegal hyphen'       => ['less-worry'],
            'illegal space inside' => ['less worry'],
            'illegal unicode'      => ['naïve.user'],
            'too long'             => [str_repeat('a', 31)],
            'just @'               => ['@'],
            'just slash'           => ['/'],
            'just url no handle'   => ['https://instagram.com/'],
        ];
    }

    #[Test]
    public function it_accepts_maximum_length_username(): void
    {
        $thirty = str_repeat('a', 30);
        $this->assertSame($thirty, $this->extractor->extract($thirty));
    }
}
