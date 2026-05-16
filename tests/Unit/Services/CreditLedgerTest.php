<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\BrandAudit;
use App\Models\User;
use App\Services\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_charge_decrements_balance_and_increments_spent(): void
    {
        $user = User::factory()->create(['credits_balance' => 3, 'credits_lifetime_spent' => 0]);
        $audit = $this->makeAudit($user);
        $ledger = new CreditLedger();

        $this->assertTrue($ledger->charge($user, $audit));

        $user->refresh();
        $audit->refresh();
        $this->assertSame(2, $user->credits_balance);
        $this->assertSame(1, $user->credits_lifetime_spent);
        $this->assertSame(1, $audit->credits_charged);
    }

    public function test_charge_returns_false_when_balance_is_zero(): void
    {
        $user = User::factory()->noCredits()->create();
        $audit = $this->makeAudit($user);
        $ledger = new CreditLedger();

        $this->assertFalse($ledger->charge($user, $audit));

        $user->refresh();
        $this->assertSame(0, $user->credits_balance);
        $this->assertSame(0, (int) $audit->fresh()->credits_charged);
    }

    public function test_refund_reverses_charge(): void
    {
        $user = User::factory()->create(['credits_balance' => 1, 'credits_lifetime_spent' => 0]);
        $audit = $this->makeAudit($user);
        $ledger = new CreditLedger();
        $ledger->charge($user, $audit);

        $this->assertTrue($ledger->refund($audit));

        $user->refresh();
        $audit->refresh();
        $this->assertSame(1, $user->credits_balance);
        $this->assertSame(0, $user->credits_lifetime_spent);
        $this->assertSame(0, $audit->credits_charged);
    }

    public function test_refund_is_idempotent_on_audit_with_no_charge(): void
    {
        $user = User::factory()->create(['credits_balance' => 5]);
        $audit = $this->makeAudit($user);
        $ledger = new CreditLedger();

        $this->assertFalse($ledger->refund($audit));

        $user->refresh();
        $this->assertSame(5, $user->credits_balance);
    }

    public function test_refund_skips_anonymous_audits_with_null_user_id(): void
    {
        $audit = BrandAudit::create([
            'session_token'   => 'tok-anon',
            'user_id'         => null,
            'credits_charged' => 1,
            'ip_address'      => '127.0.0.1',
            'brand_name'      => 'Anonymous',
            'city'            => 'Bandung',
            'service_type'    => 'kiloan',
            'touchpoints'     => [],
            'status'          => BrandAudit::STATUS_FAILED,
            'expires_at'      => now()->addDays(30),
        ]);

        $this->assertFalse((new CreditLedger())->refund($audit));
    }

    private function makeAudit(User $user): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => 'tok-' . uniqid(),
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Test Brand',
            'city'          => 'Jakarta',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_PENDING,
            'expires_at'    => now()->addDays(30),
        ]);
    }
}
