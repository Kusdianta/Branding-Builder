<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use App\Models\ScoringRubric;
use App\Services\Fetchers\GoogleMapsReviewsFetcher;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class AnalyzeBrand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public readonly string $auditId) {}

    public function handle(): void
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
                'brand_name'          => $brandName,
                'rating'              => $reviewData['rating'],
                'review_count'        => $reviewData['review_count'],
                'owner_response_rate' => $reviewData['owner_response_rate'],
                'keyword_hits'        => $reviewData['keyword_hits'],
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
    }

    // ── Private fetchers ──────────────────────────────────────────────────────

    /** @return array{rating:float,review_count:int,owner_response_rate:float,keyword_hits:array<string,mixed>,recent_reviews:list<mixed>} */
    private function fetchReviews(string $gmapsUrl, string $brandName): array
    {
        $fallback = [
            'rating'              => 0.0,
            'review_count'        => 0,
            'owner_response_rate' => 0.0,
            'keyword_hits'        => ['positive' => [], 'negative' => []],
            'recent_reviews'      => [],
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
