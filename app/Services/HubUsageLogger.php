<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB66 — fire-and-forget client for Hub's POST /api/internal/usage-logs.
 *
 * Spoke-side counterpart to the BB65 Hub controller. Same hub.inbound
 * bearer auth as HubCredentialsClient.
 *
 * Contract: never throws. Hub down → local fallback log + audit
 * pipeline continues. Network slow → 2s timeout cap.
 *
 * Two write methods:
 *   logClaude()  — captures Anthropic SDK response.usage
 *   logGoogle()  — captures Places API request count + SKU
 *
 * Both take an optional $auditId so per-audit cost rollups are possible.
 *
 * BB126 — when $auditId is supplied, resolves the audit's owner to
 * user_id and includes it in the POST body so the Hub can build the
 * per-user spend rollup without ever querying the spoke DB. The lookup
 * is request-scoped cached so a multi-call job (e.g. Score → Validate
 * → GenerateInsights all sharing one audit) only hits BrandAudit once.
 */
class HubUsageLogger
{
    /** Tight timeout — usage logging must never block the audit. */
    private const TIMEOUT_SECONDS = 2.0;

    /** Identifies this spoke in the Hub's spoke column. */
    private const SPOKE = 'branding-builder';

    /**
     * BB126 — request-scoped audit_id → user_id cache. Same job hits the
     * same audit dozens of times; a single BrandAudit lookup amortises.
     *
     * Static lifetime is fine because Laravel job workers boot a fresh
     * process per job batch and the cache is cleared between requests
     * in tests via {@see resetUserIdCache()}.
     *
     * @var array<string,?string>
     */
    private static array $userIdCache = [];

    private ClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri'        => rtrim($baseUrl, '/') . '/',
            'timeout'         => self::TIMEOUT_SECONDS,
            'connect_timeout' => 1.0,
            'http_errors'     => false,
        ]);
    }

    /**
     * Log one Anthropic Claude call.
     *
     * @param array<string,mixed>|null $metadata Free-form context (stop_reason, duration_ms, etc.)
     */
    public function logClaude(
        string $model,
        string $operation,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $cacheCreationInputTokens = null,
        ?int $cacheReadInputTokens = null,
        ?string $auditId = null,
        ?array $metadata = null,
    ): void {
        $this->post([
            'service'                     => 'anthropic_claude',
            'model'                       => $model,
            'operation'                   => $operation,
            'spoke'                       => self::SPOKE,
            'audit_id'                    => $auditId,
            'input_tokens'                => $inputTokens,
            'output_tokens'               => $outputTokens,
            'cache_creation_input_tokens' => $cacheCreationInputTokens,
            'cache_read_input_tokens'     => $cacheReadInputTokens,
            'metadata'                    => $metadata,
        ]);
    }

    /**
     * Log one Google Places API call.
     *
     * @param string $sku 'place-details-essentials' | 'place-details-pro' | 'place-photo' | 'text-search-pro' | 'nearby-search-pro'
     * @param array<string,mixed>|null $metadata
     */
    public function logGoogle(
        string $sku,
        string $operation,
        int $requestCount = 1,
        ?string $auditId = null,
        ?array $metadata = null,
    ): void {
        $this->post([
            'service'       => 'google_places',
            'model'         => $sku,
            'operation'     => $operation,
            'spoke'         => self::SPOKE,
            'audit_id'      => $auditId,
            'request_count' => $requestCount,
            'metadata'      => $metadata,
        ]);
    }

    /**
     * @param array<string,mixed> $body
     */
    private function post(array $body): void
    {
        if ($this->apiKey === '') {
            // Vault not configured for usage logging — silent skip (some
            // dev environments don't run Hub locally).
            return;
        }

        // BB126 — backfill user_id from audit_id so the Hub doesn't have
        // to query branding-builder DB. Only kicks in when caller didn't
        // already supply user_id explicitly.
        if (! array_key_exists('user_id', $body) || $body['user_id'] === null) {
            $auditId = $body['audit_id'] ?? null;
            $body['user_id'] = is_string($auditId) && $auditId !== ''
                ? $this->resolveUserId($auditId)
                : null;
        }

        try {
            $this->http->request('POST', 'api/internal/usage-logs', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept'        => 'application/json',
                ],
                'json' => $body,
            ]);
        } catch (Throwable $e) {
            // Fire-and-forget — Hub down must not break the audit.
            Log::warning('HubUsageLogger: usage write failed (continuing)', [
                'service'   => $body['service'] ?? null,
                'operation' => $body['operation'] ?? null,
                'audit_id'  => $body['audit_id'] ?? null,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * BB126 — resolve audit owner. Cached for the request lifetime so a
     * many-call job amortises to one BrandAudit SELECT. DB failures
     * (e.g. SQLite locked) fall through as null — usage logging must
     * never break the audit pipeline.
     */
    private function resolveUserId(string $auditId): ?string
    {
        if (array_key_exists($auditId, self::$userIdCache)) {
            return self::$userIdCache[$auditId];
        }

        try {
            /** @var ?string $userId */
            $userId = BrandAudit::query()->whereKey($auditId)->value('user_id');
        } catch (Throwable $e) {
            $userId = null;
        }

        return self::$userIdCache[$auditId] = $userId;
    }

    /**
     * Test seam: reset the request-scoped audit→user cache.
     */
    public static function resetUserIdCache(): void
    {
        self::$userIdCache = [];
    }
}
