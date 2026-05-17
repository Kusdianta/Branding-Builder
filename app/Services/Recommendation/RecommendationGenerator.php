<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\BrandAudit;

/**
 * Phase 9 BB37: produces the "5 Rekomendasi Tindakan Utama" section
 * of the apikprimadya-style PDF (and dashboard parity).
 *
 * Output shape persisted to brand_audits.recommendations:
 *
 *   [
 *     {
 *       rank: 1,
 *       title: "Lengkapi Website Bisnis",
 *       priority: "TINGGI",
 *       effort:   "SEDANG",
 *       impact:   "TINGGI",
 *       description: "Less Worry sudah punya brand presence di Google Maps..."
 *     },
 *     ... (4 more)
 *   ]
 *
 * The PDF (BB41) maps these straight onto the apikprimadya
 * "★ #N -- Title / Prioritas: X | Usaha: Y | Dampak: Z / body" cards.
 */
class RecommendationGenerator extends AbstractClaudeGenerator
{
    protected const MAX_TOKENS  = 3072;
    protected const TEMPERATURE = 0.5;

    /**
     * @return array{recommendations: list<array<string,mixed>>}
     */
    public function generate(BrandAudit $audit): array
    {
        $payload = $this->callAndParse(
            $this->systemPrompt(),
            $this->userPrompt($audit),
            'RecommendationGenerator',
        );
        return $payload;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand consultant senior untuk bisnis laundry di Indonesia. Berdasarkan hasil audit brand yang diberikan, hasilkan TEPAT 5 rekomendasi tindakan paling impactful, diurutkan dari prioritas tertinggi.

=== ATURAN ===

- Setiap rekomendasi HARUS spesifik untuk brand ini — sebut nama brand, kota, atau touchpoint konkret. JANGAN generic.
- Ranking by impact × urgency: pillar terlemah → highest priority. Kalau dua pillar sama-sama lemah, pilih yang gap-nya paling actionable.
- Title: kalimat aktif, 4-7 kata, dimulai dengan kata kerja (Lengkapi, Bangun, Optimalkan, Aktifkan, Standardisasi, dst).
- Priority: "TINGGI" (impact langsung pada gap pillar terlemah), "SEDANG" (improvement signifikan tapi tidak urgent), "RENDAH" (polish, tidak unblocking).
- Effort: "RENDAH" (<1 hari), "SEDANG" (1-7 hari), "TINGGI" (>1 minggu effort + koordinasi).
- Impact: "RENDAH" (incremental), "SEDANG" (notable), "TINGGI" (unblocks pillar), "SANGAT TINGGI" (transformasional brand-level).
- Description: 2-3 kalimat. Jelaskan WHAT + WHY + HOW. Sebut data konkret dari audit (skor pillar, jumlah review, presence/absence touchpoint).
- Bahasa Indonesia, register saya/kita.
- HANYA evaluasi touchpoint yang tercantum di blok "Touchpoints AKTIF". JANGAN menyebut atau membahas touchpoint yang TIDAK ADA dalam data (contoh: jangan berkomentar tentang "ketidakjelasan status WhatsApp" atau "TikTok belum aktif" kalau memang tidak ada di daftar — anggap signal itu sengaja di-skip oleh operator dan tidak perlu dievaluasi sama sekali).

Return ONLY this JSON (tanpa markdown, tanpa preamble):
{
  "recommendations": [
    {
      "rank": 1,
      "title": "<4-7 kata mulai dengan verb>",
      "priority": "TINGGI" | "SEDANG" | "RENDAH",
      "effort":   "RENDAH" | "SEDANG" | "TINGGI",
      "impact":   "RENDAH" | "SEDANG" | "TINGGI" | "SANGAT TINGGI",
      "description": "<2-3 kalimat spesifik untuk brand ini>"
    },
    { "rank": 2, ... },
    { "rank": 3, ... },
    { "rank": 4, ... },
    { "rank": 5, ... }
  ]
}
PROMPT;
    }

    private function userPrompt(BrandAudit $audit): string
    {
        $touchpoints   = (array) $audit->touchpoints;
        $pillarScores  = (array) $audit->pillar_scores;
        $subBuckets    = (array) $audit->sub_bucket_scores;
        $gmapsReviews  = (array) ($audit->gmaps_reviews ?? []);
        $instagram     = (array) ($audit->instagram_audit ?? []);

        $pillarSummary = [];
        foreach (['brand-recall', 'brand-konsistensi', 'brand-experience', 'digital-presence'] as $slug) {
            $score = $pillarScores[$slug]['score'] ?? null;
            $pillarSummary[] = sprintf('  - %s: %s/100', $slug, is_numeric($score) ? (int) $score : '—');
        }
        $pillarLines = implode("\n", $pillarSummary);

        $reviewSnippet = '';
        if (! empty($gmapsReviews['reviews']) && is_array($gmapsReviews['reviews'])) {
            $reviewSnippet = "\nGMaps reviews scraped: " . count($gmapsReviews['reviews']) .
                ' (rating ' . (string) ($gmapsReviews['rating'] ?? '—') .
                ', total_review_count ' . (string) ($gmapsReviews['total_review_count'] ?? '—') . ')';
        }

        $igSnippet = '';
        if (! empty($instagram['username'])) {
            $igSnippet = sprintf(
                "\nInstagram: @%s, %d followers, %d posts, is_verified=%s, is_business=%s",
                (string) $instagram['username'],
                (int) ($instagram['followers'] ?? 0),
                (int) ($instagram['posts_count'] ?? 0),
                ! empty($instagram['is_verified']) ? 'yes' : 'no',
                ! empty($instagram['is_business']) ? 'yes' : 'no',
            );
        }

        $touchpointBlock = $this->renderActiveTouchpoints($touchpoints);

        return <<<TEXT
Brand: {$audit->brand_name}
City: {$audit->city}
Service type: {$audit->service_type}
Overall score: {$audit->overall_score}/100

Touchpoints AKTIF (HANYA evaluasi yang tercantum di sini):
{$touchpointBlock}

Pillar scores:
{$pillarLines}

Sub-bucket scores (raw):
{$this->jsonBlock($subBuckets)}
{$reviewSnippet}
{$igSnippet}

Hasilkan 5 rekomendasi paling impactful sesuai schema. Prioritaskan pillar dengan skor terendah, berdasarkan touchpoint aktif di atas.
TEXT;
    }

    /**
     * BB104: render only touchpoints that the operator actually provided
     * so the LLM cannot manufacture absence-based commentary.
     *
     * @param array<string,mixed> $touchpoints
     */
    private function renderActiveTouchpoints(array $touchpoints): string
    {
        $candidates = [
            'instagram_url' => $touchpoints['instagram_url'] ?? null,
            'website_url'   => $touchpoints['website_url']   ?? null,
            'gmaps_url'     => $touchpoints['gmaps_url']     ?? null,
            'tiktok_url'    => $touchpoints['tiktok_url']    ?? null,
            'whatsapp_url'  => $touchpoints['whatsapp_url']  ?? null,
        ];

        $lines = [];
        foreach ($candidates as $key => $value) {
            if ($value === null || $value === '' || $value === false) {
                continue;
            }
            $lines[] = sprintf('  - %s: %s', $key, (string) $value);
        }

        return $lines === []
            ? '  (tidak ada touchpoint digital aktif yang diberikan)'
            : implode("\n", $lines);
    }

    private function str(mixed $v): string
    {
        return ($v === null || $v === '' || $v === false) ? '(kosong)' : (string) $v;
    }

    private function bool(mixed $v): string
    {
        return $v ? 'true' : 'false';
    }

    private function jsonBlock(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}';
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array{recommendations: list<array<string,mixed>>}
     */
    protected function parseResponse(array $decoded): array
    {
        $items = is_array($decoded['recommendations'] ?? null) ? $decoded['recommendations'] : [];
        $clean = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = (string) ($item['title'] ?? '');
            $description = (string) ($item['description'] ?? '');
            if ($title === '' || $description === '') {
                continue;
            }
            $clean[] = [
                'rank'        => (int) ($item['rank'] ?? ($i + 1)),
                'title'       => $title,
                'priority'    => $this->normalisePill((string) ($item['priority'] ?? 'SEDANG'), ['TINGGI', 'SEDANG', 'RENDAH']),
                'effort'      => $this->normalisePill((string) ($item['effort'] ?? 'SEDANG'), ['RENDAH', 'SEDANG', 'TINGGI']),
                'impact'      => $this->normalisePill((string) ($item['impact'] ?? 'SEDANG'), ['RENDAH', 'SEDANG', 'TINGGI', 'SANGAT TINGGI']),
                'description' => $description,
            ];
        }
        return ['recommendations' => array_slice($clean, 0, 5)];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalisePill(string $value, array $allowed): string
    {
        $upper = mb_strtoupper(trim($value));
        return in_array($upper, $allowed, true) ? $upper : 'SEDANG';
    }

    /**
     * @return array{recommendations: list<array<string,mixed>>}
     */
    protected function fallbackPayload(): array
    {
        return ['recommendations' => []];
    }
}
