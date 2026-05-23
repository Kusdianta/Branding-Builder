<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesAuditEvidence;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Nema\WorkerClient\Exceptions\WebsiteScrapeException;
use Nema\WorkerClient\Exceptions\WorkerAuthException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use Throwable;

/**
 * BB78 — Phase 11 gather sub-job 4 (joins BB52's parallel batch in BB71).
 *
 * Calls the worker /v1/website/scrape endpoint (BB77) and persists the
 * result under audit_evidence.website. Skipped cleanly when the wizard
 * left the website URL empty.
 *
 * Never throws back to the batch. Every failure mode lands as either
 * audit_evidence.website = null + a 'skipped' or 'failed' step detail,
 * or a partial-error payload with error key set — downstream BB74
 * ExtractServiceSignalsJob is responsible for degrading to other
 * evidence sources (IG bio, GMaps reviews, operator declarations).
 *
 * Lifecycle:
 *   no URL provided  → evidence.website = null + step.detail.skipped=true
 *   worker error     → evidence.website = {error: <code>, detail: <str>}
 *   transport error  → evidence.website = null + step.markFailed
 *   success          → evidence.website = WebsiteScrapeResult::toArray()
 */
class FetchWebsiteJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesAuditEvidence;

    /** Worker hard cap is 120s; this guards against transport stalls. */
    public int $timeout = 90;

    public function __construct(public readonly string $auditId) {}

    public function handle(NemaWorkerClient $worker): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('fetch_website');
        $step?->markRunning();

        $audit       = BrandAudit::findOrFail($this->auditId);
        $touchpoints = (array) $audit->touchpoints;
        $websiteUrl  = trim((string) ($touchpoints['website_url'] ?? ''));

        if ($websiteUrl === '') {
            $this->writeEvidenceSlice(null);
            $step?->markDone(['skipped' => true, 'reason' => 'no_website_url']);
            return;
        }

        try {
            $result = $worker->scrapeWebsite(
                websiteUrl: $websiteUrl,
                auditId: $this->auditId,
                timeoutMs: 30000,
            );
            $this->writeEvidenceSlice($result->toArray());
            $step?->markDone([
                'has_pricing_keywords'   => $result->hasPricingKeywords,
                'has_pickup_keywords'    => $result->hasPickupKeywords,
                'has_express_keywords'   => $result->hasExpressKeywords,
                'has_complaint_policy_keywords' => $result->hasComplaintPolicyKeywords,
                'duration_ms'            => $result->durationMs,
            ]);
        } catch (WebsiteScrapeException $e) {
            // Structured worker error (invalid URL / unreachable / timeout
            // / scrape_failed). Persist the error code so the
            // ExtractServiceSignalsJob (BB74) downstream can see WHY
            // there's no website data without re-running the scrape.
            Log::info('FetchWebsiteJob: worker returned structured error', [
                'audit_id'   => $this->auditId,
                'error_code' => $e->errorCode,
                'detail'     => $e->detail,
            ]);
            $this->writeEvidenceSlice([
                'error'  => $e->errorCode,
                'detail' => $e->detail,
            ]);
            $step?->markDone([
                'error_code' => $e->errorCode,
                'detail'     => $e->detail,
            ]);
        } catch (WorkerAuthException $e) {
            // SDK misconfig — different operator action (rotate API key,
            // not the URL). Mark the step failed so it stands out from a
            // routine unreachable site.
            Log::error('FetchWebsiteJob: worker auth rejected', [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);
            $this->writeEvidenceSlice(null);
            $step?->markFailed('worker_auth_failed: ' . $e->getMessage());
        } catch (WorkerNotAvailableException $e) {
            Log::warning('FetchWebsiteJob: worker unreachable', [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);
            $this->writeEvidenceSlice(null);
            $step?->markFailed('worker_unavailable: ' . $e->getMessage());
        } catch (Throwable $e) {
            Log::warning('FetchWebsiteJob: unexpected error', [
                'audit_id' => $this->auditId,
                'class'    => $e::class,
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
     * BB139 — concurrency-safe write of the website slice via the shared
     * WritesAuditEvidence trait (single atomic json_set UPDATE).
     *
     * @param array<string,mixed>|null $payload
     */
    private function writeEvidenceSlice(?array $payload): void
    {
        $this->writeEvidenceKey('website', $payload);
    }
}
