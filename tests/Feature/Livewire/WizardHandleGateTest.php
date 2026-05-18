<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB106 — Step 3 Lanjutkan gate, Volt edition.
 *
 * Replaces the BB105-era Alpine-wiring assertions. The BB106 fix moves
 * all Step 3 state into the Volt component (no x-data on inputs), so
 * the gate is now testable purely server-side via Livewire::test() —
 * no browser required.
 *
 * Test surface:
 *   - igCheckStatus / ttCheckStatus / whatsappValidity Volt state
 *   - canAdvanceFromStep3 computed (drives the Lanjutkan :disabled)
 *   - step3BlockReason   computed (drives the muted hint copy)
 *   - validateCurrentWizardStep() server-side gate on nextStep()
 *   - skipStep3() opt-out: nulls fields, then advances
 *   - HandleChecker integration via checkInstagram() / checkTiktok()
 */
class WizardHandleGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // mount() calls checkPlatformHealth(), which hits the worker.
        // Pre-seed the cache so tests stay hermetic.
        Cache::flush();
        Cache::forever('platform-health', [
            'healthy'    => true,
            'services'   => [],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function authedComponent()
    {
        return Livewire::actingAs(User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3);
    }

    // ─── canAdvanceFromStep3 truth table ─────────────────────────────

    #[Test]
    public function empty_state_allows_advance(): void
    {
        $this->authedComponent()
            ->assertSet('canAdvanceFromStep3', true);
    }

    #[Test]
    public function instagram_filled_but_unchecked_blocks_advance(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->assertSet('igCheckStatus', 'idle')
            ->assertSet('canAdvanceFromStep3', false);
    }

    #[Test]
    public function instagram_found_allows_advance(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('igCheckStatus', 'found')
            ->assertSet('canAdvanceFromStep3', true);
    }

    #[Test]
    public function instagram_not_found_blocks_advance(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('igCheckStatus', 'not_found')
            ->assertSet('canAdvanceFromStep3', false);
    }

    #[Test]
    public function tiktok_filled_but_unchecked_blocks_advance(): void
    {
        $this->authedComponent()
            ->set('tiktokUsername', 'nasa')
            ->assertSet('ttCheckStatus', 'idle')
            ->assertSet('canAdvanceFromStep3', false);
    }

    #[Test]
    public function whatsapp_invalid_format_blocks_advance(): void
    {
        $this->authedComponent()
            ->set('whatsappNumber', '12345')
            ->assertSet('whatsappValidity', 'invalid')
            ->assertSet('canAdvanceFromStep3', false);
    }

    #[Test]
    public function whatsapp_valid_format_allows_advance(): void
    {
        $this->authedComponent()
            ->set('whatsappNumber', '8123456789')
            ->assertSet('whatsappValidity', 'valid')
            ->assertSet('canAdvanceFromStep3', true);
    }

    // ─── Reset-on-edit behavior ──────────────────────────────────────

    #[Test]
    public function editing_instagram_resets_status_to_idle(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('igCheckStatus', 'found')      // pretend we already verified
            ->set('instagramUsername', 'nasa1')  // user edits → reset
            ->assertSet('igCheckStatus', 'idle');
    }

    #[Test]
    public function editing_tiktok_resets_status_to_idle(): void
    {
        $this->authedComponent()
            ->set('tiktokUsername', 'nasa')
            ->set('ttCheckStatus', 'found')
            ->set('tiktokUsername', 'nasa2')
            ->assertSet('ttCheckStatus', 'idle');
    }

    // ─── checkInstagram / checkTiktok action methods ─────────────────

    #[Test]
    public function check_instagram_flips_status_to_found_when_checker_returns_found(): void
    {
        Http::fake([
            'instagram.com/nasa/*' => Http::response($this->igProfileHtml('nasa', 'NASA'), 200),
        ]);

        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->call('checkInstagram')
            ->assertSet('igCheckStatus', 'found');
    }

    #[Test]
    public function check_instagram_flips_status_to_not_found_when_checker_returns_not_found(): void
    {
        Http::fake([
            'instagram.com/xxxasdjkahsdkjahsdkjasd/*' => Http::response('Page not found', 404),
        ]);

        $this->authedComponent()
            ->set('instagramUsername', 'xxxasdjkahsdkjahsdkjasd')
            ->call('checkInstagram')
            ->assertSet('igCheckStatus', 'not_found');
    }

    #[Test]
    public function check_instagram_flips_status_to_error_when_checker_returns_error(): void
    {
        Http::fake([
            'instagram.com/*' => Http::response('upstream meltdown', 500),
        ]);

        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->call('checkInstagram')
            ->assertSet('igCheckStatus', 'error');
    }

    #[Test]
    public function check_instagram_with_empty_field_stays_idle(): void
    {
        // Should NOT hit Http when the field is empty — defensive against
        // stray button clicks during Livewire morph teardown.
        Http::fake(); // any call would record; we assert zero below

        $this->authedComponent()
            ->set('instagramUsername', null)
            ->call('checkInstagram')
            ->assertSet('igCheckStatus', 'idle');

        Http::assertNothingSent();
    }

    #[Test]
    public function check_tiktok_flips_status_correctly(): void
    {
        Http::fake([
            'tiktok.com/@nasa*' => Http::response($this->ttProfileHtml('nasa', 'NASA'), 200),
        ]);

        $this->authedComponent()
            ->set('tiktokUsername', 'nasa')
            ->call('checkTiktok')
            ->assertSet('ttCheckStatus', 'found');
    }

    // ─── nextStep() server-side gate ─────────────────────────────────

    #[Test]
    public function next_step_blocks_when_instagram_not_verified(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->call('nextStep')
            ->assertSet('wizardStep', 3);   // did not advance
    }

    #[Test]
    public function next_step_advances_when_instagram_verified(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('igCheckStatus', 'found')
            ->call('nextStep')
            ->assertSet('wizardStep', 4);
    }

    #[Test]
    public function next_step_blocks_when_whatsapp_invalid(): void
    {
        $this->authedComponent()
            ->set('whatsappNumber', '12345')
            ->call('nextStep')
            ->assertSet('wizardStep', 3);
    }

    #[Test]
    public function next_step_advances_when_all_fields_empty(): void
    {
        $this->authedComponent()
            ->call('nextStep')
            ->assertSet('wizardStep', 4);
    }

    // ─── skipStep3() opt-out path ────────────────────────────────────

    #[Test]
    public function skip_step3_nulls_all_fields_and_advances(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('tiktokUsername',    'nasa')
            ->set('whatsappNumber',    '8123456789')
            ->set('igCheckStatus', 'not_found')   // would otherwise block
            ->call('skipStep3')
            ->assertSet('instagramUsername', null)
            ->assertSet('tiktokUsername',    null)
            ->assertSet('whatsappNumber',    null)
            ->assertSet('igCheckStatus',    'idle')
            ->assertSet('ttCheckStatus',    'idle')
            ->assertSet('whatsappValidity', 'idle')
            ->assertSet('wizardStep', 4);
    }

    // ─── step3BlockReason copy ───────────────────────────────────────

    #[Test]
    public function block_reason_is_null_when_gate_clear(): void
    {
        $this->authedComponent()
            ->assertSet('step3BlockReason', null);
    }

    #[Test]
    public function block_reason_prompts_to_check_when_instagram_unchecked(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->assertSet('step3BlockReason', 'Klik "Cek dulu" pada Instagram sebelum lanjut.');
    }

    #[Test]
    public function block_reason_surfaces_not_found_copy(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('igCheckStatus', 'not_found')
            ->assertSet('step3BlockReason', 'Periksa lagi handle yang ditandai merah.');
    }

    #[Test]
    public function block_reason_surfaces_worker_error_copy(): void
    {
        $this->authedComponent()
            ->set('instagramUsername', 'nasa')
            ->set('igCheckStatus', 'error')
            ->assertSet('step3BlockReason', 'Worker tidak bisa cek sekarang. Pastikan worker aktif lalu coba lagi.');
    }

    #[Test]
    public function block_reason_surfaces_whatsapp_format_copy(): void
    {
        $this->authedComponent()
            ->set('whatsappNumber', '12345')
            ->assertSet('step3BlockReason', 'Format nomor WhatsApp belum valid.');
    }

    // ─── helpers ─────────────────────────────────────────────────────

    /**
     * Minimal Instagram profile HTML the BB100 parser accepts as "found".
     * The parser reads og:title for display_name and og:image for the
     * pic; anything else is optional. Pattern mirrors BB100/BB101's
     * HandleCheckTest happy-path stubs.
     */
    private function igProfileHtml(string $username, string $displayName): string
    {
        return '<html><head>'
            . '<meta property="og:title" content="' . $displayName . ' (@' . $username . ') • Instagram photos and videos">'
            . '<meta property="og:image" content="https://example.test/' . $username . '.jpg">'
            . '<meta property="og:description" content="100 Followers, 50 Following, 10 Posts">'
            . '</head></html>';
    }

    private function ttProfileHtml(string $username, string $displayName): string
    {
        return '<html><head>'
            . '<meta property="og:title" content="' . $displayName . ' (@' . $username . ') | TikTok">'
            . '<meta property="og:image" content="https://example.test/' . $username . '.jpg">'
            . '</head></html>';
    }
}
