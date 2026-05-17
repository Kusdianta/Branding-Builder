<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\BrandAudit;

/**
 * Phase 9 BB37: produces the "Kemenangan Cepat" (Quick Wins) section
 * of the apikprimadya-style PDF.
 *
 * Different from RecommendationGenerator in three ways:
 *
 *   1. Items are TINY — actions completable in minutes/hours, not
 *      days/weeks.
 *   2. No priority/effort/impact pills — every quick win is implicitly
 *      high-impact-per-minute.
 *   3. Each item carries an estimated_minutes estimate so the PDF can
 *      render "5 menit" / "30 menit" / "1-2 jam" badges.
 *
 * Output shape persisted to brand_audits.quick_wins:
 *   [
 *     { action: "Tambahkan link WhatsApp Business ke Instagram bio", estimated_minutes: 5 },
 *     { action: "Pin 3 postingan terbaik di atas profil",            estimated_minutes: 10 },
 *     ...
 *   ]
 */
class QuickWinsGenerator extends AbstractClaudeGenerator
{
    protected const MAX_TOKENS  = 1536;
    protected const TEMPERATURE = 0.5;

    /**
     * @return array{quick_wins: list<array{action: string, estimated_minutes: int}>}
     */
    public function generate(BrandAudit $audit): array
    {
        return $this->callAndParse(
            $this->systemPrompt(),
            $this->userPrompt($audit),
            'QuickWinsGenerator',
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand consultant untuk bisnis laundry di Indonesia. Hasilkan 5-7 "kemenangan cepat" (quick wins) — tindakan kecil yang bisa diselesaikan dalam hitungan menit atau jam, dan langsung memberikan dampak terukur pada brand.

=== ATURAN ===

- Setiap action HARUS bisa diselesaikan ≤ 2 jam (120 menit). Kalau lebih lama, itu masuk RecommendationGenerator, bukan quick win.
- Action TIDAK boleh memerlukan koordinasi tim atau persetujuan stakeholder. Cukup satu orang dengan akses akun.
- Spesifik untuk brand ini — sebut handle Instagram, URL website, atau detail konkret dari audit. JANGAN generic.
- Range estimated_minutes: 5, 10, 15, 30, 60, 90, atau 120. Bulatkan ke salah satu nilai itu.
- Action: kalimat aktif, mulai dengan verb. Format imperative ("Tambahkan...", "Pin...", "Balas...", "Update...").
- Hasilkan 5-7 items. Lebih dari 7 = noise.
- Bahasa Indonesia, register saya/kita.
- HANYA evaluasi touchpoint yang tercantum di blok "Touchpoints AKTIF" pada user prompt. JANGAN menyebut atau memberi rekomendasi untuk touchpoint yang TIDAK terdaftar (contoh: kalau WhatsApp atau TikTok tidak ada, jangan menyinggungnya sama sekali — termasuk jangan menyebut "ketidakjelasan", "belum ada", atau bahasa serupa).

Return ONLY this JSON:
{
  "quick_wins": [
    { "action": "<imperative kalimat>", "estimated_minutes": 5 | 10 | 15 | 30 | 60 | 90 | 120 },
    ... (5-7 items total)
  ]
}
PROMPT;
    }

    private function userPrompt(BrandAudit $audit): string
    {
        $touchpoints  = (array) $audit->touchpoints;
        $pillarScores = (array) $audit->pillar_scores;
        $instagram    = (array) ($audit->instagram_audit ?? []);

        $weakestPillar = '';
        $weakestScore  = PHP_INT_MAX;
        foreach (['brand-recall', 'brand-konsistensi', 'brand-experience', 'digital-presence'] as $slug) {
            $s = (int) ($pillarScores[$slug]['score'] ?? 0);
            if ($s < $weakestScore) {
                $weakestScore  = $s;
                $weakestPillar = $slug;
            }
        }

        $igSnippet = '';
        if (! empty($instagram['username'])) {
            $igSnippet = sprintf(
                "\nInstagram: @%s, bio=%s",
                (string) $instagram['username'],
                $this->truncate((string) ($instagram['bio'] ?? ''), 200),
            );
        }

        $touchpointBlock = $this->renderActiveTouchpoints($touchpoints);

        return <<<TEXT
Brand: {$audit->brand_name}
City: {$audit->city}
Overall score: {$audit->overall_score}/100
Pillar terlemah: {$weakestPillar} (skor {$weakestScore})

Touchpoints AKTIF (HANYA evaluasi yang tercantum di sini):
{$touchpointBlock}
{$igSnippet}

Hasilkan 5-7 quick wins yang fokus pada pillar terlemah, berdasarkan touchpoint aktif di atas.
TEXT;
    }

    /**
     * BB104: render only touchpoints that the operator actually provided
     * so the LLM cannot manufacture commentary about missing signals.
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

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array{quick_wins: list<array{action: string, estimated_minutes: int}>}
     */
    protected function parseResponse(array $decoded): array
    {
        $items = is_array($decoded['quick_wins'] ?? null) ? $decoded['quick_wins'] : [];
        $clean = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $action  = trim((string) ($item['action'] ?? ''));
            $minutes = (int) ($item['estimated_minutes'] ?? 0);
            if ($action === '' || $minutes <= 0) {
                continue;
            }
            // Snap to allowed buckets so the PDF badge styling stays consistent.
            $snapped = $this->snapMinutes($minutes);
            $clean[] = ['action' => $action, 'estimated_minutes' => $snapped];
        }
        return ['quick_wins' => array_slice($clean, 0, 7)];
    }

    private function snapMinutes(int $minutes): int
    {
        $allowed = [5, 10, 15, 30, 60, 90, 120];
        $best = $allowed[0];
        $bestDelta = PHP_INT_MAX;
        foreach ($allowed as $opt) {
            $delta = abs($minutes - $opt);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = $opt;
            }
        }
        return $best;
    }

    /**
     * @return array{quick_wins: list<array{action: string, estimated_minutes: int}>}
     */
    protected function fallbackPayload(): array
    {
        return ['quick_wins' => []];
    }
}
