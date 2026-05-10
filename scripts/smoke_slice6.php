<?php

/**
 * Slice 6 end-to-end smoke test.
 * Creates a BrandAudit record and runs AnalyzeBrand synchronously via Bus::batch.
 * Dumps the final brand_audits row including pillar_scores, sub_bucket_scores,
 * key_findings, and recommendations.
 *
 * Run: php scripts/smoke_slice6.php
 */

declare(strict_types=1);

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Force sync queue so Bus::batch jobs run immediately in this process.
config(['queue.default' => 'sync']);

use App\Jobs\AnalyzeBrand;
use App\Models\BrandAudit;
use Illuminate\Support\Str;

echo "=== Slice 6 Smoke Test: Less Worry Laundry (AnalyzeBrand full pipeline) ===\n\n";

// ── Create a fresh audit record ───────────────────────────────────────────────
$audit = BrandAudit::create([
    'session_token' => Str::random(64),
    'ip_address'    => '127.0.0.1',
    'brand_name'    => 'Less Worry Laundry',
    'city'          => 'Bandung',
    'service_type'  => 'laundry',
    'touchpoints'   => [
        'instagram_url'            => 'https://www.instagram.com/lessworry.id/',
        'website_url'              => 'https://lessworry.id/',
        'gmaps_url'                => 'https://maps.app.goo.gl/hyHayqtwyA2wBLK57',
        'whatsapp_business_active' => true,
        'tiktok_url'               => 'https://www.tiktok.com/@daily.lessworry',
        'outlet_photo_paths'       => [],
    ],
    'status'     => BrandAudit::STATUS_PENDING,
    'expires_at' => now()->addDays(30),
]);

echo "Audit ID: {$audit->id}\n\n";

// ── Run AnalyzeBrand (sync — Bus::batch fires AggregateAuditJob inline) ───────
echo "Running AnalyzeBrand...\n";
(new AnalyzeBrand($audit->id))->handle();

// ── Reload final state ────────────────────────────────────────────────────────
$audit->refresh();

// ── Results dump ──────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  BRAND_AUDITS ROW DUMP                                           ║\n";
echo "╠══════════════════════════════════════════════════════════════════╣\n";
printf("║  id           : %-48s ║\n", $audit->id);
printf("║  brand_name   : %-48s ║\n", $audit->brand_name);
printf("║  status       : %-48s ║\n", $audit->status);
printf("║  overall_score: %-48s ║\n", ($audit->overall_score ?? '–') . '/100');
printf("║  overall_label: %-48s ║\n", $audit->overall_label ?? '–');

if ($audit->error_message) {
    printf("║  error_message: %-48s ║\n", mb_substr((string) $audit->error_message, 0, 48));
}

echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  PILLAR SCORES                                                   ║\n";

foreach ((array) $audit->pillar_scores as $slug => $data) {
    if (isset($data['error'])) {
        printf("║  %-28s : ERROR — %s\n", $slug, mb_substr((string) $data['error'], 0, 30));
    } else {
        printf("║  %-28s : %d/100\n", $slug, (int) ($data['score'] ?? 0));
    }
}

echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  SUB-BUCKET SCORES                                               ║\n";

foreach ((array) $audit->sub_bucket_scores as $pillar => $buckets) {
    printf("║  %s\n", $pillar);
    foreach ((array) $buckets as $bucket => $val) {
        printf("║    %-28s: %s\n", $bucket, $val);
    }
}

echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  KEY FINDINGS                                                    ║\n";

foreach ((array) $audit->key_findings as $finding) {
    $impact = strtoupper((string) ($finding['impact'] ?? '?'));
    $obs    = mb_substr((string) ($finding['observation'] ?? ''), 0, 58);
    printf("║  [%s] %s\n", $impact, $obs);
}

echo "╠══════════════════════════════════════════════════════════════════╣\n";
echo "║  RECOMMENDATIONS                                                 ║\n";

foreach ((array) $audit->recommendations as $i => $rec) {
    printf("║  %d. [%s] %s (gap=%d)\n",
        $i + 1,
        strtoupper((string) ($rec['priority'] ?? '?')),
        (string) ($rec['title'] ?? '?'),
        (int) ($rec['gap'] ?? 0),
    );
    $body = mb_substr((string) ($rec['body'] ?? ''), 0, 68);
    printf("║     %s\n", $body);
}

echo "╚══════════════════════════════════════════════════════════════════╝\n";
