<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\BrandAudit;

/**
 * Phase 12c.4 FIX E — LLM-generated reasoning paragraph for the
 * "Target Skor Berikutnya" card on the audit result page.
 *
 * Takes the deterministic pieces (current score, target, chosen
 * actions) and asks Claude Sonnet to explain WHY these specific
 * actions were selected and what realistic impact to expect over
 * the next 3 months. The reasoning is persisted to
 * ``audit_evidence.target_score_reasoning`` so the view never
 * blocks on the LLM call.
 *
 * Output shape (parseResponse):
 *   [
 *     'paragraphs' => list<string>,  // 2-3 short Indonesian paragraphs
 *     'generated_at' => string,      // ISO-8601 stamp
 *   ]
 *
 * Fallback: empty paragraphs list. The view treats absence as
 * "skip the reasoning block" — never renders an error to the user.
 */
final class TargetScoreReasoningGenerator extends AbstractClaudeGenerator
{
    protected const TEMPERATURE = 0.3;
    protected const MAX_TOKENS  = 900;

    /**
     * @param array<int,array{text:string,gain:int,pillar:string,bucket?:string}> $actions
     * @return array<string,mixed>
     */
    public function generate(BrandAudit $audit, int $currentScore, int $targetScore, array $actions): array
    {
        if ($actions === []) {
            return $this->fallbackPayload();
        }

        $systemPrompt = $this->systemPrompt();
        $userPrompt   = $this->userPrompt($audit, $currentScore, $targetScore, $actions);
        $payload      = $this->callAndParse($systemPrompt, $userPrompt, 'target_score_reasoning');

        if (! isset($payload['paragraphs'])) {
            return $this->fallbackPayload();
        }
        $payload['generated_at'] = now()->toIso8601String();
        return $payload;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah analis brand strategist untuk UMKM laundry di Indonesia. Tugasmu: menulis 2 paragraf singkat yang menjelaskan MENGAPA dua aksi spesifik yang dipilih akan membantu brand mencapai target skor audit dalam 3 bulan ke depan.

Aturan:
- Gunakan register saya/kita, bukan gue/lo, bukan kamu.
- Tepat 2 paragraf — satu per aksi. Maksimal 4-5 kalimat per paragraf.
- Sebutkan bukti spesifik dari data audit (skor pilar, sub-bucket, angka konkret).
- Jelaskan dampak yang realistis dalam timeframe 4-8 minggu.
- Jangan menggurui ("kamu harus..."). Tulis seolah membantu owner berpikir.
- Output HANYA JSON dengan kunci "paragraphs" berisi array string. Tidak ada kunci lain, tidak ada teks pembuka/penutup di luar JSON.

Contoh output:
{"paragraphs":["Paragraf pertama tentang aksi 1...","Paragraf kedua tentang aksi 2..."]}
PROMPT;
    }

    /**
     * @param array<int,array{text:string,gain:int,pillar:string}> $actions
     */
    private function userPrompt(BrandAudit $audit, int $currentScore, int $targetScore, array $actions): string
    {
        $delta = $targetScore - $currentScore;
        $pillarLines = [];
        foreach ((array) $audit->pillar_scores as $slug => $data) {
            if (! is_array($data)) continue;
            $score = (int) ($data['score'] ?? 0);
            $pillarLines[] = "  - {$slug}: {$score}/100";
        }
        $pillarsBlock = implode("\n", $pillarLines);

        $actionLines = [];
        foreach ($actions as $i => $a) {
            $n = $i + 1;
            $actionLines[] = "  {$n}. {$a['text']}  (+{$a['gain']} pt {$a['pillar']})";
        }
        $actionsBlock = implode("\n", $actionLines);

        $brand = (string) $audit->brand_name;

        return <<<PROMPT
Brand: {$brand}
Skor saat ini: {$currentScore}/100
Target 3 bulan: {$targetScore}/100 (+{$delta} pt)

Skor per pilar:
{$pillarsBlock}

Dua aksi prioritas yang dipilih (berdasarkan gap terbesar):
{$actionsBlock}

Tulis 2 paragraf yang menjelaskan mengapa kedua aksi ini realistis untuk mencapai target. Sebutkan data audit yang relevan.
PROMPT;
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array<string,mixed>
     */
    protected function parseResponse(array $decoded): array
    {
        $paragraphs = $decoded['paragraphs'] ?? null;
        if (! is_array($paragraphs)) {
            return $this->fallbackPayload();
        }
        $clean = [];
        foreach ($paragraphs as $p) {
            $s = is_string($p) ? trim($p) : '';
            if ($s !== '') {
                $clean[] = $s;
            }
        }
        if ($clean === []) {
            return $this->fallbackPayload();
        }
        return ['paragraphs' => array_slice($clean, 0, 3)];
    }

    /**
     * @return array<string,mixed>
     */
    protected function fallbackPayload(): array
    {
        return ['paragraphs' => []];
    }
}
