<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AnalyzeBrand;
use App\Models\BrandAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Phase 9 BB44 smoke: dispatch a fresh end-to-end audit through the
 * full Track A + Track B + GenerateInsightsJob + GeneratePdfJob
 * pipeline, then poll for completion and report what landed in the
 * three Phase 9 columns (recommendations / quick_wins /
 * competitive_positioning) plus the activation_kit_path.
 *
 * Bypasses the Livewire wizard so the smoke is reproducible from the
 * CLI. Mirrors the wizard's submit() touchpoint construction. Default
 * fixture matches the Less Worry Laundry case used through W7-W8 so
 * we have a stable comparison point session over session.
 *
 * Pre-conditions:
 *   1. Worker (workers/nema-worker) running on the configured base URL.
 *   2. Hub (nema-hub) has at least one healthy gmaps + instagram
 *      WorkerCredential row.
 *   3. Queue worker (php artisan queue:work) is consuming the
 *      database queue — the AnalyzeBrand batch + GenerateInsightsJob
 *      + GeneratePdfJob all dispatch via the queue.
 *   4. Anthropic API key configured in .env (services.anthropic.key)
 *      so the 3 generators can call Claude.
 *
 * Usage:
 *   php artisan smoke:phase9
 *   php artisan smoke:phase9 --brand="Other Brand" --gmaps=https://maps.app.goo.gl/...
 *   php artisan smoke:phase9 --no-poll  # dispatch then exit; check manually
 */
class SmokePhase9Command extends Command
{
    /** @var string */
    protected $signature = 'smoke:phase9
        {--brand=Less Worry Laundry : Brand name}
        {--city=Bandung : City}
        {--ig=https://www.instagram.com/lessworry.id/ : Instagram URL}
        {--gmaps=https://maps.app.goo.gl/hyHayqtwyA2wBLK57 : Google Maps URL}
        {--no-poll : Dispatch and exit without polling for completion}
        {--poll-seconds=420 : Max seconds to wait for completion}';

    /** @var string */
    protected $description = 'Phase 9 smoke: dispatch end-to-end audit, verify recommendations + quick_wins + competitive_positioning + PDF land.';

    public function handle(): int
    {
        $brand     = (string) $this->option('brand');
        $city      = (string) $this->option('city');
        $igUrl     = (string) $this->option('ig');
        $gmapsUrl  = (string) $this->option('gmaps');
        $maxPoll   = (int) $this->option('poll-seconds');

        $token = Str::random(64);
        $audit = BrandAudit::create([
            'session_token' => $token,
            'ip_address'    => '127.0.0.1',
            'brand_name'    => $brand,
            'city'          => $city,
            'service_type'  => 'kiloan',
            'touchpoints'   => [
                'instagram_url'            => $igUrl,
                'website_url'              => null,
                'tiktok_url'               => null,
                'gmaps_url'                => $gmapsUrl,
                'whatsapp_business_active' => false,
                'outlet_photo_paths'       => [],
                'outlet_photo_outer_paths' => [],
                'outlet_photo_inner_paths' => [],
            ],
            'status'        => BrandAudit::STATUS_PENDING,
            'expires_at'    => now()->addDays(30),
        ]);

        $this->info("Dispatched audit_id={$audit->id} token={$token}");
        AnalyzeBrand::dispatch($audit->id);

        if ($this->option('no-poll')) {
            $this->line('--no-poll set; exiting. Check status with `php artisan tinker` or the dashboard.');
            return self::SUCCESS;
        }

        $this->line("Polling up to {$maxPoll}s for status='done'...");
        $started = time();
        while (time() - $started < $maxPoll) {
            sleep(15);
            $audit->refresh();
            $elapsed = time() - $started;
            $this->line(sprintf('  t=%ds  status=%s', $elapsed, $audit->status));
            if ($audit->status === BrandAudit::STATUS_DONE) {
                break;
            }
            if ($audit->status === BrandAudit::STATUS_FAILED) {
                $this->error('Audit failed.');
                return self::FAILURE;
            }
        }

        if ($audit->status !== BrandAudit::STATUS_DONE) {
            $this->error("Timed out waiting for status='done' after {$maxPoll}s. Audit still in '{$audit->status}'.");
            return self::FAILURE;
        }

        $this->info('=== Phase 9 artefacts ===');
        $recs = (array) ($audit->recommendations ?? []);
        $this->line(sprintf('recommendations: %d items', count($recs)));
        foreach (array_slice($recs, 0, 3) as $r) {
            if (! is_array($r)) continue;
            $this->line(sprintf(
                '  #%s  [%s/%s/%s]  %s',
                (string) ($r['rank'] ?? '?'),
                (string) ($r['priority'] ?? '?'),
                (string) ($r['effort'] ?? '?'),
                (string) ($r['impact'] ?? '?'),
                (string) ($r['title'] ?? '(no title)'),
            ));
        }
        $qw = (array) ($audit->quick_wins ?? []);
        $this->line(sprintf('quick_wins: %d items', count($qw)));
        foreach (array_slice($qw, 0, 3) as $w) {
            if (! is_array($w)) continue;
            $this->line(sprintf('  [%dm] %s', (int) ($w['estimated_minutes'] ?? 0), (string) ($w['action'] ?? '(no action)')));
        }
        $cp = (array) ($audit->competitive_positioning ?? []);
        $this->line(sprintf('competitive_positioning narrative: %d chars', mb_strlen((string) ($cp['narrative'] ?? ''))));
        $this->line(sprintf('competitive_positioning growth_opportunity: %d chars', mb_strlen((string) ($cp['growth_opportunity'] ?? ''))));
        $this->line('activation_kit_path: ' . ($audit->activation_kit_path ?? '(missing)'));

        $okRecs    = count($recs) >= 5;
        $okWins    = count($qw)   >= 5;
        $okCp      = ! empty($cp['narrative']) && ! empty($cp['growth_opportunity']);
        $okPdf     = ! empty($audit->activation_kit_path);

        if ($okRecs && $okWins && $okCp && $okPdf) {
            $this->info('SMOKE PASS — all four Phase 9 outputs present.');
            return self::SUCCESS;
        }
        $this->warn(sprintf(
            'SMOKE PARTIAL — recs=%s wins=%s positioning=%s pdf=%s',
            $okRecs ? 'OK' : 'MISSING',
            $okWins ? 'OK' : 'MISSING',
            $okCp   ? 'OK' : 'MISSING',
            $okPdf  ? 'OK' : 'MISSING',
        ));
        return self::FAILURE;
    }
}
