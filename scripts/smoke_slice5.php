<?php

/**
 * Slice 5 end-to-end smoke test.
 *
 * Fill in the TEST BRAND section below, then run:
 *   php scripts/smoke_slice5.php
 *
 * What this covers:
 *   - GoogleMapsReviewsFetcher → RecallScorer + narrative
 *   - TouchpointPresenceDetector → DigitalPresenceScorer + narrative
 *   - WebsiteFetcher (content used for Experience + Konsistensi inputs)
 *   - ClaudeService::scorePillar for all 4 pillars
 *   - Weighted overall score + 5-tier label
 */

declare(strict_types=1);

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ClaudeService;
use App\Services\Fetchers\GoogleMapsReviewsFetcher;
use App\Services\Fetchers\TouchpointPresenceDetector;
use App\Services\Fetchers\WebsiteFetcher;
use App\Services\Scoring\DigitalPresenceScorer;
use App\Services\Scoring\RecallScorer;
use App\Models\ScoringRubric;

// =============================================================================
// TEST BRAND — fill in before running
// =============================================================================
$BRAND_NAME    = 'Laundry Bersih Jaya';   // brand name for GMaps text-search fallback
$INSTAGRAM_URL = '';                       // e.g. https://www.instagram.com/handle/
$WEBSITE_URL   = '';                       // e.g. https://laundrybersih.com
$GMAPS_URL     = '';                       // full or short Google Maps URL
$WA_ACTIVE     = false;                   // true if WA Business is listed
$TIKTOK_URL    = '';                       // nullable
// =============================================================================

echo "=== Slice 5 Smoke Test: {$BRAND_NAME} ===\n\n";

// Google Maps API key from vault
$googleMapsKey = (string) config('services.google_maps.key', '');

// ── Step 1: GMaps reviews ────────────────────────────────────────────────────
echo "[1/5] Fetching Google Maps reviews…\n";
$reviewData = null;

if ($googleMapsKey !== '' && $GMAPS_URL !== '') {
    $gmapsFetcher = new GoogleMapsReviewsFetcher($googleMapsKey);
    $reviewData   = $gmapsFetcher->fetch($GMAPS_URL, $BRAND_NAME);
}

if ($reviewData === null) {
    echo "      ⚠  GMaps fetch failed or API key missing — using synthetic fallback\n";
    $reviewData = [
        'rating'              => 0.0,
        'review_count'        => 0,
        'owner_response_rate' => 0.0,
        'keyword_hits'        => ['positive' => [], 'negative' => []],
        'recent_reviews'      => [],
    ];
}

echo "      rating={$reviewData['rating']}, reviews={$reviewData['review_count']}\n";
echo '      keyword_hits=' . json_encode($reviewData['keyword_hits'], JSON_UNESCAPED_UNICODE) . "\n\n";

// ── Step 2: Touchpoint presence ──────────────────────────────────────────────
echo "[2/5] Detecting touchpoint presence…\n";
$detector = new TouchpointPresenceDetector();
$presence = $detector->detect([
    'instagram_url'           => $INSTAGRAM_URL,
    'website_url'             => $WEBSITE_URL,
    'gmaps_url'               => $GMAPS_URL,
    'whatsapp_business_active' => $WA_ACTIVE,
    'tiktok_url'              => $TIKTOK_URL,
    'review_count'            => $reviewData['review_count'],
]);
echo '      ' . json_encode($presence) . "\n\n";

// ── Step 3: Website content ──────────────────────────────────────────────────
echo "[3/5] Fetching website…\n";
$websiteData = null;

if ($WEBSITE_URL !== '') {
    $websiteData = (new WebsiteFetcher())->fetch($WEBSITE_URL);
}

if ($websiteData === null) {
    echo "      ⚠  Website fetch failed or URL empty\n";
} else {
    echo '      meta_description=' . ($websiteData['meta_description'] ?? '(none)') . "\n";
    echo '      text_content_len=' . mb_strlen($websiteData['text_content']) . " chars\n";
}
echo "\n";

// ── Step 4: Score all 4 pillars ──────────────────────────────────────────────
echo "[4/5] Scoring 4 pillars…\n";
$claude = new ClaudeService();

// — Recall (deterministic + narrative) —
$recallInputs = [
    'brand_name'          => $BRAND_NAME,
    'rating'              => $reviewData['rating'],
    'review_count'        => $reviewData['review_count'],
    'owner_response_rate' => $reviewData['owner_response_rate'],
    'keyword_hits'        => $reviewData['keyword_hits'],
];
$recallScore = $claude->scorePillar(ScoringRubric::PILLAR_RECALL, $recallInputs);

// — Digital (deterministic + narrative) —
$digitalScore = $claude->scorePillar(ScoringRubric::PILLAR_DIGITAL, array_merge(
    $presence,
    ['brand_name' => $BRAND_NAME],
));

// — Konsistensi (LLM) —
$konsistensiInputs = [
    'brand_name'               => $BRAND_NAME,
    'instagram_url'            => $INSTAGRAM_URL,
    'website_url'              => $WEBSITE_URL,
    'gmaps_url'                => $GMAPS_URL,
    'whatsapp_business_active' => $WA_ACTIVE,
    'tiktok_url'               => $TIKTOK_URL,
    'outlet_photo_paths'       => [],
];
$konsistensiScore = $claude->scorePillar(ScoringRubric::PILLAR_KONSISTENSI, $konsistensiInputs);

// — Experience (LLM) —
$experienceInputs = [
    'brand_name'      => $BRAND_NAME,
    'service_type'    => 'laundry',
    'instagram_url'   => $INSTAGRAM_URL,
    'website_url'     => $WEBSITE_URL,
    'website_excerpt' => $websiteData['text_content'] ?? '',
    'keyword_hits'    => $reviewData['keyword_hits'],
];
$experienceScore = $claude->scorePillar(ScoringRubric::PILLAR_EXPERIENCE, $experienceInputs);

// ── Step 5: Weighted overall + label ────────────────────────────────────────
echo "\n[5/5] Overall score…\n";

$weights = config('branding.pillar_weights');
$overall = (int) round(
    $konsistensiScore->score * $weights[ScoringRubric::PILLAR_KONSISTENSI] +
    $recallScore->score      * $weights[ScoringRubric::PILLAR_RECALL] +
    $experienceScore->score  * $weights[ScoringRubric::PILLAR_EXPERIENCE] +
    $digitalScore->score     * $weights[ScoringRubric::PILLAR_DIGITAL],
);

$label = 'CRITICAL — Brand Belum Terbangun';
foreach (config('branding.label_thresholds') as $threshold => $thresholdLabel) {
    if ($overall >= (int) $threshold) {
        $label = $thresholdLabel;
        break;
    }
}

// ── Results ──────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║  BRAND HEALTH CHECK RESULTS                              ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
printf("║  Brand       : %-41s ║\n", $BRAND_NAME);
printf("║  Overall     : %3d/100  %-33s ║\n", $overall, $label);
echo "╠══════════════════════════════════════════════════════════╣\n";
printf("║  Konsistensi : %3d  sub_buckets: %-22s ║\n",
    $konsistensiScore->score,
    json_encode($konsistensiScore->subBucketScores, JSON_UNESCAPED_UNICODE),
);
printf("║  Recall      : %3d  sub_buckets: %-22s ║\n",
    $recallScore->score,
    json_encode($recallScore->subBucketScores, JSON_UNESCAPED_UNICODE),
);
printf("║  Experience  : %3d  sub_buckets: %-22s ║\n",
    $experienceScore->score,
    json_encode($experienceScore->subBucketScores, JSON_UNESCAPED_UNICODE),
);
printf("║  Digital     : %3d  sub_buckets: %-22s ║\n",
    $digitalScore->score,
    json_encode($digitalScore->subBucketScores, JSON_UNESCAPED_UNICODE),
);
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  keyword_hits from GMaps (for sanity check):             ║\n";
echo '║  ' . json_encode($reviewData['keyword_hits'], JSON_UNESCAPED_UNICODE) . "\n";
echo "╚══════════════════════════════════════════════════════════╝\n";
