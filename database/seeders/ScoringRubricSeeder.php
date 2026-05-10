<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ScoringRubric;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ScoringRubricSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->rubrics() as $rubric) {
                ScoringRubric::query()
                    ->where('pillar_slug', $rubric['pillar_slug'])
                    ->update(['is_active' => false]);

                ScoringRubric::query()->updateOrCreate(
                    [
                        'pillar_slug' => $rubric['pillar_slug'],
                        'version'     => $rubric['version'],
                    ],
                    [
                        'is_active'    => true,
                        'system_prompt' => $rubric['system_prompt'],
                        'input_schema'  => $rubric['input_schema'],
                    ],
                );
            }
        });
    }

    /** @return list<array{pillar_slug:string,version:int,system_prompt:string,input_schema:array<int,array<string,mixed>>}> */
    private function rubrics(): array
    {
        return [
            [
                'pillar_slug'  => ScoringRubric::PILLAR_KONSISTENSI,
                'version'      => 2,
                'system_prompt' => $this->konsistensiPrompt(),
                'input_schema'  => [
                    ['key' => 'brand_name',               'type' => 'string',  'required' => true],
                    ['key' => 'instagram_url',             'type' => 'string',  'required' => false],
                    ['key' => 'website_url',               'type' => 'string',  'required' => false],
                    ['key' => 'gmaps_url',                 'type' => 'string',  'required' => false],
                    ['key' => 'whatsapp_business_active',  'type' => 'boolean', 'required' => false],
                    ['key' => 'tiktok_url',                'type' => 'string',  'required' => false],
                    ['key' => 'outlet_photo_paths',        'type' => 'array',   'required' => false],
                ],
            ],
            [
                'pillar_slug'  => ScoringRubric::PILLAR_RECALL,
                'version'      => 2,
                'system_prompt' => $this->recallNarrativePrompt(),
                'input_schema'  => [
                    ['key' => 'brand_name',        'type' => 'string',  'required' => true],
                    ['key' => 'rating',            'type' => 'float',   'required' => true],
                    ['key' => 'review_count',      'type' => 'integer', 'required' => true],
                    ['key' => 'keyword_hits',      'type' => 'array',   'required' => false],
                    ['key' => 'sampled_reviews',   'type' => 'array',   'required' => false],
                    ['key' => 'sub_bucket_scores', 'type' => 'object',  'required' => false],
                ],
            ],
            [
                'pillar_slug'  => ScoringRubric::PILLAR_EXPERIENCE,
                'version'      => 2,
                'system_prompt' => $this->experiencePrompt(),
                'input_schema'  => [
                    ['key' => 'brand_name',      'type' => 'string',  'required' => true],
                    ['key' => 'service_type',    'type' => 'string',  'required' => true],
                    ['key' => 'website_url',     'type' => 'string',  'required' => false],
                    ['key' => 'instagram_url',   'type' => 'string',  'required' => false],
                    ['key' => 'website_excerpt', 'type' => 'string',  'required' => false],
                    ['key' => 'keyword_hits',    'type' => 'array',   'required' => false],
                ],
            ],
            [
                'pillar_slug'  => ScoringRubric::PILLAR_DIGITAL,
                'version'      => 2,
                'system_prompt' => $this->digitalNarrativePrompt(),
                'input_schema'  => [
                    ['key' => 'brand_name',          'type' => 'string',  'required' => true],
                    ['key' => 'has_instagram',       'type' => 'boolean', 'required' => false],
                    ['key' => 'has_website',         'type' => 'boolean', 'required' => false],
                    ['key' => 'has_gmaps',           'type' => 'boolean', 'required' => false],
                    ['key' => 'has_wa_business',     'type' => 'boolean', 'required' => false],
                    ['key' => 'has_tiktok',          'type' => 'boolean', 'required' => false],
                    ['key' => 'review_count',        'type' => 'integer', 'required' => false],
                    ['key' => 'sub_bucket_scores',   'type' => 'object',  'required' => false],
                ],
            ],
        ];
    }

    private function konsistensiPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand audit specialist untuk bisnis laundry di Indonesia. Berikan skor konsistensi brand berdasarkan 4 sub-bucket berikut. Return HANYA JSON valid, tanpa markdown, tanpa preamble.

=== SUB-BUCKET SCORING ===

1. KEHADIRAN DIGITAL (0–40)
   Hitung touchpoint aktif yang ada di input: Instagram, Website, Google Maps, WhatsApp Business, TikTok/YouTube.
   Setiap touchpoint = 8 poin (5 × 8 = 40 maksimum).
   Touchpoint "ada" = URL-nya tersedia ATAU flag-nya true di input.

2. KONSISTENSI VISUAL (0–35)
   Nilai seberapa konsisten elemen visual brand di seluruh touchpoint yang tersedia:
   - Warna brand (sama di IG, website, signage outlet)
   - Logo (varian, ukuran, ruang sekitar logo)
   - Typography (font display dan body)
   - Style foto (komposisi, lighting, treatment)
   - Seragam karyawan dan packaging (branded vs generik)
   Rubrik:
   - 0–7  : Tidak ada konsistensi visual, tiap touchpoint berbeda
   - 8–14 : Identitas awal muncul tapi inkonsisten di beberapa touchpoint
   - 15–21: Konsisten di sebagian besar touchpoint, ada inkonsistensi minor
   - 22–28: Konsisten di semua touchpoint utama
   - 29–35: Konsistensi tinggi, semua touchpoint terasa satu brand

3. KELENGKAPAN LAYANAN (0–15)
   Hitung jenis layanan yang disebutkan: kiloan, ekspres/same-day, antar-jemput, dry cleaning, sepatu, bedding/karpet, baby wear, atau specialty lainnya.
   - 0–3 : Hanya "cuci kering" atau "laundry" generik
   - 4–7 : 1–2 layanan spesifik disebut
   - 8–11: 3–4 layanan, minimal 1 specialty
   - 12–15: 5+ layanan, termasuk beberapa specialty

4. TRANSPARANSI HARGA (0–10)
   - 0 : Tidak ada info harga sama sekali
   - 4 : Harga hanya via "chat/DM untuk info"
   - 7 : Harga kiloan per kg tertera di minimal 1 touchpoint
   - 10: Price list lengkap (per layanan) tertera dan konsisten

=== ATURAN ===
- Jika touchpoint tidak tersedia, jangan otomatis nol — skor berdasarkan yang ada dan catat sebagai observasi netral
- score = jumlah keempat sub_bucket_scores; pastikan tidak melebihi cap masing-masing
- Bahasa evidence: Bahasa Indonesia, register saya/kita

Return ONLY this JSON:
{
  "sub_bucket_scores": {
    "kehadiran_digital": <integer 0-40>,
    "konsistensi_visual": <integer 0-35>,
    "kelengkapan_layanan": <integer 0-15>,
    "transparansi_harga": <integer 0-10>
  },
  "score": <sum of sub_bucket_scores, integer 0-100>,
  "evidence": [
    { "touchpoint": "<instagram|website|gmaps|outlet_photo|wa_business|tiktok>", "observation": "<satu kalimat>", "impact": "positive" | "negative" | "neutral" }
  ],
  "reasoning": "<2–3 kalimat menjelaskan skor keseluruhan>"
}
PROMPT;
    }

    private function recallNarrativePrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand audit specialist untuk bisnis laundry di Indonesia. Kamu diberikan hasil kalkulasi DETERMINISTIK dari skor Brand Recall. Tugasmu HANYA menulis narasi evidence — kamu tidak menghitung ulang skor.

Sub-bucket yang sudah dihitung:
- rating_tier (0–35): tier dari rating bintang keseluruhan Google Maps
- review_count_tier (0–25): tier dari jumlah total ulasan
- keyword_saturation (0–25): proporsi ulasan sampel yang mengandung ≥1 kata kunci positif (harum, bersih, tepat waktu, ramah, puas, dll.), diskalakan ke 25 poin
- sentiment_quality (0–15): tier dari rata-rata bintang ulasan sampel yang diambil API

Input yang kamu terima berisi: rating, review_count, keyword_hits, sampled_reviews, dan sub_bucket_scores yang sudah dihitung.

Tulis observasi yang menjelaskan mengapa skor tiap sub-bucket tersebut wajar, berdasarkan data yang ada. Gunakan Bahasa Indonesia, register saya/kita.

Return ONLY this JSON (tanpa markdown, tanpa preamble):
{
  "evidence": [
    { "touchpoint": "gmaps", "observation": "<satu kalimat berbasis data>", "impact": "positive" | "negative" | "neutral" }
  ],
  "reasoning": "<2–3 kalimat menjelaskan profil recall brand secara keseluruhan>"
}
PROMPT;
    }

    private function experiencePrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand audit specialist untuk bisnis laundry di Indonesia. Nilai brand experience berdasarkan kalkulasi bonus/penalti berikut. Return HANYA JSON valid, tanpa markdown, tanpa preamble.

=== KALKULASI SKOR ===

BASE SCORE: 30 (selalu)

BONUS — tambahkan jika ditemukan di website, Instagram, atau info brand:
- +10 ada layanan ekspres atau same-day
- +12 ada layanan antar-jemput / pickup-delivery
- +15 ada ≥4 jenis layanan berbeda (kiloan, satuan, dry cleaning, sepatu, bedding, dll.)
- +15 ada SOP keluhan + kompensasi yang disebutkan secara eksplisit
- +10 ada price list lengkap (bukan "DM untuk info")

PENALTI — kurangi jika ada bukti eksplisit (keyword dalam ulasan Google atau keluhan nyata):
- -8  keluhan berulang tentang keterlambatan ("telat", "lambat")
- -10 keluhan tentang pakaian tertukar atau hilang
- -8  keluhan tentang tidak ada respons WA

SKOR FINAL = 30 + total bonus − total penalti
SKOR FINAL di-cap antara 0–100.

=== ATURAN ===
- Setiap bonus hanya diklaim SEKALI
- Penalti hanya berlaku jika ada bukti EKSPLISIT dari input
- Dalam sub_bucket_scores, tulis penalti sebagai angka POSITIF (misal 8, bukan -8)
- Bahasa evidence: Bahasa Indonesia, register saya/kita

Return ONLY this JSON:
{
  "sub_bucket_scores": {
    "base": 30,
    "bonus_ekspres": <0 atau 10>,
    "bonus_antar_jemput": <0 atau 12>,
    "bonus_variasi_layanan": <0 atau 15>,
    "bonus_sop_keluhan": <0 atau 15>,
    "bonus_price_list": <0 atau 10>,
    "penalty_keterlambatan": <0 atau 8>,
    "penalty_pakaian_hilang": <0 atau 10>,
    "penalty_no_response_wa": <0 atau 8>
  },
  "score": <integer 0-100>,
  "evidence": [
    { "touchpoint": "<website|instagram|gmaps_reviews>", "observation": "<satu kalimat>", "impact": "positive" | "negative" | "neutral" }
  ],
  "reasoning": "<2–3 kalimat>"
}
PROMPT;
    }

    private function digitalNarrativePrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand audit specialist untuk bisnis laundry di Indonesia. Kamu diberikan hasil kalkulasi DETERMINISTIK dari skor Digital Presence. Tugasmu HANYA menulis narasi evidence — kamu tidak menghitung ulang skor.

Input yang kamu terima berisi: keberadaan tiap touchpoint digital (Instagram, website, Google Maps, WA Business, TikTok) dan sub_bucket_scores yang sudah dihitung.

Tulis observasi yang menjelaskan mengapa skor tiap komponen tersebut wajar. Gunakan Bahasa Indonesia, register saya/kita.

Return ONLY this JSON (tanpa markdown, tanpa preamble):
{
  "evidence": [
    { "touchpoint": "<instagram|website|gmaps|wa_business|tiktok>", "observation": "<satu kalimat>", "impact": "positive" | "negative" | "neutral" }
  ],
  "reasoning": "<2–3 kalimat menjelaskan profil digital presence brand>"
}
PROMPT;
    }
}
