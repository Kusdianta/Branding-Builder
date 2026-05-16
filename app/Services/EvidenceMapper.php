<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;

/**
 * Phase 10 BB54: single source of truth that converts the
 * audit_evidence JSON column into the per-pillar input arrays the
 * existing scorers expect.
 *
 * Why a mapper instead of refactoring each scorer's constructor?
 *
 *   1. Scorers' input shapes are stable; the data origin is what
 *      Phase 10 is changing (gather phase writes evidence first, then
 *      scoring reads from there instead of fetching inline).
 *   2. Legacy audits and partial-evidence cases need a fallback path
 *      to the old gmaps_reviews / instagram_audit / Places-API columns.
 *      Centralizing the fallback here keeps each scorer simple.
 *   3. BB55 will rewire ScorePillarsJob to feed scorers from this
 *      mapper; the scorer-internal changes stay minimal.
 *
 * Resolution precedence per slice:
 *
 *   places_api   evidence.places_api  ->  null (scorers degrade)
 *   gmaps_scrape evidence.gmaps_scrape -> legacy $audit->gmaps_reviews
 *   instagram    evidence.instagram_* ->  legacy $audit->instagram_audit
 *
 * BB58's fallback path (cap Konsistensi score at 60/100 when visual
 * analysis is unavailable) lives in the scorer, not here — the mapper
 * just surfaces what's available without judging it.
 */
class EvidenceMapper
{
    /**
     * @return array{rating: float, review_count: int, keyword_hits: array<string,mixed>, sampled_reviews: list<mixed>}
     */
    public function placesApi(BrandAudit $audit): array
    {
        $evidence = (array) ($audit->audit_evidence ?? []);
        $places   = $evidence['places_api'] ?? null;

        if (! is_array($places)) {
            return $this->emptyPlacesApi();
        }

        return [
            'rating'          => (float) ($places['rating'] ?? 0.0),
            'review_count'    => (int) ($places['review_count'] ?? 0),
            'keyword_hits'    => (array) ($places['keyword_hits'] ?? ['positive' => [], 'negative' => []]),
            'sampled_reviews' => (array) ($places['sampled_reviews'] ?? []),
        ];
    }

    /**
     * Full review corpus from the GMaps scrape. Falls back to the
     * legacy $audit->gmaps_reviews column when evidence isn't gathered
     * yet. Returns [] when neither source has reviews (no_credentials,
     * scrape_failed, etc.) — RecallScorer + ExperiencePenaltyDetector
     * then fall back to Places-API sampled_reviews.
     *
     * @return list<array<string,mixed>>
     */
    public function fullReviews(BrandAudit $audit): array
    {
        $evidence = (array) ($audit->audit_evidence ?? []);
        $scrape   = $evidence['gmaps_scrape'] ?? null;

        if (! is_array($scrape) || ! isset($scrape['reviews'])) {
            // Legacy fallback for pre-BB52 audits that wrote only to
            // the gmaps_reviews column.
            $scrape = (array) ($audit->gmaps_reviews ?? []);
        }

        $reviews = (array) ($scrape['reviews'] ?? []);
        return array_values(array_filter(
            $reviews,
            static fn ($r): bool => is_array($r) && ($r['text'] ?? '') !== '',
        ));
    }

    /**
     * Raw IG extraction slice — for vision Konsistensi (BB57) and any
     * downstream needing the visual asset paths.
     *
     * @return array{profile_pic_path: ?string, screenshot_path: ?string, post_thumbnail_paths: list<string>, username: ?string}
     */
    public function instagramRaw(BrandAudit $audit): array
    {
        $evidence = (array) ($audit->audit_evidence ?? []);
        $raw      = $evidence['instagram_audit'] ?? null;

        if (! is_array($raw)) {
            // No legacy fallback — pre-Phase-10 raw scrape was never
            // persisted (see BB51 migration comments). Returns empty
            // structure so BB57's vision call can detect missing inputs.
            return $this->emptyInstagramRaw();
        }

        return [
            'profile_pic_path'     => $raw['profile_pic_path'] ?? null,
            'screenshot_path'      => $raw['screenshot_path']  ?? null,
            'post_thumbnail_paths' => (array) ($raw['post_thumbnail_paths'] ?? []),
            'username'             => $raw['username'] ?? null,
        ];
    }

    /**
     * Phase 7-B Claude analysis output (executive_summary, scorecard, …).
     * Falls back to the legacy instagram_audit column for pre-BB52 rows.
     *
     * @return array<string,mixed>|null  null if neither evidence nor legacy has the analysis.
     */
    public function instagramAnalysis(BrandAudit $audit): ?array
    {
        $evidence = (array) ($audit->audit_evidence ?? []);
        $analysis = $evidence['instagram_analysis'] ?? null;

        if (is_array($analysis)) {
            return $analysis;
        }

        $legacy = $audit->instagram_audit;
        return is_array($legacy) ? $legacy : null;
    }

    /**
     * @return array{validation: ?array<string,mixed>, has_warning: bool}
     */
    public function validation(BrandAudit $audit): array
    {
        $evidence = (array) ($audit->audit_evidence ?? []);
        $v        = $evidence['validation'] ?? null;
        if (! is_array($v)) {
            return ['validation' => null, 'has_warning' => false];
        }
        return [
            'validation'  => $v,
            'has_warning' => ((float) ($v['confidence'] ?? 1.0)) < 0.5,
        ];
    }

    private function emptyPlacesApi(): array
    {
        return [
            'rating'          => 0.0,
            'review_count'    => 0,
            'keyword_hits'    => ['positive' => [], 'negative' => []],
            'sampled_reviews' => [],
        ];
    }

    private function emptyInstagramRaw(): array
    {
        return [
            'profile_pic_path'     => null,
            'screenshot_path'      => null,
            'post_thumbnail_paths' => [],
            'username'             => null,
        ];
    }
}
