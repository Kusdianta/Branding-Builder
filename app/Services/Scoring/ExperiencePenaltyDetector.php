<?php

declare(strict_types=1);

namespace App\Services\Scoring;

/**
 * Phase 8 BB26: deterministic penalty detector for the Brand Experience
 * pillar. Hybrid alongside the existing ClaudeService LLM scorer — does
 * NOT replace it.
 *
 * Architecture:
 *
 *   LLM ExperienceScorer (existing)         → base scores per sub-bucket
 *   ExperiencePenaltyDetector (new)         → keyword-based penalty deltas
 *   Combined final score = LLM_base - sum(deterministic_penalties)
 *
 * Reasoning: the LLM does qualitative reasoning across the brand context
 * (service breadth, SOP signals, price transparency) that regex rules
 * cannot replicate. But specific complaint patterns — keterlambatan,
 * pakaian_hilang, no_response_wa — ARE deterministically detectable
 * once you have a wider review corpus than Places API's 5-cap. The LLM
 * is not bad at these; it is just blind to them when only 5 sample
 * reviews are available.
 *
 * Penalty caps (per BB26 spec):
 *
 *   penalty_keterlambatan   -8   keluhan tepat-waktu / SLA
 *   penalty_pakaian_hilang  -10  highest-impact: actual loss
 *   penalty_no_response_wa  -8   keluhan responsivitas WA
 *
 * Per-match deltas are -2 / -3 / -2 respectively, so 4+ matches reach
 * the cap. Reviews shorter than ``MIN_REVIEW_LENGTH`` chars are skipped
 * to avoid false positives from one-word reactions ("lama", "hilang"
 * in isolation often refer to something benign).
 *
 * Returns a structured detection payload that ScorePillarsJob (BB27)
 * applies to the LLM-produced PillarScore.
 */
final class ExperiencePenaltyDetector
{
    /** Minimum review text length for a match to count. */
    private const MIN_REVIEW_LENGTH = 25;

    /** Per-match delta + cap per penalty type. */
    private const PENALTY_CONFIG = [
        'penalty_keterlambatan' => [
            'per_match' => -2,
            'cap'       => -8,
            'patterns'  => [
                // Indonesian SLA / lateness vocabulary
                'telat', 'terlambat', 'molor', 'lewat batas', 'lewat janji',
                'lama banget', 'lama sekali', 'kelamaan', 'lambat banget',
                'jam belum', 'jam belom', 'hari belum', 'hari belom',
                'sudah seminggu', 'udah seminggu', 'sampai sekarang belum',
                'tidak tepat waktu', 'gak tepat waktu', 'ga tepat waktu',
                // BB50: English additions for English-language reviews
                // (Bandung-area expat-targeted brands, tourist hotspots).
                'overdue', 'late', 'delayed', 'past deadline',
                'still not done', 'didn\'t deliver on time',
                'missed the deadline', 'took forever', 'way too slow',
            ],
        ],
        'penalty_pakaian_hilang' => [
            'per_match' => -3,
            'cap'       => -10,
            'patterns'  => [
                // Loss / damage / quantity-mismatch / mix-up vocabulary
                // BB139 — added 'tertukar' + 'kehilangan' per PPT spec.
                // 'tertukar' is the canonical Indonesian for clothes
                // accidentally swapped between customers (a recurring
                // laundry complaint pattern). 'kehilangan' catches the
                // noun form when reviewers describe their own loss.
                'hilang', 'kehilangan', 'tertukar',
                'kurang satu', 'kurang dua', 'kurang baju',
                'pakaian hilang', 'baju hilang', 'celana hilang',
                'tidak kembali', 'ga kembali', 'gak balik',
                'rusak',
                // BB50: English additions. "no compensation" + "won't replace"
                // were specifically observed in the Less Worry GMaps corpus
                // ("My white shoes that i turned in for cleaning turned grey,
                // ... No compensation at all.") that previously scored zero
                // because the Indonesian-only corpus didn't catch English
                // damage/loss complaints.
                'missing item', 'lost', 'missing', 'damaged', 'ruined',
                'destroyed', 'torn', 'shrunk', 'discolored', 'stained',
                'no compensation', 'won\'t replace', 'didn\'t replace',
                'mixed up', 'swapped', 'wrong order',
            ],
        ],
        'penalty_no_response_wa' => [
            'per_match' => -2,
            'cap'       => -8,
            'patterns'  => [
                // Communication / WA responsiveness vocabulary
                // BB139 — broadened beyond explicit "wa ..." prefix per
                // PPT spec. Plain "tidak dibalas" / "ga direspon" /
                // "tidak respons" cover reviews where the medium is
                // implied (most laundry brands route complaints
                // through WA but reviewers don't always name it).
                'tidak dibalas', 'ga dibalas', 'gak dibalas',
                'tidak dijawab', 'ga dijawab', 'gak dijawab',
                'tidak respons', 'tidak respond',
                'ga direspon', 'gak direspon', 'tidak direspon',
                'wa tidak dibalas', 'wa ga dibalas', 'wa gak dibalas',
                'wa tidak dijawab', 'wa ga dijawab', 'wa gak dijawab',
                'tidak ada respon', 'tidak ada respond', 'gak ada respon',
                'tidak responsif', 'gak responsif',
                'wa tidak direspon', 'chat tidak dibalas',
                // BB50: English additions for English-language complaints
                // about responsiveness on WhatsApp / DM channels.
                'no reply', 'no response', 'didn\'t reply', 'didn\'t answer',
                'ignored my message', 'never got back to me',
                'no follow-up', 'unresponsive', 'ghosted',
            ],
        ],
    ];

    /**
     * Scan ``$reviews`` for the three penalty patterns. Returns a
     * structured payload:
     *
     *   [
     *     'penalties' => [
     *       'penalty_keterlambatan'  => -4,
     *       'penalty_pakaian_hilang' => 0,
     *       'penalty_no_response_wa' => -2,
     *     ],
     *     'evidence' => [
     *       'penalty_keterlambatan' => [
     *         ['author' => '...', 'rating_value' => 1, 'matched_phrase' => 'telat',
     *          'text_snippet' => '...'],
     *       ],
     *       ...
     *     ],
     *     'total_penalty' => -6,
     *     'reviews_scanned' => 18,
     *     'reviews_skipped_short' => 12,
     *   ]
     *
     * @param  list<array{author?: string, rating_value?: int, text?: string}> $reviews
     * @return array<string, mixed>
     */
    public function detect(array $reviews): array
    {
        $penalties = [
            'penalty_keterlambatan'  => 0,
            'penalty_pakaian_hilang' => 0,
            'penalty_no_response_wa' => 0,
        ];
        $evidence = [
            'penalty_keterlambatan'  => [],
            'penalty_pakaian_hilang' => [],
            'penalty_no_response_wa' => [],
        ];

        $scanned = 0;
        $skipped = 0;

        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
            $text = trim((string) ($review['text'] ?? ''));
            if (mb_strlen($text) < self::MIN_REVIEW_LENGTH) {
                $skipped++;
                continue;
            }
            $scanned++;
            $textLower = mb_strtolower($text);

            foreach (self::PENALTY_CONFIG as $key => $config) {
                if ($penalties[$key] <= $config['cap']) {
                    // Already at cap — skip further matching for this key.
                    continue;
                }
                $matchedPhrase = $this->firstMatch($textLower, $config['patterns']);
                if ($matchedPhrase === null) {
                    continue;
                }
                $penalties[$key] = max(
                    $config['cap'],
                    $penalties[$key] + $config['per_match'],
                );
                $evidence[$key][] = [
                    'author'        => (string) ($review['author'] ?? ''),
                    'rating_value'  => (int) ($review['rating_value'] ?? 0),
                    'matched_phrase' => $matchedPhrase,
                    'text_snippet'  => mb_substr($text, 0, 200),
                ];
            }
        }

        return [
            'penalties'             => $penalties,
            'evidence'              => $evidence,
            'total_penalty'         => array_sum($penalties),
            'reviews_scanned'       => $scanned,
            'reviews_skipped_short' => $skipped,
        ];
    }

    /**
     * Return the first phrase in ``$patterns`` that appears as a
     * substring of ``$textLower`` (case-insensitive — caller already
     * lowercased), or null if none matched.
     *
     * @param list<string> $patterns
     */
    private function firstMatch(string $textLower, array $patterns): ?string
    {
        foreach ($patterns as $phrase) {
            if (str_contains($textLower, mb_strtolower($phrase))) {
                return $phrase;
            }
        }
        return null;
    }
}
