<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Single source-of-truth for user-facing audit dashboard labels.
 *
 * Phase 12c.2 (BB113/BB114): keeps technical jargon, raw property
 * names, and English source strings out of the rendered Blade. Use
 * AuditLabels::subBucket('color_consistency') instead of raw $k.
 *
 * Add new keys here when sub-buckets / signals are added — never
 * inline translations in Blade.
 */
final class AuditLabels
{
    public const SUB_BUCKET = [
        // brand-recall
        'rating_tier'              => 'Rating',
        'review_count_tier'        => 'Jumlah Review',
        'keyword_saturation'       => 'Kata Kunci Positif di Ulasan',
        'review_keyword_quality'   => 'Kata Kunci Positif di Ulasan',
        'kualitas_ulasan_positif'  => 'Kualitas Ulasan Positif',
        'sentiment_quality'        => 'Sentimen Ulasan',
        'manajemen_ulasan'         => 'Manajemen Ulasan',
        'search_recall'            => 'Search Recall',

        // digital-presence
        'has_gmaps'                => 'Google Maps',
        'has_instagram'            => 'Instagram',
        'has_website'              => 'Website',
        'has_wa'                   => 'WhatsApp Business',
        'has_tiktok'               => 'TikTok',
        'review_bonus'             => 'Bonus Review',
        'review_count_5plus'       => 'Ulasan ≥ 10',
        'review_count_50plus'      => 'Ulasan ≥ 50',

        // brand-konsistensi
        'kehadiran_digital'       => 'Kehadiran Digital',
        'konsistensi_visual'      => 'Konsistensi Visual',
        'kelengkapan_layanan'     => 'Kelengkapan Layanan',
        'transparansi_harga'      => 'Transparansi Harga',
        // Reserved for future fine-grained visual sub-buckets (BB112)
        'color_consistency'       => 'Konsistensi Warna',
        'typography_consistency'  => 'Konsistensi Tipografi',
        'logo_consistency'        => 'Konsistensi Logo',
        'imagery_tone'            => 'Konsistensi Imagery & Tone',

        // brand-experience
        'base'                    => 'Skor Dasar',
        'bonus_ekspres'           => 'Layanan Ekspres',
        'bonus_antar_jemput'      => 'Antar Jemput',
        'bonus_variasi_layanan'   => 'Variasi Layanan',
        'bonus_sop_keluhan'       => 'SOP Keluhan',
        'bonus_price_list'        => 'Daftar Harga',
        // BB140 — relabelled from "Penalti X" to neutral "Keluhan 'X'"
        // copy matching the PPT deck. "Penalti" felt punitive even when
        // the score impact was zero; "Keluhan" describes what the rule
        // is actually looking for in the GMaps review corpus.
        'penalty_keterlambatan'   => "Keluhan 'telat/lambat'",
        'penalty_pakaian_hilang'  => "Keluhan 'pakaian tertukar/hilang'",
        'penalty_no_response_wa'  => "Keluhan 'tidak respons WA'",
    ];

    /**
     * Plain-Indonesian description for each Digital Presence touchpoint.
     * Used in BB116 ✓/✗ row layout to replace the bare row with a
     * one-line context line.
     */
    public const TOUCHPOINT_DESCRIPTION = [
        'has_gmaps'     => 'Listing Google Maps untuk membantu pelanggan menemukan lokasi outlet.',
        'has_instagram' => 'Akun Instagram aktif untuk membangun brand di sosial media.',
        'has_website'   => 'Website resmi sebagai kanal informasi formal.',
        'has_wa'        => 'WhatsApp Business untuk komunikasi langsung dengan pelanggan.',
        'has_tiktok'    => 'Akun TikTok untuk menjangkau audiens muda. Opsional, tidak mengurangi skor.',
        'review_bonus'  => 'Bonus jika listing Google Maps memiliki volume ulasan yang signifikan.',
    ];

    /**
     * Sub-bucket-level source attribution. Renders under each sub-bucket
     * detail box. Phrased as plain Indonesian.
     */
    public const SUB_BUCKET_SOURCE = [
        // brand-recall
        'rating_tier'              => 'Sumber: rating bintang dari Google Maps',
        'review_count_tier'        => 'Sumber: jumlah total ulasan dari Google Maps',
        'review_keyword_quality'   => 'Sumber: ulasan Google Maps yang di-scrape',
        'kualitas_ulasan_positif'  => 'Sumber: kata kunci positif + sentimen pada ulasan Google Maps',
        'keyword_saturation'       => 'Sumber: ulasan Google Maps yang di-scrape',
        'sentiment_quality'        => 'Sumber: analisis sentimen ulasan Google Maps',
        'manajemen_ulasan'         => 'Sumber: scrape balasan pemilik di Google Maps reviews',
        'search_recall'            => 'Sumber: Google Search autocomplete (4 query)',

        // digital-presence
        'has_gmaps'                => 'Sumber: URL Google Maps dari form audit',
        'has_instagram'            => 'Sumber: aktivitas feed + story Instagram',
        'has_website'              => 'Sumber: cek HTTP langsung ke website',
        'has_wa'                   => 'Sumber: nomor WhatsApp dari form audit',
        'has_tiktok'               => 'Sumber: cek ketersediaan handle TikTok',
        'review_bonus'             => 'Sumber: total ulasan Google Maps',
        'review_count_5plus'       => 'Sumber: total ulasan Google Maps ≥ 10',
        'review_count_50plus'      => 'Sumber: total ulasan Google Maps ≥ 50',

        // brand-konsistensi
        'kehadiran_digital'       => 'Sumber: kelengkapan touchpoint dari form audit (Instagram, Website, Google Maps, WhatsApp)',
        'konsistensi_visual'      => 'Sumber: analisis AI atas screenshot Instagram + website + Google Maps (logo, warna, tipografi, tone)',
        'kelengkapan_layanan'     => 'Sumber: analisis AI atas copy & bio touchpoint',
        'transparansi_harga'      => 'Sumber: analisis AI atas keberadaan daftar harga di touchpoint',

        // brand-experience
        'base'                    => 'Sumber: skor dasar otomatis untuk setiap audit',
        'bonus_ekspres'           => 'Sumber: analisis AI atas copy touchpoint',
        'bonus_antar_jemput'      => 'Sumber: analisis AI atas copy touchpoint',
        'bonus_variasi_layanan'   => 'Sumber: analisis AI atas copy touchpoint',
        'bonus_sop_keluhan'       => 'Sumber: analisis AI atas copy touchpoint',
        'bonus_price_list'        => 'Sumber: analisis AI atas daftar harga di touchpoint',
        'penalty_keterlambatan'   => 'Sumber: kata kunci keluhan di ulasan Google Maps',
        'penalty_pakaian_hilang'  => 'Sumber: kata kunci keluhan di ulasan Google Maps',
        'penalty_no_response_wa'  => 'Sumber: kata kunci keluhan di ulasan Google Maps',
    ];

    /**
     * Plain-Indonesian formula labels. Replaces the noisy
     * "Threshold tier-based (deterministik)" style strings.
     */
    public const FORMULA = [
        'deterministic_threshold' => 'Rumus: berbasis ambang batas (otomatis, tanpa AI)',
        'deterministic_signals'   => 'Rumus: berdasarkan sinyal autocomplete (otomatis, tanpa AI)',
        'llm_judgment'            => 'Rumus: penilaian AI (Claude)',
    ];

    /**
     * Plain-Indonesian labels for sub-signals inside deterministic_signals
     * (currently used by search_recall).
     */
    public const SIGNAL = [
        'brand_recognition' => 'Pengenalan Brand',
        'geographic_spread' => 'Sebaran Lokasi',
        'variant_coverage'  => 'Variasi Pencarian',
    ];

    /**
     * Plain-Indonesian labels for raw_input keys, used in the
     * "Berdasarkan:" line. Keys not in this map are filtered out
     * unless they look user-friendly.
     */
    public const RAW_INPUT = [
        'sampled_reviews'   => 'Ulasan dianalisis',
        'keyword_hits'      => 'Ulasan menyebut kata kunci positif',
        'hit_rate_pct'      => 'Persentase dari sampel',
        'sample_source'     => 'Sumber sampel',
        'rating_value'      => 'Nilai rating',
        'review_count'      => 'Jumlah ulasan',
        'sentiment_score'   => 'Skor sentimen',
        'sentiment_pct'     => 'Persentase sentimen positif',
    ];

    /**
     * Plain-Indonesian for sample_source enum values that appear in
     * raw_inputs.
     */
    public const SAMPLE_SOURCE = [
        'gmaps_scrape'        => 'ulasan Google Maps (di-scrape)',
        'places_api_sample'   => 'sampel dari Google Places',
        'gmaps_full_scrape'   => 'ulasan Google Maps (full scrape)',
    ];

    public static function subBucket(string $slug): string
    {
        return self::SUB_BUCKET[$slug]
            ?? Str::title(str_replace('_', ' ', $slug));
    }

    public static function touchpointDescription(string $slug): string
    {
        return self::TOUCHPOINT_DESCRIPTION[$slug] ?? '';
    }

    public static function subBucketSource(string $slug): ?string
    {
        return self::SUB_BUCKET_SOURCE[$slug] ?? null;
    }

    public static function formula(string $key): ?string
    {
        return self::FORMULA[$key] ?? null;
    }

    public static function signal(string $key): string
    {
        return self::SIGNAL[$key] ?? Str::title(str_replace('_', ' ', $key));
    }

    public static function rawInput(string $key): ?string
    {
        return self::RAW_INPUT[$key] ?? null;
    }

    public static function sampleSource(string $key): string
    {
        return self::SAMPLE_SOURCE[$key] ?? $key;
    }

    /**
     * Pillar weights as integer percentages — matches config/branding.php.
     */
    public const PILLAR_WEIGHT_PCT = [
        'brand-konsistensi' => 35,
        'brand-recall'      => 35,
        'brand-experience'  => 20,
        'digital-presence'  => 10,
    ];

    /**
     * Distinctive color per pillar for the BB111 pillar breakdown bar.
     */
    public const PILLAR_COLOR = [
        'brand-konsistensi' => '#3D8948',
        'brand-recall'      => '#2EBBA0',
        'brand-experience'  => '#D97852',
        'digital-presence'  => '#5B7BD5',
    ];

    public static function pillarWeight(string $slug): int
    {
        return self::PILLAR_WEIGHT_PCT[$slug] ?? 0;
    }

    public static function pillarColor(string $slug): string
    {
        return self::PILLAR_COLOR[$slug] ?? '#888888';
    }

    /**
     * BB145 — qualitative tier label for an absolute 0-100 score.
     * Mirrors the PPT deck tiers used at the pillar + overall level:
     *
     *   ≥85 → Sempurna
     *   ≥70 → Sangat Baik
     *   ≥55 → Baik
     *   ≥35 → Cukup
     *   <35 → Perlu Perbaikan
     *
     * The fractional tierForRatio() below remains the sub-bucket helper
     * (driven by score/cap rather than an absolute threshold).
     */
    public static function pillarTier(?int $score): string
    {
        $s = (int) ($score ?? 0);
        return match (true) {
            $s >= 85 => 'Sempurna',
            $s >= 70 => 'Sangat Baik',
            $s >= 55 => 'Baik',
            $s >= 35 => 'Cukup',
            default  => 'Perlu Perbaikan',
        };
    }

    /**
     * BB145 — variant (good/warning/bad) for the absolute-score tier
     * badges added at the overall + pillar level. Distinct from
     * tierVariant() because the PPT 5-tier palette is wider than the
     * 3-bucket sub-bucket palette.
     */
    public static function pillarTierVariant(?int $score): string
    {
        $s = (int) ($score ?? 0);
        return match (true) {
            $s >= 70 => 'good',     // Sempurna + Sangat Baik
            $s >= 55 => 'good',     // Baik (still green-leaning)
            $s >= 35 => 'warning',  // Cukup
            default  => 'bad',      // Perlu Perbaikan
        };
    }

    /**
     * Phase 12c.2-rubric-alignment BB119 — tier label from
     * score / cap ratio. Used when a scorer doesn't provide an
     * explicit tier (legacy v2 sub-bucket entries).
     */
    public static function tierForRatio(float $ratio): string
    {
        return match (true) {
            $ratio >= 0.95 => 'sempurna',
            $ratio >= 0.80 => 'sangat baik',
            $ratio >= 0.60 => 'baik',
            $ratio >= 0.40 => 'cukup',
            $ratio >= 0.01 => 'kurang',
            default        => 'tidak ada data',
        };
    }

    /**
     * BB119 — tier label → CSS variant. Maps every PPT-rubric tier
     * label (and the inferred tierForRatio labels) onto one of three
     * color variants: good / warning / bad.
     */
    public const TIER_VARIANT = [
        'sempurna'           => 'good',
        'sangat baik'        => 'good',
        'sangat aktif'       => 'good',
        'sangat konsisten'   => 'good',
        'tinggi'             => 'good',
        'baik'               => 'good',
        'aktif'              => 'good',
        'cukup'              => 'warning',
        'cukup konsisten'    => 'warning',
        'sedang'             => 'warning',
        'jarang'             => 'warning',
        'kurang'             => 'bad',
        'kurang konsisten'   => 'bad',
        'rendah'             => 'bad',
        'sangat kurang'      => 'bad',
        'di bawah rata-rata' => 'bad',
        'tidak aktif'        => 'bad',
        'tidak ada data'     => 'bad',
    ];

    public static function tierVariant(?string $tier): string
    {
        if ($tier === null || $tier === '') {
            return 'bad';
        }
        return self::TIER_VARIANT[mb_strtolower(trim($tier))] ?? 'warning';
    }

    /**
     * BB118 — honest unavailability messages for the per-row
     * "Sumber: tidak tersedia" replacement. Keyed by audit
     * wizard_version so v1/v2 audits surface a clear "pre-rubric"
     * label and v3 audits get a scope-specific failure reason.
     */
    public static function preRubricSource(?string $wizardVersion): string
    {
        return match ($wizardVersion) {
            'v1'    => 'Sumber: tidak tersedia (audit v1, jalankan ulang untuk rubrik baru)',
            'v2'    => 'Sumber: tidak tersedia (audit v2 pra-rubrik, jalankan ulang untuk skor lengkap)',
            default => 'Sumber: tidak tersedia',
        };
    }
}
