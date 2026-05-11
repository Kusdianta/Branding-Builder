<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;
use Illuminate\Support\Facades\Log;
use Nema\WorkerClient\DTO\InstagramProfileAudit;
use Nema\WorkerClient\Exceptions\ProfileAuditException;
use Nema\WorkerClient\Exceptions\WorkerAuthException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use Throwable;

/**
 * Phase 7-B orchestrator: fetch a healthy IG credential from Hub, call the
 * worker for a comprehensive profile audit, hand the result to ClaudeService
 * for apikprimadya-style analysis, persist the payload + status on the
 * BrandAudit row.
 *
 * NEVER throws back to AnalyzeBrand — every failure mode lands on the
 * BrandAudit row as a status enum value + (when applicable) an `error`-shaped
 * instagram_audit payload. Audit pipeline continues with other pillars
 * regardless of what happens here.
 *
 * Status enum (mirror the schema spec):
 *
 *  - pending                      → initial migration default; never written here
 *  - done                         → success, instagram_audit populated
 *  - no_instagram_url_provided    → wizard didn't capture one or it was unparseable
 *  - no_credentials_available     → Hub has no healthy IG credential
 *  - credentials_stale            → login_wall_hit on every attempt; operator
 *                                   action required (Bootstrap Sesi / paste new
 *                                   Cookie-Editor export)
 *  - rate_limited                 → worker rate-limited this username
 *  - profile_not_found            → IG handle 404
 *  - audit_failed                 → catch-all; detail in instagram_audit.error
 */
final class InstagramProfileAuditService
{
    /**
     * Hardcoded by spec — 1 fallback credential attempt on login_wall_hit.
     * Higher values risk burning multiple credentials on a single bad
     * request. The Hub claims-by-last_used_at ordering means the second
     * fetch naturally returns a different row when more than one healthy
     * credential exists; we still defensively de-dup by id.
     */
    private const MAX_CREDENTIAL_ATTEMPTS = 2;

    public function __construct(
        private readonly NemaWorkerClient $worker,
        private readonly HubCredentialsClient $hub,
        private readonly ClaudeService $claude,
        private readonly IgUsernameExtractor $extractor,
    ) {}

    public function audit(BrandAudit $audit): void
    {
        $touchpoints  = (array) $audit->touchpoints;
        $instagramUrl = (string) ($touchpoints['instagram_url'] ?? '');

        $username = $this->extractor->extract($instagramUrl);
        if ($username === null) {
            $this->persistStatus($audit, 'no_instagram_url_provided');
            return;
        }

        $usedCredentialIds = [];

        for ($attempt = 1; $attempt <= self::MAX_CREDENTIAL_ATTEMPTS; $attempt++) {
            $credential = $this->fetchCredential($audit);

            if ($credential === null) {
                // First-call null = no healthy credentials at all.
                // Second-call null = the previous (now-failed) credential was the only one.
                $status = $attempt === 1 ? 'no_credentials_available' : 'credentials_stale';
                $this->persistStatus($audit, $status);
                return;
            }

            $credentialId = (string) ($credential['id'] ?? '');
            if ($credentialId === '' || in_array($credentialId, $usedCredentialIds, true)) {
                // Hub returned the same id twice (single-credential pool) —
                // no fallback path available.
                $this->persistStatus($audit, 'credentials_stale');
                return;
            }
            $usedCredentialIds[] = $credentialId;

            $sessionCookies = $credential['session_cookies'] ?? null;
            if (! is_array($sessionCookies) || $sessionCookies === []) {
                Log::warning('InstagramProfileAuditService: credential has empty session_cookies', [
                    'audit_id'      => $audit->id,
                    'credential_id' => $credentialId,
                ]);
                // Treat as stale — same operator action required.
                $this->reportCredentialStale($credentialId, 'empty session_cookies in credential record');
                if ($attempt < self::MAX_CREDENTIAL_ATTEMPTS) {
                    continue;
                }
                $this->persistStatus($audit, 'credentials_stale');
                return;
            }

            try {
                $result = $this->worker->auditInstagramProfile($username, $sessionCookies);
            } catch (ProfileAuditException $e) {
                $resolution = $this->handleProfileAuditException(
                    $audit,
                    $e,
                    $credentialId,
                    isLastAttempt: $attempt >= self::MAX_CREDENTIAL_ATTEMPTS,
                );
                if ($resolution === 'retry') {
                    continue;
                }
                return; // 'done-handling' = status already persisted
            } catch (WorkerAuthException $e) {
                Log::error('InstagramProfileAuditService: worker auth rejected', [
                    'audit_id' => $audit->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, 'worker_auth_failed: ' . $e->getMessage());
                return;
            } catch (WorkerNotAvailableException $e) {
                Log::warning('InstagramProfileAuditService: worker unreachable', [
                    'audit_id' => $audit->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, 'worker_unavailable: ' . $e->getMessage());
                return;
            } catch (Throwable $e) {
                Log::warning('InstagramProfileAuditService: unexpected worker error', [
                    'audit_id' => $audit->id,
                    'class'    => $e::class,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, $e->getMessage());
                return;
            }

            $this->analyzeAndPersist($audit, $result);
            return;
        }

        // Fall-through guard. Reaching here means MAX_CREDENTIAL_ATTEMPTS
        // exhausted without resolution — treat as stale.
        $this->persistStatus($audit, 'credentials_stale');
    }

    /**
     * Decide what to do with a ProfileAuditException. Returns:
     *  - 'retry'         caller should `continue` the credential loop
     *  - 'done-handling' caller should `return` (status already persisted)
     */
    private function handleProfileAuditException(
        BrandAudit $audit,
        ProfileAuditException $e,
        string $credentialId,
        bool $isLastAttempt,
    ): string {
        Log::info('InstagramProfileAuditService: ProfileAuditException', [
            'audit_id'      => $audit->id,
            'credential_id' => $credentialId,
            'error_code'    => $e->errorCode,
            'http_status'   => $e->httpStatus,
            'detail'        => $e->detail,
        ]);

        switch ($e->errorCode) {
            case 'login_wall_hit':
                // Mark this credential stale, then try ONCE more if budget allows.
                $this->reportCredentialStale(
                    $credentialId,
                    'login_wall_hit during Phase 7-B profile audit',
                );
                if (! $isLastAttempt) {
                    return 'retry';
                }
                $this->persistStatus($audit, 'credentials_stale');
                return 'done-handling';

            case 'rate_limited':
                $this->persistStatus($audit, 'rate_limited');
                return 'done-handling';

            case 'profile_not_found':
                $this->persistStatus($audit, 'profile_not_found');
                return 'done-handling';

            default:
                // timeout, audit_failed, missing_session_cookies (should never
                // hit here — caller validates), unknown — all funnel to
                // audit_failed with detail.
                $detail = $e->detail !== null && $e->detail !== ''
                    ? $e->errorCode . ': ' . $e->detail
                    : $e->errorCode;
                $this->persistFailure($audit, $detail);
                return 'done-handling';
        }
    }

    private function analyzeAndPersist(BrandAudit $audit, InstagramProfileAudit $result): void
    {
        try {
            $analysis = $this->claude->analyzeInstagramProfile(
                $result,
                (string) $audit->brand_name,
                (string) $audit->service_type,
                $audit->city !== null && $audit->city !== '' ? (string) $audit->city : null,
            );
            $audit->update([
                'instagram_audit_status' => 'done',
                'instagram_audit'        => $analysis,
            ]);
        } catch (Throwable $e) {
            Log::error('InstagramProfileAuditService: Claude analysis failed', [
                'audit_id' => $audit->id,
                'class'    => $e::class,
                'error'    => $e->getMessage(),
            ]);
            $this->persistFailure($audit, 'claude_analysis_failed: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed>|null */
    private function fetchCredential(BrandAudit $audit): ?array
    {
        try {
            return $this->hub->getNextCredential('instagram');
        } catch (Throwable $e) {
            Log::warning('InstagramProfileAuditService: Hub credentials fetch failed', [
                'audit_id' => $audit->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function reportCredentialStale(string $credentialId, string $reason): void
    {
        try {
            $this->hub->reportCredentialStatus($credentialId, 'requires_2fa', $reason);
        } catch (Throwable $e) {
            Log::warning('InstagramProfileAuditService: failed to report credential status', [
                'credential_id' => $credentialId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function persistStatus(BrandAudit $audit, string $status): void
    {
        $audit->update([
            'instagram_audit_status' => $status,
            'instagram_audit'        => null,
        ]);
    }

    private function persistFailure(BrandAudit $audit, string $detail): void
    {
        $audit->update([
            'instagram_audit_status' => 'audit_failed',
            'instagram_audit'        => ['error' => $detail],
        ]);
    }
}
