<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\Fetchers\GoogleMapsReviewsFetcher;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use App\Services\InstagramProfileAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeBrand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Timeout accommodates Phase 7-B Instagram profile audit phase:
     * worker scrape 25-45s + Claude analysis 15-25s + existing pillar
     * scoring overhead. Total observed audit time should land 60-90s
     * for IG-enabled audits; we set 180s headroom to absorb tail-latency
     * on Anthropic API or worker browser launch without spurious kills.
     */
    public int $timeout = 180;

    public function __construct(public readonly string $auditId) {}

    public function handle(InstagramProfileAuditService $instagramAudit): void
    {
        $audit = BrandAudit::findOrFail($this->auditId);
        $audit->update(['status' => BrandAudit::STATUS_ANALYZING]);

        $touchpoints = (array) $audit->touchpoints;
        $brandName   = (string) $audit->brand_name;
        $serviceType = (string) $audit->service_type;
        $gmapsUrl    = (string) ($touchpoints['gmaps_url'] ?? '');
        $instagramUrl = (string) ($touchpoints['instagram_url'] ?? '');
        $websiteUrl  = (string) ($touchpoints['website_url'] ?? '');
        $tiktokUrl   = (string) ($touchpoints['tiktok_url'] ?? '');
        $waActive    = (bool) ($touchpoints['whatsapp_business_active'] ?? false);
        $photoPaths  = (array) ($touchpoints['outlet_photo_paths'] ?? []);

        // ── 1. Fetch external data ────────────────────────────────────────────
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

        // ── 2. Build per-pillar input payloads ────────────────────────────────
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

        // ── 3. Batch 4 pillar jobs → AggregateAuditJob ───────────────────────
        $auditId = $this->auditId;

        $jobs = array_map(
            static fn (string $slug, array $inputs): ScorePillarJob => new ScorePillarJob($auditId, $slug, $inputs),
            array_keys($pillarInputs),
            array_values($pillarInputs),
        );

        Bus::batch($jobs)
            ->name("audit:{$auditId}")
            ->allowFailures()
            ->finally(static function () use ($auditId): void {
                AggregateAuditJob::dispatch($auditId);
            })
            ->dispatch();

        // Phase 7-B: IG profile audit runs SYNCHRONOUSLY in this job.
        // Pillar batch above is async (other workers pick it up); the IG
        // audit is single-credential / single-worker-call work that holds
        // this job for ~45-75s. Never throws — every failure mode is
        // persisted as instagram_audit_status on the row by the service.
        // v1 design; async parallelization is a 7-B.1 optimization.
        try {
            $instagramAudit->audit($audit);
        } catch (Throwable $e) {
            // Defence in depth — service contracts swallow all errors, but
            // if something slips through we never want the pillar batch's
            // AggregateAuditJob to be blocked by an IG exception.
            Log::error('AnalyzeBrand: IG audit threw despite service guarantee', [
                'audit_id' => $this->auditId,
                'class'    => $e::class,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    // ── Private fetchers ──────────────────────────────────────────────────────

    /** @return array{rating:float,review_count:int,owner_response_rate:float,keyword_hits:array<string,mixed>,recent_reviews:list<mixed>} */
    private function fetchReviews(string $gmapsUrl, string $brandName): array
    {
        $fallback = [
            'rating'          => 0.0,
            'review_count'    => 0,
            'keyword_hits'    => ['positive' => [], 'negative' => []],
            'sampled_reviews' => [],
        ];

        $googleMapsKey = (string) config('services.google.maps_api_key', '');

        if ($googleMapsKey === '' || $gmapsUrl === '') {
            return $fallback;
        }

        try {
            return (new GoogleMapsReviewsFetcher($googleMapsKey))->fetch($gmapsUrl, $brandName) ?? $fallback;
        } catch (\Throwable $e) {
            Log::warning('AnalyzeBrand: GMaps fetch failed', ['error' => $e->getMessage()]);

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
        } catch (\Throwable $e) {
            Log::warning('AnalyzeBrand: WebsiteFetcher failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
