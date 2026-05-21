<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\EvidenceMapper;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use App\Services\ClaudeService;
use App\Services\Scoring\ExperiencePenaltyDetector;
use App\Services\Scoring\ExperienceScorer;
use App\Services\Scoring\InstagramActivityScorer;
use App\Services\Scoring\KonsistensiScorer;
use App\Services\Scoring\OwnerReplyRateScorer;
use App\Services\Scoring\PriceListDetector;
use App\Services\Scoring\WebsiteLivenessScorer;
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
        ExperienceScorer $experienceScorer,
        InstagramActivityScorer $instagramActivityScorer,
        WebsiteLivenessScorer $websiteLivenessScorer,
        OwnerReplyRateScorer $ownerReplyRateScorer,
        PriceListDetector $priceListDetector,
        ClaudeService $claude,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // BB66: tag all Claude calls from this score phase (Konsistensi
        // vision + per-pillar LLM scoring) with the audit id so the Hub
        // api_usage_log dashboard can produce per-audit cost rollups.
        $claude->setAuditContext($this->auditId);

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
        // BB130 — keep the RAW GMaps rows (owner_reply + author +
        // rating_value intact) for owner-reply scoring. normalizeReviewsForScoring
        // reshapes to {text, rating} for the keyword/sentiment corpus and
        // would otherwise strip owner_reply before manajemen_ulasan runs,
        // forcing reply rate to 0% even when the scrape captured replies.
        $rawReviews  = $evidenceMapper->fullReviews($audit);
        $fullReviews = $this->normalizeReviewsForScoring($rawReviews);
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

        $operatorDecls = $audit->operator_declarations;

        // BB117 — v3 audits get an enriched input bundle that pushes new
        // signals (IG activity, website liveness, owner reply rate,
        // price-list detection) into the per-pillar $inputs. Legacy
        // v1/v2 audits keep their existing bundle shape; the scorers
        // branch internally on _wizard_version to route to the right
        // path.
        if ($audit->wizard_version === BrandAudit::WIZARD_V3) {
            $evidence = $this->ensurePriceListDetection($audit, $evidence, $priceListDetector);

            [$pillarInputs, $v3Context] = $this->enrichInputsForV3(
                $audit,
                $pillarInputs,
                $evidence,
                $touchpoints,
                $placesApi,
                $fullReviews,
                $rawReviews,
                $instagramActivityScorer,
                $websiteLivenessScorer,
                $ownerReplyRateScorer,
            );
        } else {
            $v3Context = [];
        }

        foreach ($pillarInputs as $slug => $inputs) {
            $step = $this->step($pillarStepMap[$slug]);
            $step?->markRunning();
            try {
                if ($slug === ScoringRubric::PILLAR_KONSISTENSI) {
                    // BB57+BB58: route Konsistensi through the dedicated
                    // scorer so the vision multimodal call (or BB58
                    // fallback) takes effect. BB117: merge v3 context
                    // so the scorer can route to scoreV3() and read
                    // variety_count + touchpoints flags.
                    $context = array_merge($inputs, $v3Context);
                    $score = $konsistensiScorer->scoreFromEvidence($evidence, $context);
                    $this->persistPillarScore($score);
                } elseif ($slug === ScoringRubric::PILLAR_EXPERIENCE) {
                    // BB75 + BB117: tier classifier (v1/v2) OR PPT-rubric
                    // bonus model (v3). Penalties still apply after
                    // persistence via applyExperiencePenalties() below.
                    $context = array_merge($inputs, $v3Context);
                    $score = $experienceScorer->scoreFromEvidence(
                        $evidence,
                        is_array($operatorDecls) ? $operatorDecls : null,
                        $context,
                    );
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
        // BB117 — v3 scorers write their own per-pillar data_source into
        // score_breakdown via their breakdown payloads. The legacy
        // stamper below would clobber that with a coarser hardcoded
        // list; skip it for v3 so the scorer's attribution wins.
        if ($audit->wizard_version === BrandAudit::WIZARD_V3) {
            return;
        }

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
                // BB75: ExperienceScorer reads service_signals + operator_declarations.
                'analysis.service_signals',
                $audit->operator_declarations !== null ? 'operator_declarations' : null,
                $mapper->fullReviews($audit) !== [] ? 'gmaps_scrape' : null,
                'places_api',
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
    /**
     * BB117 — enrich per-pillar input bundles for v3 audits. Adds
     * _wizard_version to every bundle and pushes the new signals into
     * the buckets that need them.
     *
     * Returns [$pillarInputs, $v3Context] where $v3Context carries
     * cross-pillar context (variety_count, touchpoints_operational,
     * owner_reply_rate) for KonsistensiScorer + ExperienceScorer.
     *
     * @param array<string,mixed> $pillarInputs
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $touchpoints
     * @param array<string,mixed> $placesApi
     * @param list<array{text: string, rating: float}> $fullReviews  normalized corpus (keyword/sentiment)
     * @param list<array<string,mixed>> $rawReviews  raw rows with owner_reply (owner-reply scoring)
     * @return array{0: array<string,array<string,mixed>>, 1: array<string,mixed>}
     */
    private function enrichInputsForV3(
        BrandAudit $audit,
        array $pillarInputs,
        array $evidence,
        array $touchpoints,
        array $placesApi,
        array $fullReviews,
        array $rawReviews,
        InstagramActivityScorer $igScorer,
        WebsiteLivenessScorer $webScorer,
        OwnerReplyRateScorer $replyScorer,
    ): array {
        $touchpointsOp  = (array) ($touchpoints['operational']  ?? []);
        $serviceTypes   = (array) ($touchpoints['service_types']?? []);
        $varietyCount   = (int)   ($serviceTypes['variety_count'] ?? max(1, count((array) ($serviceTypes['secondary'] ?? [])) + 1));
        $hasSopDeclared = (bool)  ($touchpointsOp['complaint_sop'] ?? false);

        // IG activity — read from raw_payload persisted by
        // InstagramProfileAuditService::buildScrapeSnapshot.
        $igData = $this->resolveInstagramSnapshot($audit);
        $igScore = $igScorer->score($igData);

        // Website liveness — single 5s HEAD/GET against the wizard URL.
        $webResult = $webScorer->check(is_string($touchpoints['website_url'] ?? null) ? $touchpoints['website_url'] : null);

        // Owner reply rate — deterministic on already-scraped reviews.
        // BB130 — read from the RAW rows (owner_reply intact), NOT the
        // {text, rating}-normalized $fullReviews which drops owner_reply.
        $reviewArray = $this->reviewsForOwnerReply($rawReviews);
        $ownerReply = $replyScorer->score($reviewArray, $hasSopDeclared);
        $replyRate  = (float) (($ownerReply['evidence']['reply_rate_pct'] ?? 0.0) / 100.0);

        // Recall bundle: add owner reply rate + SOP signal + tag version.
        $pillarInputs[ScoringRubric::PILLAR_RECALL]['_wizard_version']            = BrandAudit::WIZARD_V3;
        $pillarInputs[ScoringRubric::PILLAR_RECALL]['owner_reply_rate']           = $replyRate;
        $pillarInputs[ScoringRubric::PILLAR_RECALL]['has_sop_declared']           = $hasSopDeclared;
        $pillarInputs[ScoringRubric::PILLAR_RECALL]['manajemen_ulasan_evidence'] = (array) ($ownerReply['evidence'] ?? []);

        // Digital bundle: add IG activity result + website liveness +
        // tiktok status (already on $audit) + tag version.
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['_wizard_version']                       = BrandAudit::WIZARD_V3;
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['instagram_activity_score']              = $igScore['score'];
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['instagram_activity_evidence']           = (array) ($igScore['evidence'] ?? []);
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['instagram_activity_source']             = (string) ($igScore['source'] ?? '');
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['instagram_activity_unavailable_reason'] = $igScore['unavailable_reason'] ?? null;
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['website_is_live']                       = (bool) $webResult['is_live'];
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['website_evidence']                      = (array) ($webResult['evidence'] ?? []);
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['website_unavailable_reason']            = $webResult['unavailable_reason'] ?? null;
        // Phase 12c.4 FIX D — TikTok verification flag flows through
        // touchpoints (no DB column exists for tiktok_check_status).
        // The wizard's oembed checker writes touchpoints.tiktok_verified
        // when the handle resolves; ScorePillarsJob maps that boolean
        // back to the 'found' string the V3 scorer expects. Legacy
        // ``tiktok_check_status`` accessor is honored when present.
        $tiktokVerified = (bool) ($touchpoints['tiktok_verified'] ?? false);
        $legacyTtStatus = (string) ($audit->tiktok_check_status ?? '');
        $pillarInputs[ScoringRubric::PILLAR_DIGITAL]['tiktok_check_status'] = $tiktokVerified
            ? 'found'
            : ($legacyTtStatus !== '' ? $legacyTtStatus : 'not_checked');

        // Konsistensi bundle: tag version. Variety + price_list flow via $v3Context.
        $pillarInputs[ScoringRubric::PILLAR_KONSISTENSI]['_wizard_version'] = BrandAudit::WIZARD_V3;
        $pillarInputs[ScoringRubric::PILLAR_KONSISTENSI]['variety_count']    = $varietyCount;

        // Experience bundle: tag version. Operational flags + variety
        // + reply rate flow via $v3Context.
        $pillarInputs[ScoringRubric::PILLAR_EXPERIENCE]['_wizard_version']         = BrandAudit::WIZARD_V3;
        $pillarInputs[ScoringRubric::PILLAR_EXPERIENCE]['touchpoints_operational'] = $touchpointsOp;
        $pillarInputs[ScoringRubric::PILLAR_EXPERIENCE]['variety_count']           = $varietyCount;
        $pillarInputs[ScoringRubric::PILLAR_EXPERIENCE]['owner_reply_rate']        = $replyRate;

        $v3Context = [
            '_wizard_version'         => BrandAudit::WIZARD_V3,
            'variety_count'           => $varietyCount,
            'touchpoints_operational' => $touchpointsOp,
            'owner_reply_rate'        => $replyRate,
        ];

        return [$pillarInputs, $v3Context];
    }

    /**
     * Resolve the Instagram audit snapshot for InstagramActivityScorer.
     * Reads raw_payload from $audit->instagram_audit (set by
     * InstagramProfileAuditService::buildScrapeSnapshot). Falls back to
     * the legacy column shape when raw_payload is absent.
     *
     * @return array<string,mixed>
     */
    private function resolveInstagramSnapshot(BrandAudit $audit): array
    {
        $payload = $audit->instagram_audit;
        if (! is_array($payload)) {
            return [];
        }
        if (isset($payload['raw_payload']) && is_array($payload['raw_payload'])) {
            // raw_payload carries recent_posts + has_active_story at top
            // level — InstagramActivityScorer reads both keys.
            $raw = $payload['raw_payload'];
            $raw['_meta'] = $payload['_meta'] ?? [];
            return $raw;
        }
        return $payload;
    }

    /**
     * Reshape full review rows into the {owner_reply, author, rating_value,
     * text} shape OwnerReplyRateScorer expects. EvidenceMapper passes the
     * raw rows through; this just casts the fields.
     *
     * @param list<array<string,mixed>> $reviews
     * @return list<array<string,mixed>>
     */
    private function reviewsForOwnerReply(array $reviews): array
    {
        $out = [];
        foreach ($reviews as $r) {
            $out[] = [
                'author'       => (string) ($r['author'] ?? ''),
                'rating_value' => (int) ($r['rating_value'] ?? 0),
                'text'         => (string) ($r['text'] ?? ''),
                'owner_reply'  => is_array($r['owner_reply'] ?? null) ? $r['owner_reply'] : null,
            ];
        }
        return $out;
    }

    /**
     * Run PriceListDetector if audit_evidence.price_list_detection is
     * missing/stale. Persists the result back to audit_evidence so
     * subsequent re-runs (and the dashboard) read the same payload.
     *
     * Inputs: Places API photo URLs + Instagram captions (best-effort).
     * Detector handles graceful failure internally — returns a
     * detected=false + unavailable_reason payload on full failure.
     *
     * @param array<string,mixed> $evidence
     * @return array<string,mixed>  updated evidence (always populated)
     */
    private function ensurePriceListDetection(BrandAudit $audit, array $evidence, PriceListDetector $detector): array
    {
        if (isset($evidence['price_list_detection']) && is_array($evidence['price_list_detection'])) {
            return $evidence;
        }

        $photoUrls = [];
        foreach ((array) ($evidence['places_api']['photos'] ?? []) as $p) {
            $url = is_string($p) ? $p : (string) ($p['url'] ?? ($p['path'] ?? ''));
            if ($url !== '' && str_starts_with($url, 'http')) {
                $photoUrls[] = $url;
            }
        }

        $captions = [];
        $igPosts = (array) ($audit->instagram_audit['raw_payload']['recent_posts'] ?? []);
        foreach ($igPosts as $p) {
            $caption = $p['caption'] ?? null;
            if (is_string($caption) && trim($caption) !== '') {
                $captions[] = $caption;
            }
        }

        $detection = $detector->detect($photoUrls, $captions);

        $evidence['price_list_detection'] = $detection;
        DB::transaction(function () use ($audit, $detection): void {
            $fresh = BrandAudit::findOrFail($audit->id);
            $current = (array) ($fresh->audit_evidence ?? []);
            $current['price_list_detection'] = $detection;
            $fresh->update(['audit_evidence' => $current]);
        });

        return $evidence;
    }

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
