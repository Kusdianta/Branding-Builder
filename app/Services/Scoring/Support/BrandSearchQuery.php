<?php

declare(strict_types=1);

namespace App\Services\Scoring\Support;

final class BrandSearchQuery
{
    /**
     * Strip generic suffixes from a brand name to produce a search-friendly stem.
     * Used by SearchRecallScorer as the query sent to Google Autocomplete.
     */
    public function normalizeBrandStem(string $brandName): string
    {
        $stem = mb_strtolower(trim($brandName));
        if ($stem === '') {
            return '';
        }

        foreach ($this->suffixesLongestFirst() as $sfx) {
            if ($sfx === '') {
                continue;
            }
            $stem = (string) preg_replace(
                '/\b' . preg_quote($sfx, '/') . '\b/u',
                ' ',
                $stem,
            );
        }

        return trim((string) preg_replace('/\s+/', ' ', $stem));
    }

    /**
     * Whether a single word is in the generic-suffix stop list.
     */
    public function isGenericSuffix(string $word): bool
    {
        $word = mb_strtolower(trim($word));

        return $word !== '' && in_array($word, $this->genericSuffixes(), true);
    }

    /**
     * @return list<string>
     */
    public function genericSuffixes(): array
    {
        /** @var array<int,mixed> $list */
        $list = (array) config('branding.brand_stems.generic_suffixes', []);

        return array_values(array_map(
            static fn ($v): string => mb_strtolower(trim((string) $v)),
            $list,
        ));
    }

    /**
     * @return list<string>
     */
    private function suffixesLongestFirst(): array
    {
        $list = $this->genericSuffixes();
        usort($list, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $list;
    }
}
