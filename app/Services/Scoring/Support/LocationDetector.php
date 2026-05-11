<?php

declare(strict_types=1);

namespace App\Services\Scoring\Support;

final class LocationDetector
{
    /**
     * Whether a single token (one word) is in the singles seed list.
     */
    public function isLocationToken(string $word): bool
    {
        $word = mb_strtolower(trim($word));

        return $word !== '' && in_array($word, $this->singles(), true);
    }

    /**
     * Whether $text contains ANY seeded location (compound or single).
     * Compounds matched as substrings (longest-first). Singles matched with
     * word boundaries so "bali" doesn't fire inside "balikpapan".
     */
    public function containsLocation(string $text): bool
    {
        $text = mb_strtolower($text);

        foreach ($this->compoundsLongestFirst() as $loc) {
            if ($loc !== '' && str_contains($text, $loc)) {
                return true;
            }
        }

        foreach ($this->singles() as $loc) {
            if ($loc === '') {
                continue;
            }
            if (preg_match('/\b' . preg_quote($loc, '/') . '\b/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip every known location seed from $text. Compounds stripped before
     * unigrams so "park serpong" disappears as a unit rather than leaving
     * "park" behind to be mis-flagged as a variant word.
     */
    public function stripLocations(string $text): string
    {
        $out = mb_strtolower($text);

        foreach ($this->compoundsLongestFirst() as $loc) {
            if ($loc === '') {
                continue;
            }
            $out = str_replace($loc, ' ', $out);
        }

        foreach ($this->singles() as $loc) {
            if ($loc === '') {
                continue;
            }
            $out = (string) preg_replace(
                '/\b' . preg_quote($loc, '/') . '\b/u',
                ' ',
                $out,
            );
        }

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    /**
     * @return list<string>
     */
    public function compounds(): array
    {
        return $this->loadConfig('branding.location_tokens.compounds');
    }

    /**
     * @return list<string>
     */
    public function singles(): array
    {
        return $this->loadConfig('branding.location_tokens.singles');
    }

    /**
     * @return list<string>
     */
    private function compoundsLongestFirst(): array
    {
        $list = $this->compounds();
        usort($list, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $list;
    }

    /**
     * @return list<string>
     */
    private function loadConfig(string $key): array
    {
        /** @var array<int,mixed> $list */
        $list = (array) config($key, []);

        return array_values(array_map(
            static fn ($v): string => mb_strtolower(trim((string) $v)),
            $list,
        ));
    }
}
