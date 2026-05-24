<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\BrandAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('audits.index'));

        $response->assertRedirect();
    }

    public function test_authenticated_user_sees_only_their_own_audits(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceAudit = $this->makeAudit($alice, 'Alice Laundry');
        $this->makeAudit($bob, 'Bob Laundry');

        $response = $this->actingAs($alice)->get(route('audits.index'));

        $response->assertOk();
        $response->assertSee('Alice Laundry');
        $response->assertDontSee('Bob Laundry');
    }

    public function test_empty_state_renders_when_user_has_no_audits(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('audits.index'));

        $response->assertOk();
        $response->assertSee('Belum ada audit');
        $response->assertSee('Mulai Audit Pertama');
    }

    public function test_audits_are_sorted_newest_first(): void
    {
        $user = User::factory()->create();
        $old = $this->makeAudit($user, 'Lama Laundry', now()->subDays(7));
        $new = $this->makeAudit($user, 'Baru Laundry', now()->subHours(2));

        $response = $this->actingAs($user)->get(route('audits.index'));

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertLessThan(
            strpos($body, 'Lama Laundry'),
            strpos($body, 'Baru Laundry'),
            'Newer audit should appear before older audit in the markup.'
        );
    }

    public function test_user_can_delete_their_own_audit(): void
    {
        $user = User::factory()->create();
        $audit = $this->makeAudit($user, 'Hapus Laundry');

        Livewire::actingAs($user)
            ->test('audits-index')
            ->call('deleteAudit', $audit->id);

        $this->assertDatabaseMissing('brand_audits', ['id' => $audit->id]);
    }

    public function test_user_cannot_delete_another_users_audit(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobAudit = $this->makeAudit($bob, 'Bob Laundry');

        Livewire::actingAs($alice)
            ->test('audits-index')
            ->call('deleteAudit', $bobAudit->id);

        $this->assertDatabaseHas('brand_audits', ['id' => $bobAudit->id]);
    }

    public function test_deleting_audit_cascades_related_rows(): void
    {
        $user = User::factory()->create();
        $audit = $this->makeAudit($user, 'Cascade Laundry');

        $audit->brandKit()->create([
            'generated_payload' => ['summary' => 'test'],
            'pdf_path'          => 'audits/' . $audit->id . '/activation-kit.pdf',
        ]);

        Livewire::actingAs($user)
            ->test('audits-index')
            ->call('deleteAudit', $audit->id);

        $this->assertDatabaseMissing('brand_audits', ['id' => $audit->id]);
        $this->assertDatabaseMissing('brand_kits', ['brand_audit_id' => $audit->id]);
    }

    private function makeAudit(User $user, string $brandName, ?\Carbon\Carbon $createdAt = null): BrandAudit
    {
        $audit = BrandAudit::create([
            'session_token' => 'tok-' . uniqid(),
            'user_id'       => $user->id,
            'credits_charged' => 1,
            'ip_address'    => '127.0.0.1',
            'brand_name'    => $brandName,
            'city'          => 'Jakarta',
            'service_type'  => 'kiloan',
            'touchpoints'   => [],
            'status'        => BrandAudit::STATUS_DONE,
            'overall_score' => 72,
            'expires_at'    => now()->addDays(30),
        ]);

        if ($createdAt) {
            $audit->forceFill(['created_at' => $createdAt])->save();
        }

        return $audit;
    }
}
