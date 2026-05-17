<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB102 — wizard accepts a WhatsApp local-part number, normalises
 * common Indonesian variants (leading 0, leading 62, +62, spaces),
 * and surfaces it through deriveTouchpoints() with the legacy
 * whatsapp_business_active flag flipped on so existing scorers keep
 * working without a sweep.
 */
class WizardWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function leading_zero_is_stripped_to_local_part(): void
    {
        Livewire::test('brand-audit-wizard')
            ->set('whatsappNumber', '08123456789')
            ->assertSet('whatsappNumber', '8123456789');
    }

    #[Test]
    public function plus_62_prefix_is_stripped(): void
    {
        Livewire::test('brand-audit-wizard')
            ->set('whatsappNumber', '+628123456789')
            ->assertSet('whatsappNumber', '8123456789');
    }

    #[Test]
    public function bare_62_country_code_prefix_is_stripped(): void
    {
        Livewire::test('brand-audit-wizard')
            ->set('whatsappNumber', '628123456789')
            ->assertSet('whatsappNumber', '8123456789');
    }

    #[Test]
    public function spaces_and_dashes_are_stripped(): void
    {
        Livewire::test('brand-audit-wizard')
            ->set('whatsappNumber', '0812-3456-789')
            ->assertSet('whatsappNumber', '8123456789');
    }

    #[Test]
    public function malformed_input_becomes_null(): void
    {
        Livewire::test('brand-audit-wizard')
            ->set('whatsappNumber', '12345')
            ->assertSet('whatsappNumber', null);
    }

    #[Test]
    public function empty_input_becomes_null(): void
    {
        Livewire::test('brand-audit-wizard')
            ->set('whatsappNumber', '')
            ->assertSet('whatsappNumber', null);
    }
}
