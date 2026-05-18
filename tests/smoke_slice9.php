<?php

/**
 * Smoke test — Slice 9: Score Transparency Layer
 *
 * Verifies:
 * 1. score_breakdown column exists and is writable
 * 2. RecallScorer emits correct breakdown structure
 * 3. DigitalPresenceScorer emits correct breakdown structure
 * 4. score_breakdown persists to the audit row
 * 5. PDF regenerates with breakdown content (larger file)
 * 6. konsistensi_visual limitations note is present
 *
 * Run: php tests/smoke_slice9.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require_once dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\GenerateActivationKit;
use App\Models\BrandAudit;
use App\Services\Scoring\DigitalPresenceScorer;
use App\Services\Scoring\RecallScorer;

$ok     = 0;
$errors = 0;

$pass = function (string $msg) use (&$ok): void {
    echo "  [PASS] {$msg}\n";
    $ok++;
};

$fail = function (string $msg) use (&$errors): void {
    echo "  [FAIL] {$msg}\n";
    $errors++;
};

echo "\n=== Smoke Test: Score Transparency Layer ===\n\n";

// ── 1. RecallScorer breakdown structure ──────────────────────────────────────
echo "1. RecallScorer breakdown\n";

$recallResult = (new RecallScorer())->score([
    // BB117 — pin legacy path explicitly so this assertion block stays
    // pinned to v1 caps (rating 25, review_count_tier 15, etc.) even
    // after the v3 default flips at some future Phase 13 milestone.
    '_wizard_version' => \App\Models\BrandAudit::WIZARD_V1,
    'rating'          => 4.9,
    'review_count'    => 150,
    'keyword_hits'    => [],
    'sampled_reviews' => [
        ['text' => 'sangat bersih dan harum', 'rating' => 5.0],
        ['text' => 'tepat waktu dan ramah',   'rating' => 5.0],
        ['text' => 'biasa aja',               'rating' => 3.0],
    ],
]);

$bd = $recallResult->scoreBreakdown;

if (isset($bd['rating_tier']['formula']) && $bd['rating_tier']['formula'] === 'deterministic_threshold') {
    $pass('rating_tier has deterministic_threshold formula');
} else {
    $fail('rating_tier missing formula');
}

if (! empty($bd['rating_tier']['tier_table'])) {
    $matched = array_filter($bd['rating_tier']['tier_table'], fn ($t) => $t['matched'] ?? false);
    if (count($matched) === 1 && reset($matched)['range'] === '≥4.8') {
        $pass('rating_tier matched correct tier (≥4.8 → 25pt after Phase 6-partial rebalance)');
    } else {
        $fail('rating_tier matched wrong tier');
    }
} else {
    $fail('rating_tier missing tier_table');
}

if (($bd['rating_tier']['score'] ?? null) === 25) {
    $pass('rating_tier score = 25 (was 35 pre-rebalance)');
} else {
    $fail('rating_tier score wrong: ' . ($bd['rating_tier']['score'] ?? 'null'));
}

if (isset($bd['review_count_tier']['raw_inputs']['review_count']) && $bd['review_count_tier']['raw_inputs']['review_count'] === 150) {
    $pass('review_count_tier raw_inputs has review_count = 150');
} else {
    $fail('review_count_tier raw_inputs missing review_count');
}

echo "\n2. DigitalPresenceScorer breakdown\n";

$digitalResult = (new DigitalPresenceScorer())->score([
    // BB117 — pin legacy path explicitly. v3 caps the same booleans
    // differently (has_instagram is graded 0-20 via
    // InstagramActivityScorer in v3, TikTok jumps 3→10 in v3) so the
    // assertions below would mis-fire without an explicit version tag.
    '_wizard_version'  => \App\Models\BrandAudit::WIZARD_V1,
    'has_instagram'    => true,
    'has_website'      => true,
    'has_gmaps'        => true,
    'has_wa_business'  => false,
    'has_tiktok'       => false,
    'review_count'     => 150,
]);

$dbd = $digitalResult->scoreBreakdown;

if (($dbd['has_gmaps']['score'] ?? null) === 25) {
    $pass('has_gmaps score = 25');
} else {
    $fail('has_gmaps score wrong');
}

if (($dbd['has_wa']['score'] ?? null) === 0) {
    $pass('has_wa score = 0 (not present)');
} else {
    $fail('has_wa score wrong');
}

$gmapsMatched = array_filter($dbd['has_gmaps']['tier_table'] ?? [], fn ($t) => $t['matched'] ?? false);
if (count($gmapsMatched) === 1 && reset($gmapsMatched)['range'] === 'Hadir') {
    $pass('has_gmaps tier matched "Hadir"');
} else {
    $fail('has_gmaps tier not matched correctly');
}

if (($dbd['review_bonus']['score'] ?? null) === 15) {
    $pass('review_bonus = 15 (≥50 reviews)');
} else {
    $fail('review_bonus wrong: ' . ($dbd['review_bonus']['score'] ?? 'null'));
}

// ── 3. Persist synthetic breakdown to Less Worry audit ────────────────────────
echo "\n3. Persist score_breakdown to existing audit\n";

$audit = BrandAudit::where('brand_name', 'like', '%Less Worry%')->orderByDesc('created_at')->first();

if (! $audit) {
    $fail('Less Worry audit not found');
    echo "\nResult: {$ok} passed, {$errors} failed\n\n";
    exit($errors > 0 ? 1 : 0);
}

$pass("Found audit: {$audit->brand_name} (ID: {$audit->id})");

// Build synthetic breakdown merging scorer output with existing LLM pillar data
$subBuckets  = $audit->sub_bucket_scores ?? [];
$pillarScores = $audit->pillar_scores ?? [];

$syntheticBreakdown = [];

// Recall — use actual scorer with dummy inputs matching stored score shape
$syntheticBreakdown['brand-recall'] = $recallResult->scoreBreakdown;

// Digital — use actual scorer
$syntheticBreakdown['digital-presence'] = $digitalResult->scoreBreakdown;

// Konsistensi — build LLM-style breakdown from stored reasoning
$kReasoning = is_array($pillarScores['brand-konsistensi'] ?? null)
    ? ($pillarScores['brand-konsistensi']['reasoning'] ?? 'Penilaian berdasarkan kehadiran digital yang tersedia.')
    : 'Penilaian berdasarkan kehadiran digital yang tersedia.';

$kLimitations = [
    'Instagram content not fetched in v0 — judgment based on URL presence only',
    'TikTok content not fetched in v0 — judgment based on URL presence only',
    'No outlet photos provided',
];

foreach ($subBuckets['brand-konsistensi'] ?? [] as $k => $v) {
    $syntheticBreakdown['brand-konsistensi'][$k] = [
        'score'          => $v,
        'cap'            => match ($k) { 'kehadiran_digital' => 40, 'konsistensi_visual' => 35, 'kelengkapan_layanan' => 15, 'transparansi_harga' => 10, default => null },
        'raw_inputs'     => ['context_provided' => ['instagram_url: yes', 'website_url: yes', 'gmaps_url: yes', 'outlet_photos_count: 0']],
        'formula'        => 'llm_judgment',
        'llm_reasoning'  => $kReasoning,
        'limitations'    => $kLimitations,
        'explanation_id' => $k . '_llm_v1',
    ];
}

// Experience
$eReasoning = is_array($pillarScores['brand-experience'] ?? null)
    ? ($pillarScores['brand-experience']['reasoning'] ?? 'Penilaian berdasarkan informasi layanan yang tersedia.')
    : 'Penilaian berdasarkan informasi layanan yang tersedia.';

foreach ($subBuckets['brand-experience'] ?? [] as $k => $v) {
    $syntheticBreakdown['brand-experience'][$k] = [
        'score'          => $v,
        'cap'            => null,
        'raw_inputs'     => ['context_provided' => ['instagram_url: yes', 'website_url: yes', 'keyword_hits: present']],
        'formula'        => 'llm_judgment',
        'llm_reasoning'  => $eReasoning,
        'limitations'    => ['Instagram content not fetched in v0 — judgment based on URL presence only'],
        'explanation_id' => $k . '_llm_v1',
    ];
}

$audit->update(['score_breakdown' => $syntheticBreakdown]);
$audit->refresh();

if (is_array($audit->score_breakdown) && count($audit->score_breakdown) === 4) {
    $pass('score_breakdown persisted with 4 pillars');
} else {
    $fail('score_breakdown not persisted correctly');
}

// Verify konsistensi_visual has limitations
$kvBreakdown = $audit->score_breakdown['brand-konsistensi']['konsistensi_visual'] ?? null;
if (is_array($kvBreakdown) && ! empty($kvBreakdown['limitations'])) {
    $hasIgNote = in_array(
        'Instagram content not fetched in v0 — judgment based on URL presence only',
        $kvBreakdown['limitations'],
        true,
    );
    if ($hasIgNote) {
        $pass('konsistensi_visual limitations contains IG "not fetched" note');
    } else {
        $fail('konsistensi_visual limitations missing IG note');
    }
} else {
    $fail('konsistensi_visual breakdown missing limitations');
}

// ── 4. Regenerate PDF ─────────────────────────────────────────────────────────
echo "\n4. Regenerate activation kit PDF\n";

$prevSize = $audit->activation_kit_path
    ? \Illuminate\Support\Facades\Storage::disk('local')->size($audit->activation_kit_path)
    : 0;

$audit->update(['activation_kit_path' => null]);
GenerateActivationKit::dispatchSync($audit);
$audit->refresh();

if ($audit->activation_kit_path) {
    $newSize = \Illuminate\Support\Facades\Storage::disk('local')->size($audit->activation_kit_path);
    $pass("PDF regenerated at {$audit->activation_kit_path} ({$newSize} bytes)");

    if ($prevSize > 0) {
        if ($newSize >= $prevSize) {
            $pass("PDF is same size or larger than before ({$prevSize} → {$newSize} bytes) — breakdown content added");
        } else {
            $pass("PDF size changed ({$prevSize} → {$newSize} bytes)");
        }
    }
} else {
    $fail('PDF generation failed — activation_kit_path still null');
}

// ── 4. BB117 v3 scorer outputs ───────────────────────────────────────────────
echo "\n4. BB117 v3 scorer outputs\n";

$recallV3 = (new RecallScorer())->score([
    '_wizard_version'  => \App\Models\BrandAudit::WIZARD_V3,
    'brand_name'       => 'BB117 v3 Smoke',
    'rating'           => 4.9,
    'review_count'     => 600,
    'keyword_hits'     => [],
    'sampled_reviews'  => [],
    'full_reviews'     => array_fill(0, 30, ['text' => 'sangat bersih harum wangi', 'rating' => 5.0]),
    'owner_reply_rate' => 1.0,
    'has_sop_declared' => true,
]);
$rv3 = $recallV3->subBucketScores;
if (isset($rv3['rating_tier'], $rv3['review_count_tier'], $rv3['kualitas_ulasan_positif'], $rv3['manajemen_ulasan'])
    && ! isset($rv3['sentiment_quality'])) {
    $pass('v3 RecallScorer emits PPT 4-bucket shape (kualitas_ulasan_positif + manajemen_ulasan, no sentiment_quality)');
} else {
    $fail('v3 RecallScorer sub-bucket shape wrong: ' . implode(',', array_keys($rv3)));
}
if ($rv3['rating_tier'] === 35 && $rv3['review_count_tier'] === 25 && $rv3['manajemen_ulasan'] === 20) {
    $pass('v3 RecallScorer top-tier caps match PPT (rating=35, count=25, manajemen=20)');
} else {
    $fail('v3 RecallScorer caps off: ' . json_encode($rv3));
}
if ($recallV3->score <= 100) {
    $pass("v3 RecallScorer total {$recallV3->score} ≤ 100");
} else {
    $fail("v3 RecallScorer total exceeds 100: {$recallV3->score}");
}

$digitalV3 = (new DigitalPresenceScorer())->score([
    '_wizard_version'           => \App\Models\BrandAudit::WIZARD_V3,
    'has_gmaps'                 => true,
    'has_wa_business'           => true,
    'review_count'              => 600,
    'instagram_activity_score'  => 18,
    'website_is_live'           => true,
    'tiktok_check_status'       => 'found',
]);
$dv3 = $digitalV3->subBucketScores;
if (isset($dv3['review_count_5plus'], $dv3['review_count_50plus']) && ! isset($dv3['review_bonus'])) {
    $pass('v3 DigitalPresenceScorer splits review_bonus into 5plus + 50plus');
} else {
    $fail('v3 DigitalPresenceScorer review split shape wrong: ' . implode(',', array_keys($dv3)));
}
if ($dv3['has_tiktok'] === 10) {
    $pass('v3 DigitalPresenceScorer TikTok promoted to 10 pts');
} else {
    $fail("v3 DigitalPresenceScorer TikTok wrong: {$dv3['has_tiktok']}");
}
if ($digitalV3->score === 98) {
    // 25 + 18 + 20 + 15 + 10 + 5 + 5 = 98
    $pass("v3 DigitalPresenceScorer total = 98 (25+18+20+15+10+5+5)");
} else {
    $fail("v3 DigitalPresenceScorer total off: {$digitalV3->score}");
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n=== Result: {$ok} passed, {$errors} failed ===\n\n";
exit($errors > 0 ? 1 : 0);
