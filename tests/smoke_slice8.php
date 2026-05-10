<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\GenerateActivationKit;
use App\Models\BrandAudit;
use Illuminate\Support\Facades\Storage;

$auditId = '01kr8ya7xq1xr9gyyy2d30nqv7'; // Less Worry Laundry test row

echo "Slice 8 smoke test — generate activation kit\n";
echo "===========================================\n\n";

$audit = BrandAudit::find($auditId);
if (! $audit) {
    fwrite(STDERR, "FAIL: audit $auditId not found\n");
    exit(1);
}

echo "Audit:           {$audit->id}\n";
echo "Brand:           {$audit->brand_name}\n";
echo "Status:          {$audit->status}\n";
echo "Overall:         " . ($audit->overall_score ?? 'null') . "\n";
echo "kit_path before: " . ($audit->activation_kit_path ?? 'null') . "\n";

if ($audit->activation_kit_path) {
    Storage::disk('local')->delete($audit->activation_kit_path);
    $audit->update(['activation_kit_path' => null]);
    $audit->refresh();
    echo "  -> cleared previous activation_kit_path for clean run\n";
}

echo "\nDispatching GenerateActivationKit synchronously...\n";
$start = microtime(true);
(new GenerateActivationKit($audit))->handle();
$elapsed = round(microtime(true) - $start, 2);
echo "Elapsed: {$elapsed}s\n\n";

$audit->refresh();
echo "kit_path after:  " . ($audit->activation_kit_path ?? 'null') . "\n";

if (! $audit->activation_kit_path) {
    fwrite(STDERR, "FAIL: activation_kit_path is still null after handle()\n");
    fwrite(STDERR, "Check storage/logs/laravel.log for the GenerateActivationKit failure log.\n");
    exit(1);
}

if (! Storage::disk('local')->exists($audit->activation_kit_path)) {
    fwrite(STDERR, "FAIL: file does not exist on disk at {$audit->activation_kit_path}\n");
    exit(1);
}

$absPath = Storage::disk('local')->path($audit->activation_kit_path);
$bytes   = filesize($absPath);
$kb      = round($bytes / 1024, 1);
$mb      = round($bytes / 1024 / 1024, 2);

echo "PDF written:     {$absPath}\n";
echo "Size:            {$bytes} bytes ({$kb} KB / {$mb} MB)\n";

$head = file_get_contents($absPath, false, null, 0, 8);
$isPdf = str_starts_with($head, '%PDF-');
echo "Magic bytes:     " . bin2hex($head) . " " . ($isPdf ? '(valid %PDF- header)' : '(NOT a PDF)') . "\n";

if (! $isPdf) {
    fwrite(STDERR, "FAIL: file does not start with %PDF- magic\n");
    exit(1);
}

if ($bytes > 2 * 1024 * 1024) {
    fwrite(STDERR, "WARN: PDF is over 2 MB ({$mb} MB). Spec target was < 2 MB.\n");
}

echo "\nResult: PASS\n";
echo "Open it manually to inspect:\n";
echo "  start \"\" \"$absPath\"\n";
