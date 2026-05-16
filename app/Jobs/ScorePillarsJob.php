<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\EvidenceMapper;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use App\Services\Scoring\ExperiencePenaltyDetector;
use App\Services\Scoring\KonsistensiScorer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 10 BB55: Phase 3 of the 3-phase pipeline.
 *
 * REFACTORED — no longer fetches data inline. The gather phase (BB52
 * GatherEvidenceJob -> Fetch*Job) already populated audit_evidence;
 * this job pulls inputs from EvidenceMapper instead.
 *
 *   gather_gmaps      -> evidence.places_api      (RecallScorer)
 *   gather_gmaps      -> evidence.gmaps_scrape    (Recall + Experience penalty)
 *   gather_instagram  -> evidence.instagram_*     (KonsistensiScorer post-BB57)
 *
 * Audit_steps surfaced: score_recall, score_digital, score_konsistensi,
 * score_experience. The previous fetch_gmaps / fetch_gmaps_reviews /
 * apply_experience_penalties / aggregate_pillars steps are gone —
 * penalty application and aggregation are now implicit (no separate
 * step rows; their logic still runs at the end of handle()).
 *
 * Konsistensi pillar routes through KonsistensiScorer (BB54) instead
 * of the generic LLM pathway. Behaviour is identical today; BB57 will
 * swap the scorer's internal implementation to the multimodal vision
 * call without changing this job.
 */
class ScorePillarsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Without inline gather, scoring runs in ~30-40s. Buffer for LLM retries. */
    public int $timeout = 180;

    public function __construct(public readonly string $auditId) {}

    public function handle(
        EvidenceMapper $evidenceMapper,
        ExperiencePenaltyDetector $penaltyDetector,
        KonsistensiScorer $konsistensiScorer,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audit        = BrandAudit::findOrFail($this->auditId);
        $touchpoints  = (array) $audit->touchpoints;
        $brandName    = (string) $audit->brand_name;
        $serviceType  = (string) $audit->service_type;
        $gmapsUrl     = (string) ($touchpoints['gmaps_url'] ?? '');
        $instagramUrl = (string) ($touchpoints['instagram_url'] ?? '');
        $websiteUrl   = (string) ($touchpoints['website_url'] ?? '');
        $tiktokUrl    = (string) ($touchpoints['tiktok_url'] ?? '');
        $waActive     = (bool) ($touchpoints['whatsapp_business_active'] ?? false);
        $photoPaths   = (array) ($touchpoints['outlet_photo_paths'] ?? []);

        // ── Inputs from gather phase (no inline fetches) ─────────────
        $placesApi  = $evidenceMapper->placesApi($audit);
        $fullReviews = $this->normalizeReviewsForScoring($evidenceMapper->fullReviews($audit));
        $websiteData = $this->fetchWebsite($websiteUrl); // not yet in evidence layer
        $presence = (new TouchpointPresenceDetector())->detect([
            'instagram_url'            => $instagramUrl,
            'website_url'              => $websiteUrl,
            'gmaps_url'                => $gmapsUrl,
            'whatsapp_business_active' => $waActive,
            'tiktok_url'               => $tiktokUrl,
            'review_count'             => $placesApi['review_count'],
        ]);

        // ── Per-pillar input bundles ─────────────────────────────────
        $pillarInputs = [
            ScoringRubric::PILLAR_RECALL => [
                'brand_name'      => $brandName,
                'rating'          => $placesApi['rating'],
                'review_count'    => $placesApi['review_count'],
                'keyword_hits'    => $placesApi['keyword_hits'],
                'sampled_reviews' => $placesApi['sampled_reviews'],
                'full_reviews'    => $fullReviews,
            ],
            ScoringRubric::PILLAR_DIGITAL => array_merge(
                $presence,
                ['brand_name' => $brandName],
            ),
            ScoringRubric::PILLAR_KONSISTENSI => [
                'brand_name'               => $brandName,
                'instagram_url'            => $instagramUrl,
                'website_url'              => $websiteUrl,
                'gmaps_url'                => $gmapsUrl,
                'whatsapp_business_active' => $waActive,
                'tiktok_url'               => $tiktokUrl,
                'outlet_photo_paths'       => $photoPaths,
            ],
            ScoringRubric::PILLAR_EXPERIENCE => [
                'brand_name'      => $brandName,
                'service_type'    => $serviceType,
                'instagram_url'   => $instagramUrl,
                'website_url'     => $websiteUrl,
                'website_excerpt' => (string) ($websiteData['text_content'] ?? ''),
                'keyword_hits'    => $placesApi['keyword_hits'],
            ],
        ];

        $pillarStepMap = [
            ScoringRubric::PILLAR_RECALL      => 'score_recall',
            ScoringRubric::PILLAR_DIGITAL     => 'score_digital',
            ScoringRubric::PILLAR_KONSISTENSI => 'score_konsistensi',
            ScoringRubric::PILLAR_EXPERIENCE  => 'score_experience',
        ];

        $evidence = (array) ($audit->audit_evidence ?? []);

        foreach ($pillarInputs as $slug => $inputs) {
            $step = $this->step($pillarStepMap[$slug]);
            $step?->markRunning();
            try {
                if ($slug === ScoringRubric::PILLAR_KONSISTENSI) {
                    // BB57+BB58: route Konsistensi through the dedicated
                    // scorer so the vision multimodal call (or BB58
                    // fallback) takes effect. The scorer returns a
                    // PillarScore we persist with the same shape
                    // ScorePillarJob would have written.
                    $score = $konsistensiScorer->scoreFromEvidence($evidence, $inputs);
                    $this->persistPillarScore($score);
                } else {
                    ScorePillarJob::dispatchSync($this->auditId, $slug, $inputs);
                }
                $this->stampDataSourceForPillar($slug, $evidenceMapper, $audit);
                $step?->markDone();
            } catch (Throwable $e) {
                Log::warning('ScorePillarsJob: pillar failed', [
                    'audit_id' => $this->auditId, 'pillar' => $slug, 'error' => $e->getMessage(),
                ]);
                $step?->markFailed($e->getMessage());
            }
        }

        // ── Implicit: experience penalties (no step row anymore) ─────
        try {
            $reviews = $this->reviewsForPenaltyDetector($evidenceMapper->fullReviews($audit));
            $payload = $penaltyDetector->detect($reviews);
            $this->applyExperiencePenalties($payload);
        } catch (Throwable $e) {
            Log::warning('ScorePillarsJob: experience penalty application failed', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
        }

        // ── Implicit: aggregate ──────────────────────────────────────
        AggregateAuditJob::dispatchSync($this->auditId);
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    /**
     * BB57: persist a PillarScore returned by KonsistensiScorer. Mirrors
     * ScorePillarJob's persistence shape so the dashboard/PDF read
     * paths don't need a code branch.
     */
    private function persistPillarScore(\App\DTO\PillarScore $score): void
    {
        DB::transaction(function () use ($score): void {
            $audit = BrandAudit::findOrFail($this->auditId);

            $pillarScores = (array) ($audit->pillar_scores ?? []);
            $pillarScores[$score->pillarSlug] = $score->toArray();

            $subBuckets = (array) ($audit->sub_bucket_scores ?? []);
            $subBuckets[$score->pillarSlug] = $score->subBucketScores;

            $scoreBreakdown = (array) ($audit->score_breakdown ?? []);
            $scoreBreakdown[$score->pillarSlug] = $score->scoreBreakdown;

            $audit->update([
                'pillar_scores'     => $pillarScores,
                'sub_bucket_scores' => $subBuckets,
                'score_breakdown'   => $scoreBreakdown,
            ]);
        });
    }

    /** @return array<string,mixed>|null */
    private function fetchWebsite(string $url): ?array
    {
        if ($url === '') {
            return null;
        }
        try {
            return (new WebsiteFetcher())->fetch($url);
        } catch (Throwable $e) {
            Log::warning('ScorePillarsJob: WebsiteFetcher failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Normalize EvidenceMapper::fullReviews() output into the
     * {text, rating} shape RecallScorer expects.
     *
     * @param  list<array<string,mixed>> $reviews
     * @return list<array{text: string, rating: float}>
     */
    private function normalizeReviewsForScoring(array $reviews): array
    {
        $out = [];
        foreach ($reviews as $review) {
            $text = (string) ($review['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $out[] = [
                'text'   => $text,
                'rating' => (float) ($review['rating_value'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Native {author, rating_value, text} shape for the penalty
     * detector (its evidence captures depend on author + rating).
     *
     * @param  list<array<string,mixed>> $reviews
     * @return list<array{author?: string, rating_value?: int, text?: string}>
     */
    private function reviewsForPenaltyDetector(array $reviews): array
    {
        $out = [];
        foreach ($reviews as $review) {
            $out[] = [
                'author'       => (string) ($review['author'] ?? ''),
                'rating_value' => (int) ($review['rating_value'] ?? 0),
                'text'         => (string) ($review['text'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * BB54 provenance trail: write a data_source list into each
     * pillar's score_breakdown so operators can trace which evidence
     * keys the score relied on. ScorePillarJob has already persisted
     * the pillar's score row; this is an additive enrichment.
     */
    private function stampDataSourceForPillar(string $slug, EvidenceMapper $mapper, BrandAudit $audit): void
    {
        $sources = match ($slug) {
            ScoringRubric::PILLAR_RECALL => array_filter([
                'places_api',
                $mapper->fullReviews($audit) !== [] ? 'gmaps_scrape' : null,
            ]),
            ScoringRubric::PILLAR_DIGITAL => ['touchpoint_presence'],
            ScoringRubric::PILLAR_KONSISTENSI => array_filter([
                'touchpoint_urls',
                ! empty($audit->touchpoints['outlet_photo_paths'] ?? []) ? 'outlet_photo_paths' : null,
                $mapper->instagramRaw($audit)['screenshot_path'] !== null ? 'instagram_screenshot' : null,
            ]),
            ScoringRubric::PILLAR_EXPERIENCE => array_filter([
                'places_api',
                $mapper->fullReviews($audit) !== [] ? 'gmaps_scrape' : null,
                'website_fetch',
            ]),
            default => [],
        };

        DB::transaction(function () use ($slug, $sources): void {
            $audit = BrandAudit::findOrFail($this->auditId);
            $breakdown = (array) ($audit->score_breakdown ?? []);
            $pillar    = (array) ($breakdown[$slug] ?? []);
            $pillar['data_source'] = array_values($sources);
            $breakdown[$slug] = $pillar;
            $audit->update(['score_breakdown' => $breakdown]);
        });
    }

    /**
     * Subtract penalty deltas from the Experience pillar's stored
     * score (clamped to >= 0) and stash the full payload under
     * score_breakdown[experience][penalties]. Atomic against the same
     * row ScorePillarJob just wrote.
     *
     * @param array<string,mixed> $payload  ExperiencePenaltyDetector::detect() output
     */
    private function applyExperiencePenalties(array $payload): void
    {
        $totalPenalty = (int) ($payload['total_penalty'] ?? 0);

        DB::transaction(function () use ($payload, $totalPenalty): void {
            $audit = BrandAudit::findOrFail($this->auditId);

            $pillarScores = (array) ($audit->pillar_scores ?? []);
            $experienceData = (array) ($pillarScores[ScoringRubric::PILLAR_EXPERIENCE] ?? []);

            // Skip when the experience pillar errored out (no score to
            // adjust) — penalties stay recorded in score_breakdown so
            // the dashboard can still surface them.
            if (! isset($experienceData['error']) && isset($experienceData['score'])) {
                $original = (int) $experienceData['score'];
                $adjusted = max(0, $original + $totalPenalty);
                $experienceData['score']                 = $adjusted;
                $experienceData['score_pre_penalty']     = $original;
                $experienceData['penalty_total_applied'] = $totalPenalty;
                $pillarScores[ScoringRubric::PILLAR_EXPERIENCE] = $experienceData;
            }

            $scoreBreakdown = (array) ($audit->score_breakdown ?? []);
            $expBreakdown   = (array) ($scoreBreakdown[ScoringRubric::PILLAR_EXPERIENCE] ?? []);
            $expBreakdown['penalties'] = [
                'total'                 => $totalPenalty,
                'per_type'              => (array) ($payload['penalties'] ?? []),
                'evidence'              => (array) ($payload['evidence'] ?? []),
                'reviews_scanned'       => (int) ($payload['reviews_scanned'] ?? 0),
                'reviews_skipped_short' => (int) ($payload['reviews_skipped_short'] ?? 0),
                'source'                => 'gmaps_scrape',
            ];
            $scoreBreakdown[ScoringRubric::PILLAR_EXPERIENCE] = $expBreakdown;

            $audit->update([
                'pillar_scores'   => $pillarScores,
                'score_breakdown' => $scoreBreakdown,
            ]);
        });
    }
}
