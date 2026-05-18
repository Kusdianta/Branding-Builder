<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\BrandAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 12c.2 BB111-BB116 — audit dashboard polish tests.
 *
 * Loads the wizard at `audit.show` (status = done) so the
 * dashboard branch renders, then asserts:
 *   - BB111: 4-row pillar breakdown table
 *   - BB112: Konsistensi sub-bucket source attribution
 *   - BB113/BB114: technical jargon, raw property names, and
 *     internal phase labels are absent from rendered HTML
 *   - BB115: Search Recall explainer block
 *   - BB116: Digital Presence ✓/✗ rows, IG AI disclaimer,
 *     stripped internal markers
 */
class AuditDetailRenderTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'brand-audit-wizard';

    private function makeCompletedAudit(): BrandAudit
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
                'brand-konsistensi' => [
                    'kehadiran_digital'   => 28,
                    'konsistensi_visual'  => 20,
                    'kelengkapan_layanan' => 9,
                    'transparansi_harga'  => 5,
                ],
                'brand-recall' => [
                    'rating_tier'            => 18,
                    'review_count_tier'      => 10,
                    'review_keyword_quality' => 7,
                    'sentiment_quality'      => 6,
                    'search_recall'          => 7,
                ],
                'brand-experience' => [
                    'base'          => 30,
                    'bonus_ekspres' => 10,
                    'bonus_price_list' => 8,
                ],
                'digital-presence' => [
                    'has_gmaps'     => 25,
                    'has_instagram' => 20,
                    'has_website'   => 20,
                    'has_wa'        => 0,
                    'has_tiktok'    => 0,
                    'review_bonus'  => 5,
                ],
            ],
            'score_breakdown' => [
                'brand-recall' => [
                    'search_recall' => [
                        'formula'    => 'deterministic_signals',
                        'raw_inputs' => ['brand_stem' => 'ion laundry', 'source' => 'Google Autocomplete', 'suggestions' => ['ion laundry', 'ion laundry jakarta', 'ion laundry depok']],
                        'signals'    => [
                            'brand_recognition' => ['score' => 10, 'cap' => 15, 'detail' => 'Top-3 match'],
                            'geographic_spread' => ['score' => 5, 'cap' => 15, 'detail' => '1 of 3 locations'],
                            'variant_coverage'  => ['score' => 2, 'cap' => 5, 'detail' => 'Brand-only variants'],
                        ],
                    ],
                    'sentiment_quality' => [
                        'formula' => 'deterministic_threshold',
                        'raw_inputs' => ['sampled_reviews' => 8, 'keyword_hits' => 3, 'hit_rate_pct' => 37.5, 'sample_source' => 'gmaps_scrape'],
                        'tier_table' => [
                            ['range' => '0-20%', 'points' => 2, 'matched' => false],
                            ['range' => '20-40%', 'points' => 6, 'matched' => true],
                            ['range' => '40-100%', 'points' => 10, 'matched' => false],
                        ],
                    ],
                ],
                'brand-konsistensi' => [
                    'konsistensi_visual' => [
                        'formula'       => 'llm_judgment',
                        'raw_inputs'    => ['context_provided' => ['Instagram screenshot', 'website screenshot']],
                        'llm_reasoning' => 'Logo dan palet warna cukup konsisten di Instagram dan website.',
                    ],
                ],
            ],
            'gmaps_reviews' => [
                'reviews'       => [
                    ['author' => 'Budi', 'rating_value' => 5, 'text' => 'Hasil bersih, harum.', 'date_relative' => '2 minggu lalu'],
                ],
                'business_name' => 'ION Laundry Test',
                'rating'        => 4.5,
                'total_review_count' => 80,
                'scraped_at'    => '2026-05-18T15:37:00+07:00',
            ],
            'gmaps_reviews_status' => 'done',
            'instagram_audit_status' => 'done',
            'instagram_audit' => [
                '_meta' => ['username' => 'iontestlaundry', 'name' => 'ION Laundry', 'followers' => 1200, 'following' => 80, 'posts_count' => 45, 'bio' => 'Laundry kiloan terpercaya'],
                'executive_summary' => 'Profil aktif tapi engagement rendah.',
                'scorecard' => [],
            ],
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_renders_pillar_breakdown_table_with_4_rows(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        $this->assertStringContainsString('Rincian Skor', $html);
        $this->assertStringContainsString('Konsistensi Brand', $html);
        $this->assertStringContainsString('Brand Recall', $html);
        $this->assertStringContainsString('Brand Experience', $html);
        $this->assertStringContainsString('Digital Presence', $html);
    }

    public function test_pillar_table_renders_weight_score_and_color_bar(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // BB111: weights match config/branding.php
        $this->assertStringContainsString('35%', $html);
        $this->assertStringContainsString('20%', $html);
        $this->assertStringContainsString('10%', $html);

        // Pillar-specific colors
        $this->assertStringContainsString('#3D8948', $html); // konsistensi
        $this->assertStringContainsString('#2EBBA0', $html); // recall
    }

    public function test_digital_presence_renders_check_and_x_icons(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // BB116: ✓ for has_gmaps (score > 0), ✗ for has_wa (score 0)
        $this->assertStringContainsString('✓', $html);
        $this->assertStringContainsString('✗', $html);

        // Old "Hadir / Tidak hadir" wording must be absent
        $this->assertStringNotContainsString('Hadir / Tidak hadir', $html);
    }

    public function test_internal_phase_labels_are_stripped_from_user_view(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        $this->assertStringNotContainsString('Phase 8 W8', $html);
        $this->assertStringNotContainsString('full-scrape', $html);
        $this->assertStringNotContainsString('corpus Phase', $html);
    }

    public function test_raw_property_names_are_not_rendered_as_labels(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // Sub-bucket sections never display raw_input keys with
        // underscores in user-facing labels.
        $this->assertStringNotContainsString('sampled_reviews:', $html);
        $this->assertStringNotContainsString('keyword_hits:', $html);
        $this->assertStringNotContainsString('hit_rate_pct:', $html);
        $this->assertStringNotContainsString('color_consistency<', $html);
        $this->assertStringNotContainsString('typography_consistency<', $html);
    }

    public function test_ai_disclaimer_renders_above_instagram_section(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // BB116: AI suggestion disclaimer
        $this->assertStringContainsString('bb-ai-disclaimer', $html);
        $this->assertStringContainsString('Analisis dan rekomendasi di bawah dibuat oleh AI', $html);
    }

    public function test_search_recall_explainer_renders_when_pillar_present(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        $this->assertStringContainsString('Tentang Search Recall', $html);
        $this->assertStringContainsString('Google autocomplete', $html);
    }

    public function test_konsistensi_sub_bucket_source_attribution_renders(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // BB112: per-sub-bucket source attribution (via AuditLabels)
        $this->assertStringContainsString('analisis AI atas screenshot Instagram', $html);
    }

    public function test_formula_labels_are_plain_indonesian(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // BB113/BB114: jargon-free formula labels
        $this->assertStringContainsString('Rumus: berbasis ambang batas', $html);
        $this->assertStringNotContainsString('Threshold tier-based (deterministik)', $html);
        $this->assertStringNotContainsString('Signal-based weighted (deterministik)', $html);
        $this->assertStringNotContainsString('Penilaian LLM (Claude)', $html);
    }

    public function test_signal_labels_use_plain_indonesian(): void
    {
        $audit = $this->makeCompletedAudit();
        $this->actingAs($audit->user);

        $html = (string) Livewire::test(self::VIEW, ['token' => $audit->session_token])
            ->html();

        // BB114: search_recall sub-signals translated
        $this->assertStringContainsString('Pengenalan Brand', $html);
        $this->assertStringContainsString('Sebaran Lokasi', $html);
        $this->assertStringContainsString('Variasi Pencarian', $html);
        $this->assertStringNotContainsString('>brand_recognition<', $html);
        $this->assertStringNotContainsString('>geographic_spread<', $html);
    }
}
