<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTO\EvidenceItem;
use App\DTO\PillarScore;
use App\Models\ScoringRubric;
use App\Services\ClaudeService;

/**
 * Phase 10 Konsistensi scorer (BB54 stub -> BB57 vision implementation).
 *
 * Decides between two analysis strategies based on which visual assets
 * the gather phase produced:
 *
 *   VISION  (BB57)  — when at least one of {IG profile pic, IG grid
 *                     screenshot, GMaps page screenshot, Places API
 *                     photos} is present, call
 *                     ClaudeService::analyzeBrandConsistency for a
 *                     multimodal cross-touchpoint visual analysis.
 *                     Output drives sub-bucket scores for
 *                     color_consistency / typography_consistency /
 *                     logo_consistency / imagery_tone.
 *
 *   FALLBACK (BB58) — when no visual assets are present, delegate to
 *                     the legacy text-only ClaudeService::scorePillar
 *                     path and cap the score at 60/100 (top-tier
 *                     scoring requires visual analysis).
 *
 * scoreFromEvidence() routes; score() exposes the legacy path for
 * tests and callers that haven't migrated to the evidence layer.
 */
class KonsistensiScorer
{
    /** BB58: visual analysis required for a top-tier score. */
    private const FALLBACK_SCORE_CAP = 60;

    public function __construct(
        private readonly ClaudeService $claude,
    ) {}

    /**
     * Score from the legacy ScorePillarsJob input shape (text + outlet photos).
     *
     * @param array<string,mixed> $inputs
     */
    public function score(array $inputs): PillarScore
    {
        return $this->claude->scorePillar(ScoringRubric::PILLAR_KONSISTENSI, $inputs);
    }

    /**
     * Phase 10 entry: select VISION vs FALLBACK based on assets.
     *
     * @param array<string,mixed> $evidence  The audit's audit_evidence column.
     * @param array<string,mixed> $context   Per-audit context.
     */
    public function scoreFromEvidence(array $evidence, array $context): PillarScore
    {
        $assets = $this->collectVisualAssets($evidence, $context);

        if (count($assets['paths']) === 0) {
            return $this->scoreFallback($evidence, $context, $assets['data_source']);
        }

        return $this->scoreVision($evidence, $context, $assets);
    }

    /**
     * BB57: multimodal vision path.
     *
     * @param array{paths: list<string>, data_source: list<string>, vision_payload: array<string,mixed>} $assets
     */
    private function scoreVision(array $evidence, array $context, array $assets): PillarScore
    {
        $brandName = (string) ($context['brand_name'] ?? '');

        $visionResult = $this->claude->analyzeBrandConsistency(array_merge(
            $assets['vision_payload'],
            ['brand_name' => $brandName],
        ));

        return $this->hydrateVisionPillarScore($visionResult, $brandName, $assets['data_source']);
    }

    /**
     * BB58: fallback text-only path with score cap.
     *
     * BB104: only forward touchpoint signals that are actually present.
     * Empty URLs and a false whatsapp_business_active flag are dropped
     * so the LLM prompt builder (ClaudeService::renderInputsAsText)
     * does not surface them as "(tidak tersedia)" lines that the model
     * has historically converted into hallucinated absence commentary.
     *
     * @param list<string> $dataSource
     */
    private function scoreFallback(array $evidence, array $context, array $dataSource): PillarScore
    {
        $inputs = [
            'brand_name'         => (string) ($context['brand_name'] ?? ''),
            'outlet_photo_paths' => (array) ($context['outlet_photo_paths'] ?? []),
        ];

        foreach ([
            'instagram_url' => $context['instagram_url'] ?? null,
            'website_url'   => $context['website_url']   ?? null,
            'gmaps_url'     => $context['gmaps_url']     ?? null,
            'tiktok_url'    => $context['tiktok_url']    ?? null,
        ] as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $inputs[$key] = $value;
            }
        }

        if ((bool) ($context['whatsapp_business_active'] ?? false)) {
            $inputs['whatsapp_business_active'] = true;
        }

        $score = $this->score($inputs);

        // Cap at 60 — visual analysis unavailable, can't earn top-tier.
        $cappedScore = min(self::FALLBACK_SCORE_CAP, $score->score);

        $breakdown = $score->scoreBreakdown;
        $breakdown['data_source']          = $dataSource ?: ['touchpoint_urls'];
        $breakdown['analysis_path']        = 'fallback_text_only';
        $breakdown['score_pre_cap']        = $score->score;
        $breakdown['fallback_cap_applied'] = $score->score > self::FALLBACK_SCORE_CAP;

        $limitation = 'Analisis konsistensi visual tidak tersedia — fallback ke pemeriksaan touchpoint berbasis teks. Skor dibatasi maksimum '
            . self::FALLBACK_SCORE_CAP . '/100.';

        return new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_KONSISTENSI,
            score: $cappedScore,
            evidence: $score->evidence,
            reasoning: $score->reasoning . "\n\n" . $limitation,
            subBucketScores: $score->subBucketScores,
            scoreBreakdown: $breakdown,
        );
    }

    /**
     * Collect available visual asset paths from evidence + context.
     *
     * @return array{paths: list<string>, data_source: list<string>, vision_payload: array<string,mixed>}
     */
    private function collectVisualAssets(array $evidence, array $context): array
    {
        $igRaw    = (array) ($evidence['instagram_audit'] ?? []);
        $gmaps    = (array) ($evidence['gmaps_scrape'] ?? []);
        $places   = (array) ($evidence['places_api'] ?? []);

        $igProfilePic = $igRaw['profile_pic_path']     ?? null;
        $igGrid       = $igRaw['screenshot_path']      ?? null;
        $gmapsShot    = $gmaps['gmaps_screenshot_path'] ?? null;

        $placesPhotos = [];
        foreach ((array) ($places['photos'] ?? []) as $p) {
            $path = is_string($p) ? $p : ((string) ($p['path'] ?? ''));
            if ($path !== '') {
                $placesPhotos[] = $path;
            }
        }

        $paths      = [];
        $dataSource = ['touchpoint_urls'];

        if (is_string($igProfilePic) && $igProfilePic !== '') {
            $paths[] = $igProfilePic;
            $dataSource[] = 'instagram_profile_pic';
        }
        if (is_string($igGrid) && $igGrid !== '') {
            $paths[] = $igGrid;
            $dataSource[] = 'instagram_screenshot';
        }
        if (is_string($gmapsShot) && $gmapsShot !== '') {
            $paths[] = $gmapsShot;
            $dataSource[] = 'gmaps_screenshot';
        }
        if ($placesPhotos !== []) {
            $paths = array_merge($paths, array_slice($placesPhotos, 0, 2));
            $dataSource[] = 'places_api_photos';
        }

        $visionPayload = [
            'instagram_profile_pic_path' => is_string($igProfilePic) ? $igProfilePic : null,
            'instagram_screenshot_path'  => is_string($igGrid)       ? $igGrid       : null,
            'gmaps_screenshot_path'      => is_string($gmapsShot)    ? $gmapsShot    : null,
            'places_photo_paths'         => $placesPhotos,
        ];

        return [
            'paths'          => $paths,
            'data_source'    => $dataSource,
            'vision_payload' => $visionPayload,
        ];
    }

    /**
     * Convert the structured vision response into a PillarScore.
     * Weights: color 35%, typography 15%, logo 25%, imagery 25%.
     * (Typography weighted lowest since IG/GMaps screenshots rarely
     * expose enough type to grade fairly; the LLM is told to score 50
     * when it can't see typography clearly.)
     *
     * @param array<string,mixed> $vision
     * @param list<string>        $dataSource
     */
    private function hydrateVisionPillarScore(array $vision, string $brandName, array $dataSource): PillarScore
    {
        $color  = (int) ($vision['color_consistency']['score']       ?? 50);
        $typo   = (int) ($vision['typography_consistency']['score']  ?? 50);
        $logo   = (int) ($vision['logo_consistency']['score']        ?? 50);
        $imager = (int) ($vision['imagery_tone']['score']            ?? 50);

        $weighted = ($color * 0.35) + ($typo * 0.15) + ($logo * 0.25) + ($imager * 0.25);
        $overall  = (int) round($weighted);

        // Konsistensi pillar is reported on a 0-100 scale; the four
        // sub-buckets each get a portion of the total mapped to their
        // weight so the score_breakdown numbers add up cleanly.
        $subBucketScores = [
            'color_consistency'      => $color,
            'typography_consistency' => $typo,
            'logo_consistency'       => $logo,
            'imagery_tone'           => $imager,
        ];

        $subBucketReasoning = [
            'color_consistency'      => (string) ($vision['color_consistency']['observations']       ?? ''),
            'typography_consistency' => (string) ($vision['typography_consistency']['observations']  ?? ''),
            'logo_consistency'       => (string) ($vision['logo_consistency']['observations']        ?? ''),
            'imagery_tone'           => (string) ($vision['imagery_tone']['observations']            ?? ''),
        ];

        $evidenceItems = [];
        foreach ($subBucketReasoning as $bucket => $observation) {
            if ($observation === '') {
                continue;
            }
            $score = $subBucketScores[$bucket] ?? 50;
            $impact = $score >= 70
                ? EvidenceItem::IMPACT_POSITIVE
                : ($score <= 40 ? EvidenceItem::IMPACT_NEGATIVE : EvidenceItem::IMPACT_NEUTRAL);
            $evidenceItems[] = new EvidenceItem(
                touchpoint:  $bucket,
                observation: $observation,
                impact:      $impact,
            );
        }

        $reasoning = (string) ($vision['overall_visual_coherence']['summary'] ?? '')
            ?: 'Analisis vision-Konsistensi selesai.';

        $breakdown = [
            'data_source'           => $dataSource,
            'analysis_path'         => 'vision_multimodal',
            'sub_bucket_scores'     => $subBucketScores,
            'sub_bucket_reasoning'  => $subBucketReasoning,
            'touchpoints_analyzed'  => (array) ($vision['touchpoints_analyzed'] ?? []),
            'limitations'           => (array) ($vision['limitations'] ?? []),
            'overall_visual_score'  => (int) ($vision['overall_visual_coherence']['score'] ?? $overall),
            'brand_name'            => $brandName,
        ];

        return new PillarScore(
            pillarSlug: ScoringRubric::PILLAR_KONSISTENSI,
            score: $overall,
            evidence: $evidenceItems,
            reasoning: $reasoning,
            subBucketScores: $subBucketScores,
            scoreBreakdown: $breakdown,
        );
    }
}
