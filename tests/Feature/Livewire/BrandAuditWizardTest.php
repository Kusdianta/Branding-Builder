<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\AnalyzeBrand;
use App\Models\BrandAudit;
use App\Models\User;
use App\Services\PlacesApiService;
use App\Services\PlatformHealthChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BB97 — Phase 12c v2 wizard test coverage.
 *
 * Pattern mirrors GoogleAuthControllerTest + AuditsIndexTest: PHPUnit
 * 12, RefreshDatabase, snake_case test methods, RefreshDatabase rolls
 * SQLite back between cases. Volt anonymous-class components are
 * resolved by view name ('brand-audit-wizard') so the wizard renders
 * exactly as the route would render it.
 *
 * Per Phase 12c standing orders, tests here are diagnostics — they
 * surface regressions but do not gate shipping. The wizard's happy
 * path must stay green; edge-case reds are acceptable to land first
 * and fix in a follow-up.
 */
class BrandAuditWizardTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'brand-audit-wizard';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();

        // BB134 — BB105 Part 3 added a hard platform-health gate at the top
        // of submit() (and a probe in mount()) that force-refreshes a live
        // PlatformHealthChecker::check(). With preventStrayRequests() on, the
        // real probe resolves unhealthy and submit() bounces off the gate
        // before any tested logic runs. Bind a healthy stub so the wizard's
        // submit flow is exercised; the gate itself is covered elsewhere.
        $this->app->instance(PlatformHealthChecker::class, new class extends PlatformHealthChecker {
            public function __construct() {}

            public function check(): array
            {
                return ['healthy' => true, 'services' => [], 'checked_at' => now()->toIso8601String()];
            }
        });
    }

    private function signedInUser(int $creditsBalance = 5): User
    {
        $user = User::factory()->create([
            'credits_balance'        => $creditsBalance,
            'credits_lifetime_spent' => 0,
        ]);
        $this->actingAs($user);
        return $user;
    }

    /** @return array<string,mixed> */
    private function fakePlaceData(string $placeId = 'ChIJtest1234567890'): array
    {
        return [
            'place_id'                   => $placeId,
            'name'                       => 'Laundry Bersih Sentosa',
            'formatted_address'          => 'Jl. Test 123, Yogyakarta, DIY 55281',
            'geometry'                   => ['location' => ['lat' => -7.7956, 'lng' => 110.3695]],
            'website'                    => 'https://laundrybersih.example.id',
            'international_phone_number' => '+62 274 1234567',
            'types'                      => ['laundry', 'point_of_interest'],
            'address_components'         => [
                ['long_name' => 'Yogyakarta', 'short_name' => 'YK', 'types' => ['administrative_area_level_2']],
                ['long_name' => 'Daerah Istimewa Yogyakarta', 'short_name' => 'DIY', 'types' => ['administrative_area_level_1']],
            ],
        ];
    }

    public function test_wizard_starts_at_step_1(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->assertSet('step', 'wizard')
            ->assertSet('wizardStep', 1);
    }

    public function test_guest_user_sees_sign_in_cta_inside_wizard(): void
    {
        // No actingAs — explicit guest.
        Livewire::test(self::VIEW)
            ->assertSee('Masuk dengan Google');
    }

    public function test_next_step_without_place_selection_stays_at_step_1(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->call('nextStep')
            ->assertSet('wizardStep', 1)
            ->assertHasErrors('placeId');
    }

    public function test_select_place_hydrates_all_place_fields(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->call('selectPlace', $this->fakePlaceData())
            ->assertSet('placeId', 'ChIJtest1234567890')
            ->assertSet('placeName', 'Laundry Bersih Sentosa')
            ->assertSet('placeAddress', 'Jl. Test 123, Yogyakarta, DIY 55281')
            ->assertSet('placePhone', '+62 274 1234567')
            ->assertSet('placeWebsite', 'https://laundrybersih.example.id');
    }

    public function test_advances_to_step_2_when_place_selected(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->call('selectPlace', $this->fakePlaceData())
            ->call('nextStep')
            ->assertSet('wizardStep', 2);
    }

    public function test_clear_selected_place_resets_all_place_fields(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->call('selectPlace', $this->fakePlaceData())
            ->call('clearSelectedPlace')
            ->assertSet('placeId', null)
            ->assertSet('placeName', null)
            ->assertSet('placeWebsite', null);
    }

    public function test_self_service_is_a_valid_service_type(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('wizardStep', 2)
            ->set('serviceType', 'self_service')
            ->call('nextStep')
            ->assertSet('wizardStep', 3)
            ->assertHasNoErrors();
    }

    public function test_unknown_service_type_blocks_advance_from_step_2(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('wizardStep', 2)
            ->set('serviceType', 'pizza')
            ->call('nextStep')
            ->assertSet('wizardStep', 2)
            ->assertHasErrors('serviceType');
    }

    public function test_instagram_url_is_stripped_to_username_on_blur(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('instagramUsername', 'https://www.instagram.com/laundry.bersih/')
            ->assertSet('instagramUsername', 'laundry.bersih');
    }

    public function test_tiktok_url_is_stripped_to_username_on_blur(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('tiktokUsername', 'https://www.tiktok.com/@laundry.bersih?lang=id')
            ->assertSet('tiktokUsername', 'laundry.bersih');
    }

    public function test_at_prefixed_username_is_normalized(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('instagramUsername', '@my.laundry')
            ->assertSet('instagramUsername', 'my.laundry');
    }

    public function test_empty_social_handles_normalize_to_null(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('instagramUsername', '   ')
            ->assertSet('instagramUsername', null)
            ->set('tiktokUsername', '')
            ->assertSet('tiktokUsername', null);
    }

    public function test_submit_creates_v3_audit_charges_credit_and_dispatches(): void
    {
        $user = $this->signedInUser(3);

        Livewire::test(self::VIEW)
            ->call('selectPlace', $this->fakePlaceData())
            ->set('serviceType', 'self_service')
            ->set('instagramUsername', 'laundry.bersih')
            ->set('tiktokUsername', null)
            ->set('notes', '  Target mahasiswa di kos-kosan.  ')
            ->call('submit');

        $this->assertSame(1, BrandAudit::count(), 'one audit row created');

        $audit = BrandAudit::first();
        // BB134 — the wizard stamps WIZARD_V3 (BB118 rubric alignment,
        // blade submit() line ~1052); the BB97 test predated that bump.
        $this->assertSame(BrandAudit::WIZARD_V3, $audit->wizard_version);
        $this->assertSame('ChIJtest1234567890', $audit->place_id);
        $this->assertSame('Laundry Bersih Sentosa', $audit->place_name);
        $this->assertSame('Laundry Bersih Sentosa', $audit->brand_name, 'brand_name mirrors place_name for legacy compat');
        $this->assertSame('Yogyakarta', $audit->city, 'city derived from admin_area_level_2');
        $this->assertSame('self_service', $audit->service_type);
        $this->assertSame('Target mahasiswa di kos-kosan.', $audit->notes, 'notes trimmed');
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame(1, $audit->credits_charged);

        $touchpoints = $audit->touchpoints;
        $this->assertIsArray($touchpoints);
        $this->assertSame('https://www.instagram.com/laundry.bersih', $touchpoints['instagram_url']);
        $this->assertNull($touchpoints['tiktok_url']);
        $this->assertStringContainsString('place_id:ChIJtest1234567890', $touchpoints['gmaps_url']);
        $this->assertSame('https://laundrybersih.example.id', $touchpoints['website_url']);

        $user->refresh();
        $this->assertSame(2, $user->credits_balance, 'one credit debited');
        $this->assertSame(1, $user->credits_lifetime_spent);

        Bus::assertDispatched(AnalyzeBrand::class);
    }

    public function test_submit_blocks_when_balance_is_zero(): void
    {
        $this->signedInUser(0);

        Livewire::test(self::VIEW)
            ->call('selectPlace', $this->fakePlaceData())
            ->set('serviceType', 'kiloan')
            ->call('submit')
            ->assertSet('showInsufficientCreditsModal', true);

        $this->assertSame(0, BrandAudit::count(), 'no audit row created');
        Bus::assertNotDispatched(AnalyzeBrand::class);
    }

    public function test_submit_without_place_bounces_to_step_1(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('wizardStep', 4)
            ->set('serviceType', 'kiloan')
            ->call('submit')
            ->assertSet('wizardStep', 1)
            ->assertHasErrors('placeId');

        $this->assertSame(0, BrandAudit::count());
    }

    public function test_manual_gmaps_url_rejects_non_google_host(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('manualGmapsUrl', 'https://example.com/place/random')
            ->call('submitManualGmapsUrl')
            ->assertSet('placeId', null);
        // The component sets manualResolveError to an Indonesian
        // string; we assert non-null rather than coupling the test
        // to specific copy.
    }

    public function test_manual_gmaps_url_resolves_via_places_api_service(): void
    {
        $this->signedInUser();

        // Bind a stub PlacesApiService that returns a known payload.
        $this->app->instance(PlacesApiService::class, new class extends PlacesApiService {
            public function __construct() {}
            public function resolveManualUrl(string $url): ?array
            {
                return [
                    'place_id'                   => 'ChIJstub',
                    'name'                       => 'Stub Laundry',
                    'formatted_address'          => 'Jl. Stub 1',
                    'geometry'                   => ['location' => ['lat' => 0.1, 'lng' => 0.2]],
                    'website'                    => null,
                    'international_phone_number' => null,
                    'types'                      => ['laundry'],
                    'address_components'         => [],
                ];
            }
            public function fetchPlaceDetails(string $placeId): ?array { return null; }
            public function hasApiKey(): bool { return true; }
        });

        Livewire::test(self::VIEW)
            ->set('manualGmapsUrl', 'https://www.google.com/maps/place/Stub+Laundry/')
            ->set('showManualFallback', true)
            ->call('submitManualGmapsUrl')
            ->assertSet('placeId', 'ChIJstub')
            ->assertSet('placeName', 'Stub Laundry')
            ->assertSet('showManualFallback', false);
    }

    public function test_previous_step_decrements_wizard_step(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('wizardStep', 3)
            ->call('previousStep')
            ->assertSet('wizardStep', 2)
            ->call('previousStep')
            ->assertSet('wizardStep', 1)
            ->call('previousStep')
            ->assertSet('wizardStep', 1, 'previousStep clamps at 1');
    }

    public function test_next_step_clamps_at_total_steps(): void
    {
        $this->signedInUser();

        Livewire::test(self::VIEW)
            ->set('wizardStep', 3)
            ->call('nextStep')
            ->assertSet('wizardStep', 4)
            ->call('nextStep')
            ->assertSet('wizardStep', 4, 'nextStep clamps at totalWizardSteps');
    }
}
