<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Concerns;

use App\Models\BrandAudit;
use App\Models\User;

/**
 * BB139 — shared factory for the brand-audit dashboard render tests.
 *
 * Builds a `status=done` BrandAudit so the wizard dashboard branch
 * renders, then lets each test override only the `instagram_audit`
 * payload it cares about. Mirrors the known-good fixture in
 * AuditDetailRenderTest so the heavy pillar/scorecard sections render
 * without error and the assertions can focus on the IG section.
 */
trait MakesCompletedIgAudit
{
    protected function makeIgAudit(array $instagramAudit, string $instagramAuditStatus = 'done'): BrandAudit
    {
        $user = User::factory()->create();

        return BrandAudit::create([
            'session_token'   => 'tok-' . uniqid(),
            'user_id'         => $user->id,
            'credits_charged' => 1,
            'ip_address'      => '127.0.0.1',
            'brand_name'      => 'ION Laundry Test',
            'city'            => 'Jakarta',
            'service_type'    => 'kiloan',
            'touchpoints'     => [],
            'status'          => BrandAudit::STATUS_DONE,
            'overall_score'   => 57,
            'overall_label'   => 'AVERAGE — Perlu Perbaikan Sistematis',
            'pillar_scores'   => [
                'brand-konsistensi' => ['score' => 62, 'reasoning' => 'Identitas brand cukup konsisten.'],
                'brand-recall'      => ['score' => 48, 'reasoning' => 'Brand belum cukup dikenal di pencarian.'],
                'brand-experience'  => ['score' => 55, 'reasoning' => 'Layanan dasar tersedia.'],
                'digital-presence'  => ['score' => 70, 'reasoning' => 'Hampir semua touchpoint hadir.'],
            ],
            'sub_bucket_scores' => [
                'brand-konsistensi' => ['kehadiran_digital' => 28, 'konsistensi_visual' => 20, 'kelengkapan_layanan' => 9, 'transparansi_harga' => 5],
                'brand-recall'      => ['rating_tier' => 18, 'review_count_tier' => 10, 'review_keyword_quality' => 7, 'sentiment_quality' => 6, 'search_recall' => 7],
                'brand-experience'  => ['base' => 30, 'bonus_ekspres' => 10, 'bonus_price_list' => 8],
                'digital-presence'  => ['has_gmaps' => 25, 'has_instagram' => 20, 'has_website' => 20, 'has_wa' => 0, 'has_tiktok' => 0, 'review_bonus' => 5],
            ],
            'instagram_audit_status' => $instagramAuditStatus,
            'instagram_audit'        => $instagramAudit,
            'expires_at'             => now()->addDays(30),
        ]);
    }

    /**
     * Minimal valid `_meta` block. Tests merge their own keys on top.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function igMeta(array $overrides = []): array
    {
        return array_merge([
            'username'    => 'iontestlaundry',
            'name'        => 'ION Laundry',
            'followers'   => 1200,
            'following'   => 80,
            'posts_count' => 45,
            'bio'         => 'Laundry kiloan terpercaya',
        ], $overrides);
    }
}
