<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\Fetchers\GoogleMapsReviewsFetcher;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use App\Services\GMapsReviewsService;
use App\Services\Scoring\ExperiencePenaltyDetector;
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
 * BB20 Track A: pillar scoring + aggregation.
 *
 * Runs the existing 4-pillar fetch + score + aggregate flow inline so
 * the outer Bus::batch in AnalyzeBrand can use ->then() reliably. Each
 * pillar runs synchronously (dispatchSync) inside this job — we lose
 * the previous intra-track parallelism but gain a single completion
 * boundary the outer batch can hang ->then() off.
 *
 * Phase 8 BB27: extended with two new units of work that bracket the
 * pillar loop:
 *
 *   1. fetch_gmaps_reviews (after fetch_gmaps): GMapsReviewsService
 *      claims a 'gmaps' worker_credential from Hub and runs the W8
 *      Playwright scrape. Output lands on $audit->gmaps_reviews and
 *      flows into the Recall pillar's `full_reviews` input.
 *
 *   2. apply_experience_penalties (after the pillar loop, before
 *      aggregate_pillars): ExperiencePenaltyDetector scans the same
 *      review corpus for keterlambatan / pakaian_hilang /
 *      no_response_wa patterns and subtracts deltas from the LLM-
 *      produced Experience pillar score. Penalty payload is folded
 *      into score_breakdown[experience][penalties] for PDF + dashboard
 *      surfacing (BB28 / BB29).
 *
 * Updates audit_steps rows pre-created by AnalyzeBrand so the loading
 * view (BB21) shows real-time progress for each step.
 */
class ScorePillarsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bumped from 120s — gmaps scrape can add 30-90s. */
    public int $timeout = 240;

    public function __construct(public readonly string $auditId) {}

    public function handle(
        GMapsReviewsService $gmapsService,
        ExperiencePenaltyDetector $penaltyDetector,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $audit = BrandAudit::findOrFail($this->auditId);
        $touchpoints  = (array) $audit->touchpoints;
        $brandName    = (string) $audit->brand_name;
        $serviceType  = (string) $audit->service_type;
        $gmapsUrl     = (string) ($touchpoints['gmaps_url'] ?? '');
        $instagramUrl = (string) ($touchpoints['instagram_url'] ?? '');
        $websiteUrl   = (string) ($touchpoints['website_url'] ?? '');
        $tiktokUrl    = (string) ($touchpoints['tiktok_url'] ?? '');
        $waActive     = (bool) ($touchpoints['whatsapp_business_active'] ?? false);
        $photoPaths   = (array) ($touchpoints['outlet_photo_paths'] ?? []);

        // ── Step 1: fetch Places API metadata ────────────────────────
        $fetchStep = $this->step('fetch_gmaps');
        $fetchStep?->markRunning();
        $reviewData = $this->fetchReviews($gmapsUrl, $brandName);
        $presence = (new TouchpointPresenceDetector())->detect([
            'instagram_url'            => $instagramUrl,
            'website_url'              => $websiteUrl,
            'gmaps_url'                => $gmapsUrl,
            'whatsapp_business_active' => $waActive,
            'tiktok_url'               => $tiktokUrl,
            'review_count'             => $reviewData['review_count'],
        ]);
        $websiteData = $this->fetchWebsite($websiteUrl);
        $fetchStep?->markDone(['review_count' => $reviewData['review_count'], 'rating' => $reviewData['rating']]);

        // ── Step 1.5 (BB27): gmaps full-corpus scrape via worker ──────
        $scrapeStep = $this->step('fetch_gmaps_reviews');
        $scrapeStep?->markRunning();
        try {
            $gmapsService->fetch($audit);
        } catch (Throwable $e) {
            // GMapsReviewsService is supposed to swallow all errors; this
            // catch is defensive in case something throws past it.
            Log::warning('ScorePillarsJob: gmaps scrape threw unexpectedly', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
        }
        $audit->refresh();
        $fullReviews = $this->extractFullReviewsForScoring($audit);
        $scrapeStep?->markDone([
            'status'        => (string) $audit->gmaps_reviews_status,
            'review_count'  => count($fullReviews),
        ]);

        // ── Step 2: score each pillar synchronously ──────────────────
        $pillarInputs = [
            ScoringRubric::PILLAR_RECALL => [
                'brand_name'      => $brandName,
                'rating'          => $reviewData['rating'],
                'review_count'    => $reviewData['review_count'],
                'keyword_hits'    => $reviewData['keyword_hits'],
                'sampled_reviews' => $reviewData['sampled_reviews'],
                // BB27: full_reviews drives the keyword + sentiment
                // sub-buckets when populated; falls back to
                // sampled_reviews otherwise (legacy + scrape failures).
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
                'keyword_hits'    => $reviewData['keyword_hits'],
            ],
        ];

        $pillarStepMap = [
            ScoringRubric::PILLAR_RECALL      => 'score_recall',
            ScoringRubric::PILLAR_DIGITAL     => 'score_digital',
            ScoringRubric::PILLAR_KONSISTENSI => 'score_konsistensi',
            ScoringRubric::PILLAR_EXPERIENCE  => 'score_experience',
        ];

        foreach ($pillarInputs as $slug => $inputs) {
            $step = $this->step($pillarStepMap[$slug]);
            $step?->markRunning();
            try {
                ScorePillarJob::dispatchSync($this->auditId, $slug, $inputs);
                $step?->markDone();
            } catch (Throwable $e) {
                Log::warning('ScorePillarsJob: pillar failed', [
                    'audit_id' => $this->auditId, 'pillar' => $slug, 'error' => $e->getMessage(),
                ]);
                $step?->markFailed($e->getMessage());
            }
        }

        // ── Step 2.5 (BB27): apply experience penalties from full_reviews ─
        $penaltyStep = $this->step('apply_experience_penalties');
        if ($penaltyStep !== null) {
            $penaltyStep->markRunning();
            try {
                $payload = $penaltyDetector->detect($this->experienceReviewsForPenaltyDetector($audit));
                $this->applyExperiencePenalties($payload);
                $penaltyStep->markDone([
                    'total_penalty'   => (int) $payload['total_penalty'],
                    'reviews_scanned' => (int) $payload['reviews_scanned'],
                ]);
            } catch (Throwable $e) {
                Log::warning('ScorePillarsJob: experience penalty application failed', [
                    'audit_id' => $this->auditId, 'error' => $e->getMessage(),
                ]);
                $penaltyStep->markFailed($e->getMessage());
            }
        }

        // ── Step 3: aggregate ────────────────────────────────────────
        $aggStep = $this->step('aggregate_pillars');
        $aggStep?->markRunning();
        try {
            AggregateAuditJob::dispatchSync($this->auditId);
            $aggStep?->markDone();
        } catch (Throwable $e) {
            $aggStep?->markFailed($e->getMessage());
            throw $e;
        }
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    /** @return array{rating:float,review_count:int,keyword_hits:array<string,mixed>,sampled_reviews:list<mixed>} */
    private function fetchReviews(string $gmapsUrl, string $brandName): array
    {
        $fallback = ['rating' => 0.0, 'review_count' => 0, 'keyword_hits' => ['positive' => [], 'negative' => []], 'sampled_reviews' => []];
        $key = (string) config('services.google.maps_api_key', '');
        if ($key === '' || $gmapsUrl === '') {
            return $fallback;
        }
        try {
            return (new GoogleMapsReviewsFetcher($key))->fetch($gmapsUrl, $brandName) ?? $fallback;
        } catch (Throwable $e) {
            Log::warning('ScorePillarsJob: GMaps fetch failed', ['error' => $e->getMessage()]);
            return $fallback;
        }
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
     * Pull the persisted gmaps_reviews payload into the {text, rating}
     * shape RecallScorer expects. Returns [] when the scrape produced
     * no reviews (no_credentials_available, scrape_failed, legacy
     * audits, etc.) so the scorer falls back to sampled_reviews.
     *
     * @return list<array{text: string, rating: float}>
     */
    private function extractFullReviewsForScoring(BrandAudit $audit): array
    {
        $payload = (array) ($audit->gmaps_reviews ?? []);
        $reviews = (array) ($payload['reviews'] ?? []);
        $out = [];
        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
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
     * Same source as RecallScorer (gmaps_reviews.reviews) but kept in
     * the scrape's native {author, rating_value, text} shape so the
     * penalty detector's evidence captures stay informative.
     *
     * @return list<array{author?: string, rating_value?: int, text?: string}>
     */
    private function experienceReviewsForPenaltyDetector(BrandAudit $audit): array
    {
        $payload = (array) ($audit->gmaps_reviews ?? []);
        $reviews = (array) ($payload['reviews'] ?? []);
        $out = [];
        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
            $out[] = [
                'author'       => (string) ($review['author'] ?? ''),
                'rating_value' => (int) ($review['rating_value'] ?? 0),
                'text'         => (string) ($review['text'] ?? ''),
            ];
        }
        return $out;
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
