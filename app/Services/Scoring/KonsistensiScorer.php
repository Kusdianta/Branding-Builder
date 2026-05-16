<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\PillarScore;
use App\Models\ScoringRubric;
use App\Services\ClaudeService;

/**
 * Phase 10 BB54: explicit class for the Konsistensi pillar.
 *
 * Pre-Phase-10, Konsistensi was scored inline via the generic LLM
 * pillar pathway (ClaudeService::scoreLlmPillar) — text-based analysis
 * of touchpoint URL presence + brand context + optional outlet photo
 * blocks. Surfacing the pillar as its own class is the SEAM that BB57
 * swaps with the multimodal cross-touchpoint vision analysis.
 *
 * Two paths today, both in this class:
 *
 *   1. score()              — current (text+photos) LLM call.
 *                             Annotates breakdown with the touchpoint
 *                             URL fingerprint as data_source.
 *
 *   2. scoreFromEvidence()  — STUB. Reads audit_evidence to build the
 *                             current input shape, then delegates to
 *                             score(). BB57 will REPLACE this method's
 *                             internals with the multimodal vision call
 *                             (ClaudeService::analyzeBrandConsistency).
 *                             Until then the behaviour is identical to
 *                             score() — same prompt, same model, just
 *                             routed through the evidence layer.
 */
class KonsistensiScorer
{
    public function __construct(
        private readonly ClaudeService $claude,
    ) {}

    /**
     * Score from the legacy ScorePillarsJob input shape.
     *
     * @param array<string,mixed> $inputs Same shape current
     *        ScorePillarsJob produces for PILLAR_KONSISTENSI.
     */
    public function score(array $inputs): PillarScore
    {
        return $this->claude->scorePillar(ScoringRubric::PILLAR_KONSISTENSI, $inputs);
    }

    /**
     * Score from the Phase 10 audit_evidence shape.
     *
     * Builds the legacy inputs array from audit_evidence, then calls
     * score(). BB57 replaces this implementation with the multimodal
     * vision call.
     *
     * @param array<string,mixed> $evidence  The audit's audit_evidence column.
     * @param array<string,mixed> $context   Per-audit context (brand_name + touchpoint URLs)
     *                                       that the legacy LLM prompt expects.
     */
    public function scoreFromEvidence(array $evidence, array $context): PillarScore
    {
        $inputs = [
            'brand_name'               => (string) ($context['brand_name'] ?? ''),
            'instagram_url'            => (string) ($context['instagram_url'] ?? ''),
            'website_url'              => (string) ($context['website_url'] ?? ''),
            'gmaps_url'                => (string) ($context['gmaps_url'] ?? ''),
            'whatsapp_business_active' => (bool) ($context['whatsapp_business_active'] ?? false),
            'tiktok_url'               => (string) ($context['tiktok_url'] ?? ''),
            'outlet_photo_paths'       => (array) ($context['outlet_photo_paths'] ?? []),
        ];

        $score = $this->score($inputs);

        // Annotate breakdown with which evidence keys were consumed so
        // operators can trace why a score went the way it did. BB57's
        // vision implementation will replace this list with the visual
        // sources actually fed into the multimodal prompt.
        return $this->annotateDataSource($score, $this->resolveDataSources($evidence, $context));
    }

    /**
     * @return list<string>
     */
    private function resolveDataSources(array $evidence, array $context): array
    {
        $sources = ['touchpoint_urls'];
        if (! empty($context['outlet_photo_paths'])) {
            $sources[] = 'outlet_photo_paths';
        }
        if (! empty($evidence['instagram_audit']['screenshot_path'])) {
            $sources[] = 'instagram_screenshot';
        }
        if (! empty($evidence['instagram_audit']['profile_pic_path'])) {
            $sources[] = 'instagram_profile_pic';
        }
        if (! empty($evidence['places_api']['photos'])) {
            $sources[] = 'places_api_photos';
        }
        return $sources;
    }

    /**
     * Stamp the data_source list onto the PillarScore's scoreBreakdown.
     * BB54: live in scoreBreakdown to avoid forcing a DTO schema change.
     * BB57 may promote to a first-class property if vision analysis
     * needs richer provenance metadata.
     *
     * @param list<string> $sources
     */
    private function annotateDataSource(PillarScore $score, array $sources): PillarScore
    {
        $breakdown                = $score->scoreBreakdown;
        $breakdown['data_source'] = $sources;

        return new PillarScore(
            pillarSlug: $score->pillarSlug,
            score: $score->score,
            evidence: $score->evidence,
            reasoning: $score->reasoning,
            subBucketScores: $score->subBucketScores,
            scoreBreakdown: $breakdown,
        );
    }
}
