<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\Fetchers\GoogleMapsReviewsFetcher;
use App\Services\HubUsageLogger;
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
 * BB52 — Phase 10 gather sub-job 1 of 3.
 *
 * Hits the Google Places API for rating / review_count / address /
 * keyword hits / sampled_reviews, then writes the payload under
 * audit_evidence.places_api. Marks the 'gather_places' audit_step row.
 *
 * Never throws back to the batch. Missing API key or empty gmaps_url
 * lands as audit_evidence.places_api = null and a 'skipped' step
 * detail; the scorers handle that as a graceful no-data case.
 */
class FetchPlacesApiJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(HubUsageLogger $usageLogger): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('gather_places');
        $step?->markRunning();

        $audit       = BrandAudit::findOrFail($this->auditId);
        $touchpoints = (array) $audit->touchpoints;
        $gmapsUrl    = trim((string) ($touchpoints['gmaps_url'] ?? ''));
        $brandName   = (string) $audit->brand_name;
        $apiKey      = (string) config('services.google.maps_api_key', '');

        if ($gmapsUrl === '' || $apiKey === '') {
            $this->writeEvidenceSlice(null);
            $step?->markDone([
                'skipped' => true,
                'reason'  => $gmapsUrl === '' ? 'no_gmaps_url' : 'no_api_key',
            ]);
            return;
        }

        try {
            // BB66: thread the HubUsageLogger + audit id into the fetcher so
            // each chargeable Places API call (text-search + place-details)
            // posts a row to the Hub api_usage_log endpoint.
            $payload = (new GoogleMapsReviewsFetcher($apiKey, $usageLogger, $this->auditId))
                ->fetch($gmapsUrl, $brandName);
            $this->writeEvidenceSlice($payload);
            $step?->markDone([
                'review_count' => (int) ($payload['review_count'] ?? 0),
                'rating'       => (float) ($payload['rating'] ?? 0.0),
            ]);
        } catch (Throwable $e) {
            Log::warning('FetchPlacesApiJob: Places API call failed', [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);
            $this->writeEvidenceSlice(null);
            $step?->markFailed($e->getMessage());
        }
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    /**
     * Atomic key-update on audit_evidence: read current JSON, set
     * places_api slice, write back. SQLite-safe (no JSON_SET reliance)
     * and tolerant of pre-Phase-10 rows with null audit_evidence.
     */
    private function writeEvidenceSlice(?array $payload): void
    {
        DB::transaction(function () use ($payload): void {
            $audit = BrandAudit::findOrFail($this->auditId);
            $evidence = (array) ($audit->audit_evidence ?? []);
            $evidence['places_api'] = $payload;
            $audit->update(['audit_evidence' => $evidence]);
        });
    }
}
