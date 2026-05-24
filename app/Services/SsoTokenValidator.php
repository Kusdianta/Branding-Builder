<?php

declare(strict_types=1);

namespace App\Services;

/**
 * BB02 — validate-only counterpart to the Hub's SsoTokenService. Verifies
 * the HMAC signature against SSO_SHARED_SECRET and checks expiry, returning
 * the decoded payload or null.
 *
 * Token format (issued by the Hub):
 *     base64url(JSON payload) "." hex(hmac_sha256(base64url_payload, secret))
 *
 * NOTE: this class is duplicated near-verbatim in laundry-calculator. Flagged
 * for extraction into a shared nema/sso-client package (see V02) — kept
 * inline for v1 to avoid a premature package.
 */
class SsoTokenValidator
{
    public function __construct(private readonly string $sharedSecret)
    {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('sso.shared_secret', ''));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function validate(string $token): ?array
    {
        if ($this->sharedSecret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$encodedPayload, $signature] = $parts;

        $expected = hash_hmac('sha256', $encodedPayload, $this->sharedSecret);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = base64_decode(strtr($encodedPayload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['expires_at'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }
}
