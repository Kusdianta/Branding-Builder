<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Nema\WorkerClient\DTO\InstagramProfileAudit;
use Nema\WorkerClient\Exceptions\ProfileAuditException;
use Nema\WorkerClient\Exceptions\WorkerAuthException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use RuntimeException;
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
class InstagramProfileAuditService
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

            // W7.5: dropped normalizeSessionCookies shim. Audit on
            // 2026-05-15 confirmed all worker_credentials.session_cookies
            // rows in Hub are stored as JSON arrays; the W7.1 item 6
            // shim is no longer needed.
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

            case 'interstitial_blocked':
                // W7.1 item 7: Bloks login-chooser interstitial redirected
                // away from the profile URL even after the worker's
                // dismiss + re-navigate retry. Operator action is the same
                // as login_wall_hit (re-bootstrap credential), so we use
                // the same retry-once-with-different-credential pattern;
                // the diagnostic detail string is what carries the
                // distinction into Filament logs and operator dashboards.
                $this->reportCredentialStale(
                    $credentialId,
                    'interstitial_blocked: ' . ($e->detail ?? 'Bloks chooser redirect'),
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
        // BB13: persist the worker's visual assets (profile pic, screenshot,
        // top-6 post thumbnails) to disk + build the _meta sub-record that
        // gives Phase 7-C's PDF + dashboard renderers the structured profile
        // facts they need for the header card and the followers=0 degraded-
        // data trigger. The Claude analysis output covers the prose; _meta
        // covers the numeric + visual context.
        $meta = $this->persistInstagramAssets($audit, $result);

        try {
            $analysis = $this->claude->analyzeInstagramProfile(
                $result,
                (string) $audit->brand_name,
                (string) $audit->service_type,
                $audit->city !== null && $audit->city !== '' ? (string) $audit->city : null,
            );
            $analysis['_meta'] = $meta;
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

    /**
     * BB13: persist the worker payload's visual assets + return a structured
     * profile-metadata record for the PDF + dashboard renderers.
     *
     * Files land on the ``local`` disk (storage/app/private/) under
     * ``audits/{audit_id}/instagram/`` so they're private by default
     * (never web-served, only readable from server-side render paths).
     *
     * Schema-safe attachment: returns the record so the caller can attach
     * it under the ``_meta`` key of the persisted ``instagram_audit`` JSON.
     * Underscore-prefixed keys are forbidden in Claude's output by the
     * ANTI-POLA prompt callout (W7.1 item 8), so there's no collision
     * risk with the analysis structure.
     *
     * Defensive: each base64 decode is wrapped — a malformed payload
     * (RuntimeException from the DTO accessor) skips that single file
     * without breaking the rest of the persist. ``profile_pic_path``,
     * ``screenshot_path``, and the per-index ``post_thumbnail_paths``
     * entries stay null when their source was empty or invalid.
     *
     * @return array<string,mixed>
     */
    private function persistInstagramAssets(BrandAudit $audit, InstagramProfileAudit $result): array
    {
        $basePath = "audits/{$audit->id}/instagram";
        $disk     = Storage::disk('local');

        $meta = [
            'username'             => $result->username,
            'name'                 => $result->profile->name,
            'bio'                  => $result->profile->bio,
            'external_url'         => $result->profile->externalUrl,
            'followers'            => $result->profile->followers,
            'following'            => $result->profile->following,
            'posts_count'          => $result->profile->postsCount,
            'is_verified'          => $result->profile->isVerified,
            'is_business'          => $result->profile->isBusiness,
            'is_private'           => $result->isPrivate,
            'captured_at'          => $result->capturedAt->format(\DateTimeInterface::ATOM),
            'duration_ms'          => $result->durationMs,
            'profile_pic_path'     => null,
            'screenshot_path'      => null,
            'post_thumbnail_paths' => [],
            'highlight_names'      => array_map(
                static fn ($h) => $h->name,
                $result->highlights,
            ),
        ];

        // Profile pic — worker captures as JPEG (httpx fetch of the IG CDN
        // avatar URL, which IG serves as image/jpeg).
        $picBase64 = $result->profile->profilePicBase64;
        if ($picBase64 !== '') {
            $bytes = base64_decode($picBase64, true);
            if ($bytes !== false && $bytes !== '') {
                $path = "{$basePath}/profile_pic.jpg";
                $disk->put($path, $bytes);
                $meta['profile_pic_path'] = $path;
            }
        }

        // Grid screenshot — worker captures as PNG via page.screenshot.
        if ($result->screenshotBase64 !== '') {
            try {
                $bytes = $result->screenshotBytes();
                if ($bytes !== '') {
                    $path = "{$basePath}/screenshot.png";
                    $disk->put($path, $bytes);
                    $meta['screenshot_path'] = $path;
                }
            } catch (RuntimeException) {
                // Malformed base64 — diagnostic only, skip.
            }
        }

        // Top-6 post thumbnails — worker captures as PNG via element
        // bounding-box screenshot. Cap matches the audit's caption-fetch
        // limit (anti-ban discipline) so we never persist more thumbnails
        // than we have analysed.
        $thumbPaths = [];
        foreach (array_slice($result->recentPosts, 0, 6) as $idx => $post) {
            if ($post->thumbnailBase64 === '') {
                continue;
            }
            try {
                $bytes = $post->thumbnailBytes();
                if ($bytes === '') {
                    continue;
                }
                $path = "{$basePath}/posts/{$idx}.png";
                $disk->put($path, $bytes);
                $thumbPaths[$idx] = $path;
            } catch (RuntimeException) {
                // Malformed base64 on a single thumbnail — skip this one,
                // continue with the rest.
                continue;
            }
        }
        $meta['post_thumbnail_paths'] = $thumbPaths;

        return $meta;
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

    // W7.5: removed normalizeSessionCookies — see W7.1 item 6 closure
    // notes. Hub now consistently writes arrays; the defensive
    // string-decode pass is no longer needed.
}
