<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use RuntimeException;

/**
 * Thin HTTP client over the Hub's internal credentials API.
 *
 * Two endpoints are consumed:
 *
 * - GET  /api/internal/credentials/{platform}/next
 *     The Hub orders healthy credentials by last_used_at (NULLS first) and
 *     atomically bumps last_used_at on claim. Concurrent callers rotate
 *     naturally; our retry-once path therefore gets a different row on
 *     second call without any per-id exclude logic.
 *
 * - POST /api/internal/credentials/{id}/status
 *     Report state changes back to Hub: requires_2fa on login_wall_hit,
 *     healthy after a clean run, etc. Same auth as the GET.
 *
 * Auth: Bearer token from config('services.hub.inbound_api_key'). 10s timeout
 * by default; Hub is local LAN so no transient-failure retries.
 */
final class HubCredentialsClient
{
    private ClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly float $timeoutSeconds = 10.0,
        ?ClientInterface $http = null,
    ) {
        $this->http = $http ?? new Client([
            'base_uri'        => rtrim($baseUrl, '/') . '/',
            'timeout'         => $timeoutSeconds,
            'connect_timeout' => min(5.0, $timeoutSeconds),
            'http_errors'     => false,
        ]);
    }

    /**
     * Claim the next healthy credential for $platform.
     *
     * Returns the decoded payload on 200, null on 404 (no healthy credentials).
     * Throws RuntimeException for 5xx / transport / parse errors — caller is
     * expected to log + surface as 'audit_failed' status.
     *
     * Returned shape (matches WorkerCredentialController::next):
     * { id: ULID, platform: string, username: string,
     *   password: ?string, session_cookies: mixed }
     * session_cookies may be Cookie-Editor array OR legacy instagrapi dict —
     * the worker's _cookies_to_playwright accepts both, so forward as-is.
     *
     * @return array<string,mixed>|null
     */
    public function getNextCredential(string $platform): ?array
    {
        $resp = $this->call('GET', "api/internal/credentials/{$platform}/next");

        if ($resp['status'] === 404) {
            return null;
        }

        if ($resp['status'] !== 200) {
            throw new RuntimeException(sprintf(
                'Hub returned HTTP %d for /credentials/%s/next: %s',
                $resp['status'],
                $platform,
                $this->safeJsonForMessage($resp['body']),
            ));
        }

        return $resp['body'];
    }

    /**
     * Report a credential state change back to Hub.
     *
     * $status must be one of the Hub-side STATUSES enum:
     * unknown | healthy | rate_limited | requires_2fa | banned | disabled
     * Hub validates and 422s on anything else — that surfaces here as
     * RuntimeException.
     */
    public function reportCredentialStatus(
        string $credentialId,
        string $status,
        ?string $failureReason = null,
    ): void {
        $body = ['status' => $status];
        if ($failureReason !== null && $failureReason !== '') {
            $body['last_failure_reason'] = $failureReason;
        }

        $resp = $this->call('POST', "api/internal/credentials/{$credentialId}/status", $body);

        if ($resp['status'] >= 400) {
            throw new RuntimeException(sprintf(
                'Hub returned HTTP %d when updating credential %s: %s',
                $resp['status'],
                $credentialId,
                $this->safeJsonForMessage($resp['body']),
            ));
        }
    }

    /**
     * @param  array<string,mixed>|null  $jsonBody
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(string $method, string $uri, ?array $jsonBody = null): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept'        => 'application/json',
            ],
        ];
        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        try {
            $response = $this->http->request($method, $uri, $options);
        } catch (ConnectException $e) {
            throw new RuntimeException(
                sprintf('Hub unreachable at %s: %s', $this->baseUrl, $e->getMessage()),
                0,
                $e,
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                sprintf('Hub transport error: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $raw = (string) $response->getBody();

        if ($raw === '') {
            $body = [];
        } else {
            try {
                $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(
                    sprintf('Hub returned invalid JSON: %s', $raw),
                    0,
                    $e,
                );
            }
            if (! is_array($decoded)) {
                throw new RuntimeException(sprintf('Hub returned non-object JSON: %s', $raw));
            }
            $body = $decoded;
        }

        return [
            'status' => $response->getStatusCode(),
            'body'   => $body,
        ];
    }

    /** @param array<string,mixed> $body */
    private function safeJsonForMessage(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '(unencodable body)';
        }
    }
}
