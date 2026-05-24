<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

use App\Services\HubCredentialsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Nema\WorkerClient\Exceptions\ProfileAuditException;
use Nema\WorkerClient\NemaWorkerClient;
use Throwable;

/**
 * BB135 — worker-first TikTok username availability check.
 *
 * Mirrors {@see InstagramHandleChecker}: TikTok's unauthenticated HTTP
 * endpoints are defended (oembed exposes no follower count; api/user/detail
 * returns an empty body to the spoke server). The reliable path is an
 * authenticated Playwright session through the worker, exactly like IG.
 *
 * Resolution order:
 *   1. Claim a healthy TikTok session from the Hub (HubCredentialsClient).
 *   2. Call the worker's /v1/tiktok/profile-audit
 *      (NemaWorkerClient::auditTikTokProfile) with those cookies — reads the
 *      profile from inside the authenticated browser, returning the real
 *      follower count + display name.
 *   3. Fall back to {@see TikTokHandleCheckerLegacy} (oembed) ONLY when no
 *      healthy credential exists or the worker errors. The legacy path
 *      resolves found/not_found but rarely a follower count.
 *
 * Caching: 1 hour per username, ONLY for definitive results (found /
 * not_found). 'error' is never cached so a transient failure doesn't lock
 * the operator out of re-checking for an hour.
 */
final class TikTokHandleChecker
{
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly NemaWorkerClient $worker,
        private readonly HubCredentialsClient $hub,
        private readonly TikTokHandleCheckerLegacy $legacy,
    ) {}

    public function check(string $username): TikTokHandleResult
    {
        $normalized = $this->normalize($username);
        if ($normalized === null) {
            return TikTokHandleResult::error($username);
        }

        $cacheKey = "tt-handle:{$normalized}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return TikTokHandleResult::fromArray($cached);
        }

        $result = $this->resolve($normalized);

        if ($result->status === 'found' || $result->status === 'not_found') {
            Cache::put($cacheKey, $result->toArray(), self::CACHE_TTL_SECONDS);
        }

        return $result;
    }

    private function normalize(string $raw): ?string
    {
        $clean = ltrim(trim($raw), '@');
        if ($clean === '' || ! preg_match('/^[A-Za-z0-9._]{2,24}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    /** Worker-first resolution with the legacy oembed probe as fallback. */
    private function resolve(string $username): TikTokHandleResult
    {
        $viaWorker = $this->checkViaWorker($username);
        if ($viaWorker !== null) {
            return $viaWorker;
        }

        return $this->legacy->check($username);
    }

    /**
     * Resolve via the worker using a Hub-claimed TikTok session. Returns null
     * (→ caller falls back to the legacy probe) when no healthy credential is
     * available or the worker can't give a definitive answer. Never throws.
     */
    private function checkViaWorker(string $username): ?TikTokHandleResult
    {
        try {
            $credential = $this->hub->getNextCredential('tiktok');
        } catch (Throwable $e) {
            Log::info('TikTokHandleChecker: Hub credential fetch failed; legacy fallback', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (! is_array($credential)) {
            return null; // no healthy credential — fall back
        }

        $cookies = $credential['session_cookies'] ?? null;
        if (! is_array($cookies) || $cookies === []) {
            return null;
        }

        $credentialId = isset($credential['id']) && is_string($credential['id'])
            ? $credential['id']
            : null;

        try {
            $audit = $this->worker->auditTikTokProfile($username, $cookies);
        } catch (ProfileAuditException $e) {
            // BB142 — a login wall / interstitial means the claimed session's
            // cookies are stale: TikTok's edge rejected the authenticated
            // navigation (net::ERR_HTTP_RESPONSE_CODE_FAILURE, classified as
            // login_wall_hit by the worker). Mark the Hub credential
            // requires_2fa (fires the admin "Cookies TikTok kedaluwarsa"
            // banner) and surface 'error' — NOT the legacy oembed probe, which
            // would only mask the operator-actionable cause with a
            // follower-less guess. Mirrors InstagramHandleChecker (BB137).
            if (in_array($e->errorCode, ['login_wall_hit', 'interstitial_blocked'], true)) {
                if ($credentialId !== null) {
                    $this->reportCredentialStale(
                        $credentialId,
                        $e->errorCode . ' during wizard handle check',
                    );
                }
                return TikTokHandleResult::error($username);
            }

            // Other codes (timeout / captcha_hit / rate_limited) are advisory —
            // fall back to the legacy oembed probe (best-effort name, no follower).
            Log::info('TikTokHandleChecker: worker audit failed; legacy fallback', [
                'username'   => $username,
                'error_code' => $e->errorCode,
            ]);
            return null;
        } catch (Throwable $e) {
            // Worker unreachable / unexpected — advisory legacy fallback.
            Log::info('TikTokHandleChecker: worker audit failed; legacy fallback', [
                'username' => $username,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }

        if ($audit->status === 'not_found') {
            return TikTokHandleResult::notFound($username);
        }

        return new TikTokHandleResult(
            username:      $username,
            status:        'found',
            exists:        true,
            displayName:   $audit->displayName,
            profilePicUrl: null,
            followerCount: $audit->followerCount,
            checkedAt:     now()->toIso8601String(),
        );
    }

    /**
     * BB142 — report a stale TikTok session to the Hub so the admin banner
     * warns the operator. Best-effort: a Hub hiccup must never break the
     * wizard check (which already degrades to 'error'). Mirrors
     * {@see InstagramHandleChecker::reportCredentialStale}.
     */
    private function reportCredentialStale(string $credentialId, string $reason): void
    {
        try {
            $this->hub->reportCredentialStatus($credentialId, 'requires_2fa', $reason);
        } catch (Throwable $e) {
            Log::warning('TikTokHandleChecker: failed to report credential stale', [
                'credential_id' => $credentialId,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
