<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Services\Scoring\Support\BrandSearchQuery;
use App\Services\Scoring\Support\LocationDetector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nema\WorkerClient\DTO\AutocompleteSuggestion;
use Nema\WorkerClient\NemaWorkerClient;

/**
 * Deterministic scorer for the Brand Recall sub-bucket "search_recall" (cap 35).
 *
 * Three independent signals against Google Autocomplete suggestions for the
 * brand's name stem (hl=id, gl=ID):
 *
 *   brand_recognition  cap 15  — earliest position the stem appears in the top-10
 *   geographic_spread  cap 15  — count of suggestions pairing stem with a location
 *   variant_coverage   cap  5  — any suggestion with stem + a non-stem/non-location word
 */
final class SearchRecallScorer
{
    public function __construct(
        private readonly NemaWorkerClient $worker,
        private readonly BrandSearchQuery $brandSearchQuery,
        private readonly LocationDetector $locationDetector,
    ) {}

    /**
     * Fetch suggestions from the worker and compute the 35-pt sub-bucket.
     *
     * @return array{score:int, breakdown:array<string,mixed>}
     */
    public function score(string $brandName): array
    {
        $stem = $this->brandSearchQuery->normalizeBrandStem($brandName);
        $now  = Carbon::now()->toIso8601String();

        if ($stem === '') {
            return $this->emptyResult($stem, [], $now, 'Brand name kosong setelah normalisasi.');
        }

        try {
            $result = $this->worker->autocomplete($stem, 'id', 'ID');
        } catch (\Throwable $e) {
            Log::warning('SearchRecallScorer: autocomplete fetch failed', [
                'brand_name' => $brandName,
                'stem'       => $stem,
                'error'      => $e->getMessage(),
            ]);

            return $this->emptyResult($stem, [], $now, 'Worker autocomplete tidak tersedia: ' . $e->getMessage());
        }

        $suggestions = array_map(
            static fn (AutocompleteSuggestion $s): string => $s->text,
            $result->suggestions,
        );
        $fetchedAt = $result->fetchedAt !== '' ? $result->fetchedAt : $now;

        return $this->scoreFromSuggestions($brandName, $stem, $suggestions, $fetchedAt);
    }

    /**
     * Pure scoring entry — bypasses the worker for tests and offline re-scoring.
     *
     * @param  list<string>  $suggestions  raw autocomplete suggestion text, in rank order
     * @return array{score:int, breakdown:array<string,mixed>}
     */
    public function scoreFromSuggestions(
        string $brandName,
        string $stem,
        array $suggestions,
        string $fetchedAt,
    ): array {
        $normalized = array_values(array_map(
            static fn ($s): string => mb_strtolower(trim((string) $s)),
            $suggestions,
        ));

        $recognition = $this->scoreBrandRecognition($stem, $normalized);
        $geographic  = $this->scoreGeographicSpread($stem, $normalized);
        $variant     = $this->scoreVariantCoverage($stem, $normalized);

        $total = min(35, $recognition['score'] + $geographic['score'] + $variant['score']);

        return [
            'score'     => $total,
            'breakdown' => [
                'score'      => $total,
                'cap'        => 35,
                'raw_inputs' => [
                    'brand_name'       => $brandName,
                    'brand_stem'       => $stem,
                    'suggestions'      => $suggestions,
                    'suggestion_count' => count($suggestions),
                    'source'           => 'Google Autocomplete (hl=id, gl=ID)',
                    'fetched_at'       => $fetchedAt,
                ],
                'formula'  => 'deterministic_signals',
                'signals'  => [
                    'brand_recognition' => $recognition,
                    'geographic_spread' => $geographic,
                    'variant_coverage'  => $variant,
                ],
                'limitations' => [
                    "Autocomplete hasil dapat berubah dari waktu ke waktu — skor mencerminkan snapshot saat audit dijalankan ({$fetchedAt}).",
                ],
                'explanation_id' => 'search_recall_v1',
            ],
        ];
    }

    /**
     * @param  list<string>  $normalized
     * @return array{score:int, cap:int, first_match_position:?int, detail:string}
     */
    private function scoreBrandRecognition(string $stem, array $normalized): array
    {
        $firstMatchAt = null;
        foreach ($normalized as $i => $suggestion) {
            if ($this->suggestionContainsStem($stem, $suggestion)) {
                $firstMatchAt = $i;
                break;
            }
        }

        if ($firstMatchAt === null) {
            return [
                'score'                => 0,
                'cap'                  => 15,
                'first_match_position' => null,
                'detail'               => 'Brand stem tidak ditemukan di top-10 hasil autocomplete.',
            ];
        }

        [$score, $tier] = match (true) {
            $firstMatchAt <= 2 => [15, 'top-3'],
            $firstMatchAt <= 4 => [10, 'top-5'],
            $firstMatchAt <= 9 => [5,  'top-10'],
            default            => [0,  'di luar top-10'],
        };

        return [
            'score'                => $score,
            'cap'                  => 15,
            'first_match_position' => $firstMatchAt,
            'detail'               => "Brand stem ditemukan di posisi {$firstMatchAt} ({$tier}).",
        ];
    }

    /**
     * Geographic spread measures BREADTH of locations the brand is associated
     * with — not query density. Two suggestions for the same neighborhood count
     * once. Score is keyed on the size of the unique location set across all
     * branded suggestions.
     *
     * @param  list<string>  $normalized
     * @return array{score:int, cap:int, unique_count:int, unique_locations:list<string>, suggestion_count:int, detail:string}
     */
    private function scoreGeographicSpread(string $stem, array $normalized): array
    {
        $uniqueLocations = [];
        $matchedSuggestions = 0;

        foreach ($normalized as $suggestion) {
            if (! $this->suggestionContainsStem($stem, $suggestion)) {
                continue;
            }

            $found = $this->locationDetector->matchedLocations($suggestion);
            if ($found === []) {
                continue;
            }

            $matchedSuggestions++;
            foreach ($found as $loc) {
                $uniqueLocations[$loc] = true;
            }
        }

        $uniqueLocations = array_keys($uniqueLocations);
        $uniqueCount     = count($uniqueLocations);

        $score = match (true) {
            $uniqueCount >= 5 => 15,
            $uniqueCount >= 3 => 10,
            $uniqueCount >= 1 => 5,
            default           => 0,
        };

        $detail = $uniqueCount === 0
            ? 'Tidak ada saran autocomplete yang menggabungkan brand stem dengan lokasi.'
            : sprintf(
                '%d lokasi unik di %d saran autocomplete: %s',
                $uniqueCount,
                $matchedSuggestions,
                implode(', ', $uniqueLocations),
            );

        return [
            'score'            => $score,
            'cap'              => 15,
            'unique_count'     => $uniqueCount,
            'unique_locations' => $uniqueLocations,
            'suggestion_count' => $matchedSuggestions,
            'detail'           => $detail,
        ];
    }

    /**
     * @param  list<string>  $normalized
     * @return array{score:int, cap:int, variants:list<string>, detail:string}
     */
    private function scoreVariantCoverage(string $stem, array $normalized): array
    {
        $variants = [];

        foreach ($normalized as $suggestion) {
            if (! $this->suggestionContainsStem($stem, $suggestion)) {
                continue;
            }

            // Strip stem, then strip every seeded location (compounds first so
            // "park serpong" disappears as a unit instead of leaving stray "park").
            $residual = str_replace($stem, ' ', $suggestion);
            $residual = $this->locationDetector->stripLocations($residual);
            $residual = trim((string) preg_replace('/\s+/', ' ', $residual));

            $tokens = preg_split('/\s+/', $residual, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                if ($this->brandSearchQuery->isGenericSuffix($token)) {
                    continue;
                }
                $variants[] = $token;
            }
        }

        $variants = array_values(array_unique($variants));
        $score    = $variants === [] ? 0 : 5;

        $detail = $variants === []
            ? 'Tidak ada variasi non-stem ditemukan.'
            : 'Variasi non-stem ditemukan: ' . implode(', ', $variants);

        return [
            'score'    => $score,
            'cap'      => 5,
            'variants' => $variants,
            'detail'   => $detail,
        ];
    }

    /**
     * Brand stem is "in" a suggestion when:
     *   - the lowercased stem appears as a word-bounded substring (handles the
     *     common case where Google Autocomplete prepends the typed query
     *     verbatim), OR
     *   - more than 70% of the stem's whitespace tokens appear individually
     *     with word boundaries (handles re-ordering / minor punctuation).
     *
     * Word boundaries protect against future short or generic brand stems
     * (e.g. "wash", "clean") from matching every laundry-adjacent suggestion.
     */
    private function suggestionContainsStem(string $stem, string $suggestion): bool
    {
        if ($stem === '' || $suggestion === '') {
            return false;
        }
        if (preg_match('/\b' . preg_quote($stem, '/') . '\b/u', $suggestion) === 1) {
            return true;
        }

        $stemTokens = preg_split('/\s+/', $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($stemTokens === []) {
            return false;
        }

        $hits = 0;
        foreach ($stemTokens as $tok) {
            if (preg_match('/\b' . preg_quote($tok, '/') . '\b/u', $suggestion) === 1) {
                $hits++;
            }
        }

        return ($hits / count($stemTokens)) > 0.7;
    }

    /**
     * @param  list<string>  $suggestions
     * @return array{score:int, breakdown:array<string,mixed>}
     */
    private function emptyResult(string $stem, array $suggestions, string $fetchedAt, string $reason): array
    {
        return [
            'score'     => 0,
            'breakdown' => [
                'score'      => 0,
                'cap'        => 35,
                'raw_inputs' => [
                    'brand_stem'       => $stem,
                    'suggestions'      => $suggestions,
                    'suggestion_count' => count($suggestions),
                    'source'           => 'Google Autocomplete (hl=id, gl=ID)',
                    'fetched_at'       => $fetchedAt,
                ],
                'formula' => 'deterministic_signals',
                'signals' => [
                    'brand_recognition' => ['score' => 0, 'cap' => 15, 'first_match_position' => null, 'detail' => $reason],
                    'geographic_spread' => ['score' => 0, 'cap' => 15, 'unique_count' => 0, 'unique_locations' => [], 'suggestion_count' => 0, 'detail' => $reason],
                    'variant_coverage'  => ['score' => 0, 'cap' => 5,  'variants' => [], 'detail' => $reason],
                ],
                'limitations' => [
                    "Autocomplete hasil dapat berubah dari waktu ke waktu — skor mencerminkan snapshot saat audit dijalankan ({$fetchedAt}).",
                ],
                'explanation_id' => 'search_recall_v1',
            ],
        ];
    }
}
