<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Internal;

use App\Models\BrandAudit;
use App\Models\CreditAdjustment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersApiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.hub.users_api_key', 'test-bearer-xyz');
    }

    public function test_index_requires_bearer_token(): void
    {
        $response = $this->getJson('/api/internal/users');

        $response->assertStatus(401);
    }

    public function test_index_returns_paginated_users(): void
    {
        User::factory()->count(3)->create();

        $response = $this->withToken('test-bearer-xyz')
            ->getJson('/api/internal/users');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'email', 'name', 'credits_balance', 'total_audits']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_search_filters_by_email(): void
    {
        User::factory()->create(['email' => 'alice@target.local']);
        User::factory()->create(['email' => 'bob@other.local']);

        $response = $this->withToken('test-bearer-xyz')
            ->getJson('/api/internal/users?search=alice');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_returns_user_with_audits_and_adjustments(): void
    {
        $user = User::factory()->create();
        BrandAudit::create([
            'session_token' => 'tok-show-1',
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Show Brand',
            'city'          => 'Jakarta',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_DONE,
            'expires_at'    => now()->addDays(30),
        ]);
        CreditAdjustment::create([
            'user_id'     => $user->id,
            'amount'      => 5,
            'reason'      => 'Compensation',
            'adjusted_by' => 'ops@nema.local',
            'created_at'  => now(),
        ]);

        $response = $this->withToken('test-bearer-xyz')
            ->getJson('/api/internal/users/' . $user->id);

        $response->assertOk();
        $this->assertSame($user->id, $response->json('user.id'));
        $this->assertCount(1, $response->json('audits'));
        $this->assertCount(1, $response->json('adjustments'));
        $this->assertSame(5, $response->json('adjustments.0.amount'));
    }

    public function test_show_returns_404_for_unknown_user(): void
    {
        $response = $this->withToken('test-bearer-xyz')
            ->getJson('/api/internal/users/01-bogus-id');

        $response->assertStatus(404);
    }

    public function test_adjust_credits_adds_to_balance_and_logs_row(): void
    {
        $user = User::factory()->create(['credits_balance' => 2]);

        $response = $this->withToken('test-bearer-xyz')
            ->postJson('/api/internal/users/' . $user->id . '/credits/adjust', [
                'amount'      => 3,
                'reason'      => 'Manual top-up',
                'adjusted_by' => 'admin@nema.local',
            ]);

        $response->assertOk();
        $this->assertSame(5, $response->json('credits_balance'));
        $this->assertDatabaseHas('credit_adjustments', [
            'user_id'     => $user->id,
            'amount'      => 3,
            'reason'      => 'Manual top-up',
            'adjusted_by' => 'admin@nema.local',
        ]);
        $this->assertSame(5, (int) $user->fresh()->credits_balance);
        $this->assertSame(4, (int) $user->fresh()->credits_lifetime_earned, 'lifetime_earned should grow on positive adjust');
    }

    public function test_adjust_credits_deducts_with_clamping(): void
    {
        $user = User::factory()->create(['credits_balance' => 2]);

        $response = $this->withToken('test-bearer-xyz')
            ->postJson('/api/internal/users/' . $user->id . '/credits/adjust', [
                'amount'      => -10,
                'reason'      => 'Refund clawback',
                'adjusted_by' => 'admin@nema.local',
            ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('credits_balance'));
        $this->assertDatabaseHas('credit_adjustments', [
            'user_id' => $user->id,
            'amount'  => -2, // clamped from -10
        ]);
    }

    public function test_adjust_credits_rejects_zero(): void
    {
        $user = User::factory()->create();

        $response = $this->withToken('test-bearer-xyz')
            ->postJson('/api/internal/users/' . $user->id . '/credits/adjust', [
                'amount'      => 0,
                'reason'      => 'No-op',
                'adjusted_by' => 'admin@nema.local',
            ]);

        $response->assertStatus(422);
    }

    public function test_destroy_requires_bearer_token(): void
    {
        $user = User::factory()->create();

        $response = $this->deleteJson('/api/internal/users/' . $user->id);

        $response->assertStatus(401);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_destroy_returns_404_for_unknown_user(): void
    {
        $response = $this->withToken('test-bearer-xyz')
            ->deleteJson('/api/internal/users/01-bogus-id');

        $response->assertStatus(404);
    }

    public function test_destroy_deletes_user_and_cascades_their_data(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $audit = BrandAudit::create([
            'session_token' => 'tok-del-1',
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Doomed Brand',
            'city'          => 'Jakarta',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_DONE,
            'expires_at'    => now()->addDays(30),
        ]);
        $audit->brandKit()->create([
            'generated_payload' => ['summary' => 'test'],
            'pdf_path'          => 'audits/' . $audit->id . '/activation-kit.pdf',
        ]);
        CreditAdjustment::create([
            'user_id'     => $user->id,
            'amount'      => 5,
            'reason'      => 'Compensation',
            'adjusted_by' => 'ops@nema.local',
            'created_at'  => now(),
        ]);

        // A second user's audit must survive untouched.
        $otherAudit = BrandAudit::create([
            'session_token' => 'tok-keep-1',
            'user_id'       => $other->id,
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Survivor Brand',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_DONE,
            'expires_at'    => now()->addDays(30),
        ]);

        $response = $this->withToken('test-bearer-xyz')
            ->deleteJson('/api/internal/users/' . $user->id);

        $response->assertOk()
            ->assertJson([
                'deleted'        => true,
                'user_id'        => $user->id,
                'audits_deleted' => 1,
            ]);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('brand_audits', ['id' => $audit->id]);
        $this->assertDatabaseMissing('brand_kits', ['brand_audit_id' => $audit->id]);
        $this->assertDatabaseMissing('credit_adjustments', ['user_id' => $user->id]);

        $this->assertDatabaseHas('users', ['id' => $other->id]);
        $this->assertDatabaseHas('brand_audits', ['id' => $otherAudit->id]);
    }
}
