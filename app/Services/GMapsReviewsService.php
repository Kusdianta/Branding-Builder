<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;
use Illuminate\Support\Facades\Log;
use Nema\WorkerClient\Exceptions\GMapsReviewsException;
use Nema\WorkerClient\Exceptions\WorkerAuthException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use Throwable;

/**
 * Phase 8 BB24 orchestrator: fetch a healthy gmaps credential from
 * Hub, call the worker for a Reviews-tab scrape, persist the payload +
 * status on the BrandAudit row.
 *
 * Mirrors InstagramProfileAuditService in spirit (status enum,
 * credential rotation on transient failure, never throws back to the
 * caller). Differences from the IG service:
 *   - No LLM analysis pass — the scrape result feeds RecallScorer +
 *     ExperiencePenaltyDetector deterministically (BB25 + BB26).
 *   - Soft-failure path on missing GMaps URL (the audit form treats
 *     gmaps_url as optional — same posture as instagram_url).
 *   - Cookies normalisation shim is shared with the IG service via
 *     the same encrypted:json double-encode workaround (W7.1 item 6).
 *
 * Status enum:
 *   pending                   — initial default
 *   done                      — success
 *   no_gmaps_url_provided     — wizard didn't capture one
 *   no_credentials_available  — Hub has no healthy gmaps credential
 *   credentials_stale         — Google sign-in on every attempt
 *   rate_limited              — worker bucket cooldown
 *   place_not_found           — h1.DUwDvf absent
 *   captcha_blocked           — CAPTCHA / consent interstitial
 *   scrape_failed             — catch-all; detail in gmaps_reviews.error
 */
final class GMapsReviewsService
{
    /**
     * Hardcoded by spec — 1 fallback credential attempt on
     * credentials_stale. Higher values risk burning multiple
     * credentials on a single bad session.
     */
    private const MAX_CREDENTIAL_ATTEMPTS = 2;

    public function __construct(
        private readonly NemaWorkerClient $worker,
        private readonly HubCredentialsClient $hub,
    ) {}

    public function fetch(BrandAudit $audit): void
    {
        $touchpoints = (array) $audit->touchpoints;
        $gmapsUrl    = trim((string) ($touchpoints['gmaps_url'] ?? ''));

        if ($gmapsUrl === '') {
            $this->persistStatus($audit, 'no_gmaps_url_provided');
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_CREDENTIAL_ATTEMPTS; $attempt++) {
            $credential = $this->fetchCredential($audit);

            if ($credential === null) {
                $status = $attempt === 1 ? 'no_credentials_available' : 'credentials_stale';
                $this->persistStatus($audit, $status);
                return;
            }

            $credentialId  = (string) ($credential['id'] ?? '');
            // W7.5: dropped normalizeSessionCookies shim. Audit on
            // 2026-05-15 confirmed all worker_credentials.session_cookies
            // rows in Hub are stored as JSON arrays (not double-encoded
            // JSON strings); the W7.1 item 6 shim is no longer needed.
            $sessionCookies = $credential['session_cookies'] ?? null;
            if (! is_array($sessionCookies) || $sessionCookies === []) {
                Log::warning('GMapsReviewsService: credential has empty session_cookies', [
                    'audit_id'      => $audit->id,
                    'credential_id' => $credentialId,
                ]);
                $this->reportCredentialStale($credentialId, 'empty session_cookies in credential record');
                if ($attempt < self::MAX_CREDENTIAL_ATTEMPTS) {
                    continue;
                }
                $this->persistStatus($audit, 'credentials_stale');
                return;
            }

            try {
                $result = $this->worker->scrapeGMapsReviews(
                    $gmapsUrl,
                    $sessionCookies,
                    'audit-' . $audit->id,
                );
            } catch (GMapsReviewsException $e) {
                $resolution = $this->handleScrapeException(
                    $audit,
                    $e,
                    $credentialId,
                    isLastAttempt: $attempt >= self::MAX_CREDENTIAL_ATTEMPTS,
                );
                if ($resolution === 'retry') {
                    continue;
                }
                return;
            } catch (WorkerAuthException $e) {
                Log::error('GMapsReviewsService: worker auth rejected', [
                    'audit_id' => $audit->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, 'worker_auth_failed: ' . $e->getMessage());
                return;
            } catch (WorkerNotAvailableException $e) {
                Log::warning('GMapsReviewsService: worker unreachable', [
                    'audit_id' => $audit->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, 'worker_unavailable: ' . $e->getMessage());
                return;
            } catch (Throwable $e) {
                Log::warning('GMapsReviewsService: unexpected worker error', [
                    'audit_id' => $audit->id,
                    'class'    => $e::class,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, $e->getMessage());
                return;
            }

            // Success — persist the structured payload.
            $audit->update([
                'gmaps_reviews_status' => 'done',
                'gmaps_reviews'        => [
                    'business_name'      => $result->businessName,
                    'rating'             => $result->rating,
                    'total_review_count' => $result->totalReviewCount,
                    'reviews'            => array_map(
                        static fn ($r) => [
                            'author'        => $r->author,
                            'rating_label'  => $r->ratingLabel,
                            'rating_value'  => $r->ratingValue,
                            'date_relative' => $r->dateRelative,
                            'text'          => $r->text,
                        ],
                        $result->reviews,
                    ),
                    'scraped_at'   => $result->scrapedAt->format('c'),
                    'duration_ms'  => $result->durationMs,
                    'limitations'  => $result->limitations,
                    '_meta'        => [
                        'credential_id' => $credentialId,
                        'sample_source' => 'gmaps_scrape',
                    ],
                ],
            ]);
            return;
        }

        // Fall-through: should never reach here.
        $this->persistStatus($audit, 'credentials_stale');
    }

    /**
     * Decide what to do with a GMapsReviewsException. Returns:
     *  - 'retry'         caller should `continue` the credential loop
     *  - 'done-handling' caller should `return` (status already persisted)
     */
    private function handleScrapeException(
        BrandAudit $audit,
        GMapsReviewsException $e,
        string $credentialId,
        bool $isLastAttempt,
    ): string {
        $code = $e->errorCode;

        // credentials_stale + captcha_blocked are credential-rotation
        // candidates — try one more credential before giving up.
        if (in_array($code, ['credentials_stale', 'captcha_blocked'], true)) {
            $this->reportCredentialStale($credentialId, $code . ': ' . ($e->detail ?? ''));
            if (! $isLastAttempt) {
                return 'retry';
            }
            $this->persistStatus($audit, $code);
            return 'done-handling';
        }

        // Terminal codes — operator must intervene at the form / URL
        // level, not by rotating credentials.
        if (in_array($code, ['place_not_found', 'invalid_gmaps_url', 'rate_limited'], true)) {
            $persistCode = $code === 'invalid_gmaps_url' ? 'scrape_failed' : $code;
            $this->persistStatus($audit, $persistCode);
            return 'done-handling';
        }

        // timeout + scrape_failed + anything else — surface as
        // scrape_failed with the worker's detail.
        $this->persistFailure(
            $audit,
            sprintf('worker_error[%s]: %s', $code, $e->detail ?? '(no detail)'),
        );
        return 'done-handling';
    }

    /** @return array<string,mixed>|null */
    private function fetchCredential(BrandAudit $audit): ?array
    {
        try {
            return $this->hub->getNextCredential('gmaps');
        } catch (Throwable $e) {
            Log::warning('GMapsReviewsService: Hub credentials fetch failed', [
                'audit_id' => $audit->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function reportCredentialStale(string $credentialId, string $reason): void
    {
        if ($credentialId === '') {
            return;
        }
        try {
            $this->hub->reportCredentialStatus($credentialId, 'requires_2fa', $reason);
        } catch (Throwable $e) {
            Log::warning('GMapsReviewsService: failed to report credential status', [
                'credential_id' => $credentialId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function persistStatus(BrandAudit $audit, string $status): void
    {
        $audit->update([
            'gmaps_reviews_status' => $status,
            'gmaps_reviews'        => null,
        ]);
    }

    private function persistFailure(BrandAudit $audit, string $detail): void
    {
        $audit->update([
            'gmaps_reviews_status' => 'scrape_failed',
            'gmaps_reviews'        => ['error' => $detail],
        ]);
    }

    // W7.5: removed normalizeSessionCookies — see W7.1 item 6 closure
    // notes. Hub now consistently writes arrays; the defensive
    // string-decode pass is no longer needed.
}
