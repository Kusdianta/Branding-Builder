<?php

declare(strict_types=1);

namespace App\Services\HandleCheckers;

/**
 * BB101 — return shape of TikTokHandleChecker. Mirrors
 * InstagramHandleResult but drops the IG-specific is_business hint
 * (TikTok's public-facing meta tags don't expose an analogue).
 *
 * status meanings:
 *   - "found"     : TikTok returned a profile page parseable via og:* meta
 *   - "not_found" : 404 or "Couldn't find this account" sentinel
 *   - "error"     : transport failure, rate limit, or unparseable response
 */
final readonly class TikTokHandleResult
{
    public function __construct(
        public string $username,
        public string $status,
        public bool $exists,
        public ?string $displayName,
        public ?string $profilePicUrl,
        public ?int $followerCount,
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
            'checked_at'      => $this->checkedAt,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            username:      (string) ($data['username'] ?? ''),
            status:        (string) ($data['status'] ?? 'error'),
            exists:        (bool)   ($data['exists'] ?? false),
            displayName:   isset($data['display_name'])    ? (string) $data['display_name']    : null,
            profilePicUrl: isset($data['profile_pic_url']) ? (string) $data['profile_pic_url'] : null,
            followerCount: isset($data['follower_count'])  ? (int)    $data['follower_count']  : null,
            checkedAt:     isset($data['checked_at'])      ? (string) $data['checked_at']      : null,
        );
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
            checkedAt:     now()->toIso8601String(),
        );
    }
}
