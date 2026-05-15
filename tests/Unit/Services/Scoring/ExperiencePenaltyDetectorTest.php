<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Scoring;

use App\Services\Scoring\ExperiencePenaltyDetector;
use PHPUnit\Framework\TestCase;

/**
 * Phase 8 BB26: tests for the deterministic experience penalty detector.
 *
 * Coverage:
 *   - Empty corpus -> all zero penalties.
 *   - Short reviews (<25 chars) -> skipped, no penalty.
 *   - Single match per penalty type -> -2 / -3 / -2.
 *   - Multiple matches -> accumulates up to cap (-8 / -10 / -8).
 *   - Mixed-keyword review fires multiple penalties.
 *   - Evidence captures author + rating + matched_phrase + snippet.
 *   - Reviews-scanned vs reviews-skipped accounting.
 */
final class ExperiencePenaltyDetectorTest extends TestCase
{
    private ExperiencePenaltyDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ExperiencePenaltyDetector();
    }

    public function test_empty_reviews_returns_all_zero(): void
    {
        $result = $this->detector->detect([]);

        $this->assertSame([
            'penalty_keterlambatan'  => 0,
            'penalty_pakaian_hilang' => 0,
            'penalty_no_response_wa' => 0,
        ], $result['penalties']);
        $this->assertSame(0, $result['total_penalty']);
        $this->assertSame(0, $result['reviews_scanned']);
        $this->assertSame(0, $result['reviews_skipped_short']);
    }

    public function test_short_reviews_are_skipped(): void
    {
        $reviews = [
            ['text' => 'telat'],            // 5 chars — skipped
            ['text' => 'lama'],             // 4 chars — skipped
            ['text' => 'pesanan saya hilang nih'], // 23 chars — skipped (under 25)
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(0, $result['penalties']['penalty_keterlambatan']);
        $this->assertSame(0, $result['penalties']['penalty_pakaian_hilang']);
        $this->assertSame(3, $result['reviews_skipped_short']);
        $this->assertSame(0, $result['reviews_scanned']);
    }

    public function test_single_keterlambatan_match_fires_minus_2(): void
    {
        $reviews = [
            ['author' => 'Andi', 'rating_value' => 2, 'text' => 'Pesen laundry 24 jam, tapi 32 jam belum kelar. Kecewa.'],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-2, $result['penalties']['penalty_keterlambatan']);
        $this->assertSame(0, $result['penalties']['penalty_pakaian_hilang']);
        $this->assertSame(-2, $result['total_penalty']);
        $this->assertCount(1, $result['evidence']['penalty_keterlambatan']);
    }

    public function test_keterlambatan_caps_at_minus_8(): void
    {
        // 6 matching reviews, but the cap is -8 (i.e. 4 matches at -2 each).
        $reviews = array_fill(
            0,
            6,
            ['text' => 'Sudah seminggu pakaian belum selesai, ini kelamaan banget service-nya'],
        );

        $result = $this->detector->detect($reviews);

        $this->assertSame(-8, $result['penalties']['penalty_keterlambatan']);
        $this->assertCount(4, $result['evidence']['penalty_keterlambatan']);
    }

    public function test_pakaian_hilang_per_match_is_minus_3_capped_at_minus_10(): void
    {
        $reviews = [
            ['text' => 'Baju saya hilang setelah dikembalikan, kurang satu kemeja'],
            ['text' => 'Pesen 5 stel tapi kurang satu pas dikembalikan'],
            ['text' => 'Sepatu rusak parah ketika kembali, cat lecet semua'],
            ['text' => 'Celana hilang dan tidak ada penggantian sampai sekarang'],
            ['text' => 'Lain kali pakaian hilang lagi, payah pelayanannya'],
        ];

        $result = $this->detector->detect($reviews);

        // 5 matches × -3 = -15, capped at -10
        $this->assertSame(-10, $result['penalties']['penalty_pakaian_hilang']);
    }

    public function test_no_response_wa_per_match_is_minus_2(): void
    {
        $reviews = [
            ['text' => 'Sudah dua hari WA tidak dibalas, padahal urgent banget cucian saya'],
            ['text' => 'Chat tidak dibalas berkali-kali, akhirnya datang sendiri ke outlet'],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-4, $result['penalties']['penalty_no_response_wa']);
    }

    public function test_one_review_can_fire_multiple_penalties(): void
    {
        $reviews = [
            [
                'author' => 'Citra',
                'rating_value' => 1,
                'text' => 'Pakaian hilang satu, sudah seminggu belum ada respon, WA tidak dibalas juga sangat mengecewakan',
            ],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-2, $result['penalties']['penalty_keterlambatan']);
        $this->assertSame(-3, $result['penalties']['penalty_pakaian_hilang']);
        $this->assertSame(-2, $result['penalties']['penalty_no_response_wa']);
        $this->assertSame(-7, $result['total_penalty']);
    }

    public function test_evidence_captures_author_rating_phrase_and_snippet(): void
    {
        $reviews = [
            [
                'author' => 'Milenius',
                'rating_value' => 1,
                'text' => 'Pesen laundry 24 jam, tapi 32 jam belum kelar — sangat tidak rekomen.',
            ],
        ];

        $result = $this->detector->detect($reviews);

        $evidence = $result['evidence']['penalty_keterlambatan'][0];
        $this->assertSame('Milenius', $evidence['author']);
        $this->assertSame(1, $evidence['rating_value']);
        $this->assertSame('jam belum', $evidence['matched_phrase']);
        $this->assertStringContainsString('32 jam belum kelar', $evidence['text_snippet']);
    }

    public function test_positive_reviews_do_not_fire_penalties(): void
    {
        $reviews = [
            ['text' => 'Cepat bersih harum dan ramah pelayanannya, recommended sekali'],
            ['text' => 'Hasil cucian wangi dan rapi, harga juga terjangkau cocok untuk keluarga'],
            ['text' => 'Pelayanan profesional dan tepat waktu, akan kembali lagi'],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(0, $result['total_penalty']);
        $this->assertSame(3, $result['reviews_scanned']);
    }

    public function test_reviews_scanned_vs_skipped_accounting(): void
    {
        $reviews = [
            ['text' => 'short'],                                        // skipped
            ['text' => 'Pelayanan ramah, hasil cucian bersih harum'],   // scanned, no penalty
            ['text' => 'Sudah seminggu belum selesai juga, kecewa'],   // scanned, penalty
            ['text' => 'ok'],                                            // skipped
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(2, $result['reviews_scanned']);
        $this->assertSame(2, $result['reviews_skipped_short']);
        $this->assertSame(-2, $result['penalties']['penalty_keterlambatan']);
    }

    // -----------------------------------------------------------------
    // BB50: English-language penalty triggers. Bandung-area expat-targeted
    // brands receive English reviews that the pre-BB50 Indonesian-only
    // corpus silently dropped — the canonical example is the Less Worry
    // GMaps review:
    //   "My white shoes that i turned in for cleaning turned grey,
    //    waited another week and it got worse. No compensation at all."
    // which previously fired zero penalties despite being a clear
    // damage + unresponsive complaint at 1 star.
    // -----------------------------------------------------------------

    public function test_english_damage_complaint_fires_pakaian_hilang_penalty(): void
    {
        // The Less Worry "white shoes" review verbatim. Two BB50
        // patterns hit: 'no compensation' (damage cluster) AND the
        // generic damage signal. Single review = single per-match
        // delta of -3 against penalty_pakaian_hilang.
        $reviews = [
            [
                'author' => 'Radia',
                'rating_value' => 1,
                'text' => 'My white shoes that i turned in for cleaning turned grey, '
                    . 'waited another week and it got worse. No compensation at all.',
            ],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-3, $result['penalties']['penalty_pakaian_hilang']);
        $this->assertCount(1, $result['evidence']['penalty_pakaian_hilang']);
        $this->assertSame('Radia', $result['evidence']['penalty_pakaian_hilang'][0]['author']);
        $this->assertSame(1, $result['evidence']['penalty_pakaian_hilang'][0]['rating_value']);
        $this->assertSame('no compensation', $result['evidence']['penalty_pakaian_hilang'][0]['matched_phrase']);
    }

    public function test_english_late_complaint_fires_keterlambatan_penalty(): void
    {
        $reviews = [
            ['text' => 'Picked up my laundry late again, took forever to finish a small order.'],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-2, $result['penalties']['penalty_keterlambatan']);
        $this->assertSame('late', $result['evidence']['penalty_keterlambatan'][0]['matched_phrase']);
    }

    public function test_english_unresponsive_complaint_fires_no_response_wa_penalty(): void
    {
        $reviews = [
            ['text' => 'Sent multiple WhatsApp messages and got ignored my message every single time.'],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-2, $result['penalties']['penalty_no_response_wa']);
        $this->assertSame('ignored my message', $result['evidence']['penalty_no_response_wa'][0]['matched_phrase']);
    }

    public function test_mixed_english_review_fires_multiple_penalties(): void
    {
        // A single review that hits all three penalty types in English
        // — exercises the cross-penalty matching loop with the BB50
        // English additions.
        $reviews = [
            [
                'text' => 'They lost my shirt, were unresponsive on WA, and the order was overdue by three days.',
            ],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-2, $result['penalties']['penalty_keterlambatan'],   'overdue should fire keterlambatan');
        $this->assertSame(-3, $result['penalties']['penalty_pakaian_hilang'],  'lost should fire pakaian_hilang');
        $this->assertSame(-2, $result['penalties']['penalty_no_response_wa'],  'unresponsive should fire no_response_wa');
        $this->assertSame(-7, $result['total_penalty']);
    }

    public function test_indonesian_keywords_still_fire_after_bb50_extension(): void
    {
        // Regression guard: BB50 only ADDED English to the corpus. The
        // Indonesian keywords from the pre-BB50 set must still match.
        $reviews = [
            ['text' => 'Pakaian saya hilang dan WA tidak dibalas selama 3 hari, sudah seminggu juga belum diselesaikan.'],
        ];

        $result = $this->detector->detect($reviews);

        $this->assertSame(-2, $result['penalties']['penalty_keterlambatan']);
        $this->assertSame(-3, $result['penalties']['penalty_pakaian_hilang']);
        $this->assertSame(-2, $result['penalties']['penalty_no_response_wa']);
    }
}
