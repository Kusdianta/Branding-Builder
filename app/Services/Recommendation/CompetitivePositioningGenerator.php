<?php

declare(strict_types=1);

namespace App\Services\Recommendation;

use App\Models\BrandAudit;

/**
 * Phase 9 BB37: produces the "Positioning Kompetitif" + "Peluang
 * Pertumbuhan" callout for the apikprimadya-style PDF.
 *
 * Output shape persisted to brand_audits.competitive_positioning:
 *   {
 *     "narrative": "<1-2 paragraf, ~120-180 kata>",
 *     "growth_opportunity": "<1 kalimat — diquote di kotak callout>"
 *   }
 *
 * The PDF (BB42) renders narrative as body text and growth_opportunity
 * inside the highlighted "Peluang Pertumbuhan" callout block (matches
 * apikprimadya page 8).
 */
final class CompetitivePositioningGenerator extends AbstractClaudeGenerator
{
    protected const MAX_TOKENS  = 1024;
    protected const TEMPERATURE = 0.5;

    /**
     * @return array{narrative: string, growth_opportunity: string}
     */
    public function generate(BrandAudit $audit): array
    {
        return $this->callAndParse(
            $this->systemPrompt(),
            $this->userPrompt($audit),
            'CompetitivePositioningGenerator',
        );
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Kamu adalah brand strategist senior untuk bisnis laundry di Indonesia. Tulis analisis positioning kompetitif yang ringkas namun penuh insight, kemudian satu kalimat "peluang pertumbuhan" untuk callout box.

=== ATURAN ===

- Narrative: 1-2 paragraf. Total 120-180 kata. Sebut posisi brand ini di pasarnya, kekuatan struktural yang membedakan dari pesaing generik, dan risiko strategis yang harus dijaga (rasa puas, ketinggalan tren, dll).
- Growth opportunity: SATU kalimat (15-30 kata) yang menyebut potensi tumbuh konkret kalau gap terbesar ditutup. Format: "Dengan [aksi spesifik], [brand] berpotensi [outcome konkret] dalam [timeframe]."
- Specific to this brand — sebut nama brand, kota, signal data konkret dari audit. JANGAN generic positioning advice.
- Bahasa Indonesia, register saya/kita.

Return ONLY this JSON:
{
  "narrative": "<1-2 paragraf 120-180 kata>",
  "growth_opportunity": "<1 kalimat 15-30 kata>"
}
PROMPT;
    }

    private function userPrompt(BrandAudit $audit): string
    {
        $touchpoints  = (array) $audit->touchpoints;
        $pillarScores = (array) $audit->pillar_scores;
        $gmapsReviews = (array) ($audit->gmaps_reviews ?? []);
        $instagram    = (array) ($audit->instagram_audit ?? []);

        $pillarLines = [];
        foreach (['brand-konsistensi', 'brand-recall', 'brand-experience', 'digital-presence'] as $slug) {
            $s = $pillarScores[$slug]['score'] ?? null;
            $pillarLines[] = sprintf('  - %s: %s/100', $slug, is_numeric($s) ? (int) $s : '—');
        }
        $pillarBlock = implode("\n", $pillarLines);

        $reviewSnippet = '';
        if (! empty($gmapsReviews['reviews']) && is_array($gmapsReviews['reviews'])) {
            $reviewSnippet = sprintf(
                "\nGMaps signal: rating %s, total_reviews %d, business_name '%s'",
                (string) ($gmapsReviews['rating'] ?? '—'),
                (int) ($gmapsReviews['total_review_count'] ?? 0),
                (string) ($gmapsReviews['business_name'] ?? ''),
            );
        }

        $igSnippet = '';
        if (! empty($instagram['username'])) {
            $igSnippet = sprintf(
                "\nInstagram: @%s, %d followers, %d posts, is_verified=%s",
                (string) $instagram['username'],
                (int) ($instagram['followers'] ?? 0),
                (int) ($instagram['posts_count'] ?? 0),
                ! empty($instagram['is_verified']) ? 'yes' : 'no',
            );
        }

        return <<<TEXT
Brand: {$audit->brand_name}
City: {$audit->city}
Service type: {$audit->service_type}
Overall score: {$audit->overall_score}/100

Pillar scores:
{$pillarBlock}
{$reviewSnippet}
{$igSnippet}

Tulis positioning kompetitif sesuai schema.
TEXT;
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array{narrative: string, growth_opportunity: string}
     */
    protected function parseResponse(array $decoded): array
    {
        $narrative = trim((string) ($decoded['narrative'] ?? ''));
        $growth    = trim((string) ($decoded['growth_opportunity'] ?? ''));
        if ($narrative === '' || $growth === '') {
            return $this->fallbackPayload();
        }
        return [
            'narrative'          => $narrative,
            'growth_opportunity' => $growth,
        ];
    }

    /**
     * @return array{narrative: string, growth_opportunity: string}
     */
    protected function fallbackPayload(): array
    {
        return [
            'narrative'          => '',
            'growth_opportunity' => '',
        ];
    }
}
