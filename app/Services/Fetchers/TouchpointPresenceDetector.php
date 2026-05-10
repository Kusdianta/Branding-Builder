<?php

declare(strict_types=1);

namespace App\Services\Fetchers;

use Illuminate\Support\Facades\Validator;

/**
 * Pure input transformer — no API calls.
 * Converts raw wizard touchpoint fields into the boolean presence shape
 * expected by DigitalPresenceScorer.
 */
final class TouchpointPresenceDetector
{
    /**
     * @param array{
     *     instagram_url?: string|null,
     *     website_url?: string|null,
     *     gmaps_url?: string|null,
     *     whatsapp_business_active?: bool,
     *     tiktok_url?: string|null,
     *     review_count?: int,
     * } $inputs
     *
     * @return array{
     *     has_instagram: bool,
     *     has_website: bool,
     *     has_gmaps: bool,
     *     has_wa_business: bool,
     *     has_tiktok: bool,
     *     review_count: int,
     * }
     */
    public function detect(array $inputs): array
    {
        return [
            'has_instagram'  => $this->isValidUrl($inputs['instagram_url'] ?? null),
            'has_website'    => $this->isValidUrl($inputs['website_url'] ?? null),
            'has_gmaps'      => $this->isValidUrl($inputs['gmaps_url'] ?? null),
            'has_wa_business' => (bool) ($inputs['whatsapp_business_active'] ?? false),
            'has_tiktok'     => $this->isValidUrl($inputs['tiktok_url'] ?? null),
            'review_count'   => (int) ($inputs['review_count'] ?? 0),
        ];
    }

    /** URL is present when non-empty and passes Laravel's url validation rule. */
    private function isValidUrl(string|null $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        $validator = Validator::make(['url' => $url], ['url' => 'url']);

        return ! $validator->fails();
    }
}
