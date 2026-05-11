<?php

/**
 * Smoke — Phase 6-partial: regenerate Less Worry audit with the new
 * search_recall sub-bucket + the rebalanced Brand Recall caps.
 *
 * Verifies:
 *   1. AnalyzeBrand → ClaudeService::scoreRecall → SearchRecallScorer wiring
 *   2. sub_bucket_scores['brand-recall'] has 5 keys including search_recall
 *   3. score_breakdown.brand-recall.search_recall structure (formula,
 *      signals, raw_inputs.suggestions, limitations)
 *   4. PDF activation kit regenerates
 *
 * Requires the nema-worker to be reachable at services.nema_worker.url with
 * a valid api_key — without that, search_recall falls back to a zero-result
 * with the upstream failure recorded in each signal's "detail" field.
 *
 * Run: php tests/smoke_phase6_partial.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require_once dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\AnalyzeBrand;
use App\Jobs\GenerateActivationKit;
use App\Models\BrandAudit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

$auditId = $argv[1] ?? '01kr8ya7xq1xr9gyyy2d30nqv7';

$audit = BrandAudit::find($auditId);
if ($audit === null) {
    fwrite(STDERR, "ABORT: audit {$auditId} not found.\n");
    exit(1);
}

echo "Found audit: {$audit->brand_name} (id={$audit->id})\n";
echo "Pre-run overall_score: " . ($audit->overall_score ?? 'null') . "\n";
$preBucket = (array) ($audit->sub_bucket_scores['brand-recall'] ?? []);
echo "Pre-run brand-recall sub-bucket keys: " . implode(', ', array_keys($preBucket)) . "\n\n";

// Reset audit state so AnalyzeBrand runs fresh.
$audit->update([
    'status'              => BrandAudit::STATUS_PENDING,
    'pillar_scores'       => [],
    'sub_bucket_scores'   => [],
    'score_breakdown'     => [],
    'overall_score'       => null,
    'overall_label'       => null,
    'key_findings'        => [],
    'recommendations'     => [],
    'evidence'            => [],
    'error_message'       => null,
    'activation_kit_path' => null,
]);

// Force synchronous queue so Bus::batch executes inline within dispatchSync.
Config::set('queue.default', 'sync');
Config::set('queue.connections.sync', ['driver' => 'sync']);

echo "Dispatching AnalyzeBrand synchronously…\n";
AnalyzeBrand::dispatchSync($audit->id);

$audit->refresh();

echo "\n=== Result ===\n";
echo "Status:        {$audit->status}\n";
echo "Overall score: " . ($audit->overall_score ?? 'null') . "\n";
echo "Overall label: " . ($audit->overall_label ?? 'null') . "\n";

$recallSubBuckets = (array) ($audit->sub_bucket_scores['brand-recall'] ?? []);
echo "\nbrand-recall sub-bucket keys: " . implode(', ', array_keys($recallSubBuckets)) . "\n";
echo "brand-recall sub-bucket scores: " . json_encode($recallSubBuckets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$searchBd = $audit->score_breakdown['brand-recall']['search_recall'] ?? null;
echo "\nscore_breakdown.brand-recall.search_recall:\n";
echo json_encode($searchBd, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$recallPillar = $audit->pillar_scores['brand-recall'] ?? null;
echo "\nbrand-recall pillar total: " . (is_array($recallPillar) ? ($recallPillar['score'] ?? 'null') : 'n/a') . "\n";

echo "\nRegenerating activation kit PDF…\n";
GenerateActivationKit::dispatchSync($audit);
$audit->refresh();

if ($audit->activation_kit_path) {
    $size = Storage::disk('local')->size($audit->activation_kit_path);
    echo "PDF generated: {$audit->activation_kit_path} ({$size} bytes)\n";
} else {
    echo "PDF generation FAILED (activation_kit_path is null)\n";
}

echo "\nDone.\n";
