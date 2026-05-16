<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\BrandAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_factory_creates_user_with_one_free_credit(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->id);
        $this->assertSame(26, strlen($user->id), 'user.id must be a ULID');
        $this->assertSame(1, $user->credits_balance);
        $this->assertSame(1, $user->credits_lifetime_earned);
        $this->assertSame(0, $user->credits_lifetime_spent);
    }

    public function test_user_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'kembar@nema.test']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create(['email' => 'kembar@nema.test']);
    }

    public function test_user_has_brand_audits_relation(): void
    {
        $user = User::factory()->create();
        $audit = BrandAudit::create([
            'session_token'   => 'tok-' . uniqid(),
            'user_id'         => $user->id,
            'credits_charged' => 1,
            'ip_address'      => '127.0.0.1',
            'brand_name'      => 'Test Brand',
            'city'            => 'Jakarta',
            'service_type'    => 'laundry',
            'touchpoints'     => [],
            'status'          => BrandAudit::STATUS_PENDING,
            'expires_at'      => now()->addDays(30),
        ]);

        $this->assertCount(1, $user->brandAudits);
        $this->assertSame($audit->id, $user->brandAudits->first()->id);
        $this->assertSame($user->id, $audit->user->id);
    }

    public function test_no_credits_factory_state_produces_zero_balance(): void
    {
        $user = User::factory()->noCredits()->create();

        $this->assertSame(0, $user->credits_balance);
        $this->assertSame(1, $user->credits_lifetime_spent);
    }
}
