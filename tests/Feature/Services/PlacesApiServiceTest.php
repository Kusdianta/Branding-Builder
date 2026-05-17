<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\PlacesApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BB97 — Unit-ish tests for the wizard's manual fallback path.
 * Exercises URL parsing and the Places API request shape under
 * Http::fake() so the contract is stable even when the real Places
 * API isn't reachable from CI.
 */
class PlacesApiServiceTest extends TestCase
{
    public function test_returns_null_when_api_key_missing(): void
    {
        $service = new PlacesApiService(apiKey: '');
        $this->assertNull($service->resolveManualUrl('https://www.google.com/maps/place/Test/'));
    }

    public function test_returns_null_for_empty_url(): void
    {
        $service = new PlacesApiService(apiKey: 'test-key');
        $this->assertNull($service->resolveManualUrl(''));
        $this->assertNull($service->resolveManualUrl('   '));
    }

    public function test_text_search_then_details_resolves_full_maps_url(): void
    {
        Http::fake([
            'maps.googleapis.com/maps/api/place/textsearch/*' => Http::response([
                'status'  => 'OK',
                'results' => [
                    ['place_id' => 'ChIJfromTextSearch', 'name' => 'Found Laundry'],
                ],
            ], 200),
            'maps.googleapis.com/maps/api/place/details/*' => Http::response([
                'status' => 'OK',
                'result' => [
                    'place_id'                   => 'ChIJfromTextSearch',
                    'name'                       => 'Found Laundry',
                    'formatted_address'          => 'Jl. Found 42',
                    'geometry'                   => ['location' => ['lat' => -7.5, 'lng' => 110.5]],
                    'website'                    => 'https://found.example.id',
                    'international_phone_number' => '+62 123 4567',
                    'types'                      => ['laundry'],
                    'address_components'         => [],
                ],
            ], 200),
        ]);

        $service = new PlacesApiService(apiKey: 'test-key');
        $resolved = $service->resolveManualUrl(
            'https://www.google.com/maps/place/Found+Laundry/@-7.5,110.5,17z/'
        );

        $this->assertIsArray($resolved);
        $this->assertSame('ChIJfromTextSearch', $resolved['place_id']);
        $this->assertSame('Found Laundry', $resolved['name']);
        $this->assertSame('Jl. Found 42', $resolved['formatted_address']);
    }

    public function test_text_search_no_results_returns_null(): void
    {
        Http::fake([
            'maps.googleapis.com/maps/api/place/textsearch/*' => Http::response([
                'status'  => 'ZERO_RESULTS',
                'results' => [],
            ], 200),
        ]);

        $service = new PlacesApiService(apiKey: 'test-key');
        $resolved = $service->resolveManualUrl(
            'https://www.google.com/maps/place/Nonexistent/'
        );

        $this->assertNull($resolved);
    }

    public function test_fetch_place_details_normalizes_legacy_fields(): void
    {
        Http::fake([
            'maps.googleapis.com/maps/api/place/details/*' => Http::response([
                'status' => 'OK',
                'result' => [
                    'place_id'           => 'ChIJabc',
                    'name'               => 'Test',
                    'formatted_address'  => 'Jl. Test 1',
                    'geometry'           => ['location' => ['lat' => 0, 'lng' => 0]],
                    'types'              => ['laundry'],
                ],
            ], 200),
        ]);

        $service = new PlacesApiService(apiKey: 'test-key');
        $details = $service->fetchPlaceDetails('ChIJabc');

        $this->assertIsArray($details);
        $this->assertSame('ChIJabc', $details['place_id']);
        $this->assertSame(['laundry'], $details['types']);
        // Always-present null keys for unset fields so the wizard
        // doesn't have to defensive-check each one.
        $this->assertArrayHasKey('website', $details);
        $this->assertArrayHasKey('international_phone_number', $details);
    }
}
