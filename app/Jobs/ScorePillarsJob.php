<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\Fetchers\GoogleMapsReviewsFetcher;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
 * Updates audit_steps rows pre-created by AnalyzeBrand so the loading
 * view (BB21) shows real-time progress for each pillar.
 */
class ScorePillarsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public readonly string $auditId) {}

    public function handle(): void
    {
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

        // ── Step 1: fetch external data ──────────────────────────────
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

        // ── Step 2: score each pillar synchronously ──────────────────
        $pillarInputs = [
            ScoringRubric::PILLAR_RECALL => [
                'brand_name'      => $brandName,
                'rating'          => $reviewData['rating'],
                'review_count'    => $reviewData['review_count'],
                'keyword_hits'    => $reviewData['keyword_hits'],
                'sampled_reviews' => $reviewData['sampled_reviews'],
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
}
