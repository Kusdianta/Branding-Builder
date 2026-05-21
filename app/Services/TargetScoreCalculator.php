<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Phase 12c.4 FIX E — single source of truth for the "Target Skor
 * Berikutnya" computation.
 *
 * Used by both the dashboard view (Volt component) AND
 * AggregateAuditJob (to pass into the LLM reasoning generator), so
 * the rendered actions and the LLM-explained actions can never
 * diverge.
 *
 * Output shape:
 *   [
 *     'current' => int,
 *     'target'  => int,
 *     'delta'   => int,
 *     'actions' => list<array{
 *         text: string,
 *         gain: int,
 *         pillar: string,
 *         bucket: string,
 *         pillar_slug: string,
 *     }>,
 *   ]
 */
final class TargetScoreCalculator
{
    /** @var array<string,float> */
    private const PILLAR_WEIGHTS = [
        'brand-konsistensi' => 0.35,
        'brand-recall'      => 0.35,
        'brand-experience'  => 0.20,
        'digital-presence'  => 0.10,
    ];

    /** @var array<string,string> */
    private const PILLAR_LABELS = [
        'brand-konsistensi' => 'Brand Konsistensi',
        'brand-recall'      => 'Brand Recall',
        'brand-experience'  => 'Brand Experience',
        'digital-presence'  => 'Digital Presence',
    ];

    /** @var array<string,string> */
    private const ACTION_TEMPLATES = [
        'has_instagram'           => 'Update Instagram secara reguler (≥ 2 post/minggu)',
        'has_wa'                  => 'Aktifkan WhatsApp Business dan pasang link wa.me di IG + GMaps',
        'has_tiktok'              => 'Buka akun TikTok bisnis dengan handle konsisten',
        'has_website'             => 'Aktifkan landing page sederhana (Linktree/Carrd cukup)',
        'manajemen_ulasan'        => 'Mulai balas semua ulasan Google Maps',
        'kualitas_ulasan_positif' => 'Minta pelanggan tulis ulasan dengan kata kunci spesifik ("harum", "bersih", "tepat waktu")',
        'review_count_tier'       => 'Dorong pelanggan menulis ulasan Google Maps (QR code di outlet)',
        'kehadiran_digital'       => 'Lengkapi touchpoint: IG + Website + GMaps + WA + TikTok',
        'konsistensi_visual'      => 'Selaraskan logo, warna, dan tipografi di IG + signage outlet + foto GMaps',
        'kelengkapan_layanan'     => 'Tambahkan variasi layanan (cuci sepatu / dry cleaning / antar jemput) ≥ 4',
        'transparansi_harga'      => 'Publikasikan daftar harga di IG atau foto outlet',
        'bonus_ekspres'           => 'Aktifkan layanan ekspres dan publikasikan di IG',
        'bonus_antar_jemput'      => 'Tambahkan opsi antar jemput dan publikasikan di IG/Maps',
        'bonus_sop_keluhan'       => 'Bangun SOP balas komplain dan publikasikan',
        'bonus_price_list'        => 'Publikasikan daftar harga di IG atau foto outlet',
        'bonus_variasi_layanan'   => 'Tampilkan variasi layanan lengkap di IG dan Maps (≥ 4)',
    ];

    /**
     * @param array<string,int|null>          $pillarScoreInts
     * @param array<string,array<string,int>> $subBucketScores
     * @param array<string,array<string,mixed>> $scoreBreakdown
     * @return array{current:int,target:int,delta:int,actions:list<array<string,mixed>>}
     */
    public static function compute(
        int $overallScore,
        array $pillarScoreInts,
        array $subBucketScores,
        array $scoreBreakdown,
    ): array {
        // Weighted gap-to-100 picks the two pillars where a point swing
        // matters most for the overall score.
        $weightedGains = [];
        foreach (self::PILLAR_WEIGHTS as $slug => $weight) {
            $score = (int) ($pillarScoreInts[$slug] ?? 0);
            $weightedGains[$slug] = max(0, 100 - $score) * $weight;
        }
        arsort($weightedGains);
        $topGapPillars = array_slice(array_keys($weightedGains), 0, 2);

        // Realistic +10..+15 delta, scaled to the room above.
        $current = $overallScore;
        $room    = max(0, 100 - $current);
        $delta   = $room >= 30 ? 15 : ($room >= 15 ? 12 : min(10, $room));
        $target  = min(100, $current + $delta);

        $actions = [];
        foreach ($topGapPillars as $slug) {
            $sb = (array) ($subBucketScores[$slug] ?? []);
            $bd = (array) ($scoreBreakdown[$slug] ?? []);
            $bestSlug = null;
            $bestGap  = 0;
            foreach ($sb as $k => $v) {
                if (str_starts_with((string) $k, 'penalty_')) continue;
                $row = is_array($bd[$k] ?? null) ? $bd[$k] : [];
                $cap = (int) ($row['cap'] ?? config('branding.pillar_sub_buckets.' . $slug . '.' . $k . '.cap', 0));
                if ($cap <= 0) continue;
                $gap = $cap - (int) $v;
                if ($gap > $bestGap) {
                    $bestGap  = $gap;
                    $bestSlug = (string) $k;
                }
            }
            if ($bestSlug === null) continue;
            $template = self::ACTION_TEMPLATES[$bestSlug] ?? null;
            if ($template === null) continue;

            $actions[] = [
                'text'        => $template,
                'gain'        => $bestGap,
                'pillar'      => self::PILLAR_LABELS[$slug] ?? $slug,
                'bucket'      => $bestSlug,
                'pillar_slug' => $slug,
            ];
        }
        $actions = array_slice($actions, 0, 2);

        return [
            'current' => $current,
            'target'  => $target,
            'delta'   => $delta,
            'actions' => $actions,
        ];
    }
}
