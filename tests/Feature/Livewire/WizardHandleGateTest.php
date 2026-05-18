<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB105 Part 1 — Step 3 Lanjutkan gate.
 *
 * The disable logic lives entirely in Alpine (parent `step3Gate()`
 * component reads child status via `step3-status` events). These
 * tests therefore verify the BLADE CONTRACT — that the rendered
 * partial wires up the gate directives correctly. The Alpine
 * runtime behavior is covered by manual browser tests in BB105's
 * deliverable list.
 *
 * Why this is the right granularity: Livewire tests don't execute
 * Alpine, so the `:disabled="gateBlocked"` expression is never
 * evaluated server-side. Asserting on the rendered HTML proves the
 * wiring exists; visual confirmation proves the wiring works.
 */
class WizardHandleGateTest extends TestCase
{
    use RefreshDatabase;

    private function renderStep3(): string
    {
        // Wizard always mounts at step 1; advance to step 3 via the
        // public wizardStep property so the step-3-social partial
        // actually renders. Step 1 + 2 guards don't fire for guests
        // (the @guest branch short-circuits), so we set the property
        // directly and bypass validation.
        return Livewire::test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();
    }

    #[Test]
    public function step3_wrapper_uses_step3gate_alpine_component(): void
    {
        $html = $this->renderStep3();

        // For guests the wizard shows the sign-in panel, NOT the step
        // partial. We need an authed user for the step to render.
        $authed = Livewire::actingAs(\App\Models\User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();

        $this->assertStringContainsString('x-data="step3Gate()"', $authed);
    }

    #[Test]
    public function lanjutkan_button_binds_disabled_to_gateblocked(): void
    {
        $html = Livewire::actingAs(\App\Models\User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();

        $this->assertStringContainsString(':disabled="gateBlocked"', $html);
        $this->assertStringContainsString('bb-btn-disabled', $html);
    }

    #[Test]
    public function helper_text_paragraph_is_bound_to_helpertext(): void
    {
        $html = Livewire::actingAs(\App\Models\User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();

        $this->assertStringContainsString('x-text="helperText"', $html);
        $this->assertStringContainsString('x-show="helperText"', $html);
    }

    #[Test]
    public function each_field_dispatches_step3_status(): void
    {
        $html = Livewire::actingAs(\App\Models\User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();

        // All three fields wire x-effect that dispatches the parent event.
        $this->assertStringContainsString("field: 'ig'", $html);
        $this->assertStringContainsString("field: 'tt'", $html);
        $this->assertStringContainsString("field: 'wa'", $html);
        $this->assertStringContainsString('step3-status', $html);
    }

    #[Test]
    public function whatsapp_validate_and_commit_are_renamed(): void
    {
        $html = Livewire::actingAs(\App\Models\User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();

        // Confirm the renamed method names are wired in directives.
        $this->assertStringContainsString('checkFormat()', $html);
        $this->assertStringContainsString('commitWhatsapp()', $html);
        // And the IG/TT side is renamed too (defensive against the same
        // Alpine bare-identifier edge case).
        $this->assertStringContainsString('commitHandle()', $html);
    }

    #[Test]
    public function lewati_button_is_never_disabled(): void
    {
        $html = Livewire::actingAs(\App\Models\User::factory()->create())
            ->test('brand-audit-wizard')
            ->set('wizardStep', 3)
            ->html();

        // The Lewati (skip) button must not carry the gate's :disabled
        // binding — users always retain the escape hatch.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="bb-btn-ghost"[^>]*>\s*Lewati\s*<\/button>/',
            $html,
        );
        // ... and it must NOT contain :disabled inside that specific button.
        if (preg_match('/<button[^>]*class="bb-btn-ghost"[^>]*>\s*Lewati/', $html, $m)) {
            $this->assertStringNotContainsString(':disabled', $m[0]);
        }
    }
}
