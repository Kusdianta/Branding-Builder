<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parse any IG-shaped string (URL, @handle, bare username) to the canonical
 * 1-30 char username. Mirrors the worker's USERNAME_PATTERN regex so anything
 * this returns is guaranteed to clear the worker's own validator.
 *
 * Returns null when the input cannot be reduced to a valid handle — caller
 * must treat that as "no IG audit possible" and set
 * instagram_audit_status='no_instagram_url_provided'.
 */
final class IgUsernameExtractor
{
    // Same shape as the worker's app/services/instagram_profile_audit.py
    // USERNAME_PATTERN. Keep in sync.
    private const VALID_PATTERN = '/^[a-zA-Z0-9._]{1,30}$/';

    public function extract(string $input): ?string
    {
        $candidate = trim($input);
        if ($candidate === '') {
            return null;
        }

        // Drop scheme.
        $candidate = (string) preg_replace('#^https?://#i', '', $candidate);
        // Drop www. + canonical IG host.
        $candidate = (string) preg_replace('#^(www\.)?instagram\.com/#i', '', $candidate);
        // Drop leading @.
        $candidate = ltrim($candidate, '@');
        // Drop query string + fragment + trailing path.
        $candidate = explode('?', $candidate, 2)[0];
        $candidate = explode('#', $candidate, 2)[0];
        $candidate = trim($candidate, '/');
        $candidate = explode('/', $candidate, 2)[0];

        if ($candidate === '' || ! preg_match(self::VALID_PATTERN, $candidate)) {
            return null;
        }

        return $candidate;
    }
}
