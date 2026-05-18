<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

/**
 * BB100 — return shape of InstagramHandleChecker. Readonly DTO; values
 * are nullable when the source HTML didn't expose them or when the
 * check itself failed (network / rate limit).
 *
 * status meanings:
 *   - "found"     : Instagram returned a profile page that parses cleanly
 *   - "not_found" : 404 / Instagram's "Page Not Found" sentinel page
 *   - "error"     : transport failure, rate limit, or unparseable response
 */
final readonly class InstagramHandleResult
{
    public function __construct(
        public string $username,
        public string $status,
        public bool $exists,
        public ?string $displayName,
        public ?string $profilePicUrl,
        public ?int $followerCount,
        public ?bool $isBusiness,
        public ?string $checkedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'username'        => $this->username,
            'status'          => $this->status,
            'exists'          => $this->exists,
            'display_name'    => $this->displayName,
            'profile_pic_url' => $this->profilePicUrl,
            'follower_count'  => $this->followerCount,
            'is_business'     => $this->isBusiness,
            'checked_at'      => $this->checkedAt,
        ];
    }

    public static function notFound(string $username): self
    {
        return new self(
            username:      $username,
            status:        'not_found',
            exists:        false,
            displayName:   null,
            profilePicUrl: null,
            followerCount: null,
            isBusiness:    null,
            checkedAt:     now()->toIso8601String(),
        );
    }

    public static function error(string $username): self
    {
        return new self(
            username:      $username,
            status:        'error',
            exists:        false,
            displayName:   null,
            profilePicUrl: null,
            followerCount: null,
            isBusiness:    null,
            checkedAt:     now()->toIso8601String(),
        );
    }

    /**
     * BB107.1 — reconstruct from the toArray() shape. The cache layer
     * stores the array form (not the serialized object) so the value
     * survives autoload-classmap drift between Herd PHP-FPM workers,
     * OPcache staleness, and class signature changes. Symmetric with
     * toArray(); same key names.
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            username:      (string) ($data['username'] ?? ''),
            status:        (string) ($data['status'] ?? 'error'),
            exists:        (bool) ($data['exists'] ?? false),
            displayName:   isset($data['display_name'])    ? (string) $data['display_name']    : null,
            profilePicUrl: isset($data['profile_pic_url']) ? (string) $data['profile_pic_url'] : null,
            followerCount: isset($data['follower_count'])  ? (int) $data['follower_count']     : null,
            isBusiness:    isset($data['is_business'])     ? (bool) $data['is_business']       : null,
            checkedAt:     isset($data['checked_at'])      ? (string) $data['checked_at']      : null,
        );
    }
}
