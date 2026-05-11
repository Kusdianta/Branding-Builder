<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BrandAudit;
use App\Services\InstagramProfileAuditService;
use Illuminate\Console\Command;

/**
 * Phase 7-B smoke: drive InstagramProfileAuditService against an existing
 * brand_audit row by session_token.
 *
 * Bypasses AnalyzeBrand on purpose — runs the IG audit standalone so we
 * see the full payload in real time, with no queue worker required and no
 * pillar-batch noise in the output.
 *
 * Usage:
 *   php artisan smoke:phase7b {session_token}
 */
class SmokePhase7BCommand extends Command
{
    /** @var string */
    protected $signature = 'smoke:phase7b
        {session_token : The brand_audits.session_token to drive}
        {--truncate=200 : Max chars per string in the JSON dump (0 = no truncation)}';

    /** @var string */
    protected $description = 'Phase 7-B smoke: run InstagramProfileAuditService against an existing brand_audit by session_token.';

    public function handle(InstagramProfileAuditService $service): int
    {
        $token  = (string) $this->argument('session_token');
        $cap    = (int) $this->option('truncate');

        $audit = BrandAudit::query()->where('session_token', $token)->first();
        if ($audit === null) {
            $this->error("No brand_audit found for session_token={$token}");
            return self::FAILURE;
        }

        $this->line('==============================================');
        $this->line("Phase 7-B smoke");
        $this->line('==============================================');
        $this->line("brand_audit_id    : {$audit->id}");
        $this->line("brand_name        : {$audit->brand_name}");
        $this->line("city              : " . ($audit->city ?: '(not set)'));
        $this->line("service_type      : {$audit->service_type}");
        $this->line("touchpoints       : " . json_encode($audit->touchpoints, JSON_UNESCAPED_SLASHES));
        $this->line("ig_status (before): " . ($audit->instagram_audit_status ?: '(null)'));
        $this->line('');

        $started = microtime(true);
        $this->line('-> Calling InstagramProfileAuditService::audit ...');
        $service->audit($audit);
        $elapsed = round(microtime(true) - $started, 2);

        $audit->refresh();
        $this->line('');
        $this->line('==============================================');
        $this->line("Elapsed             : {$elapsed}s");
        $this->line("instagram_audit_status (after): {$audit->instagram_audit_status}");
        $this->line('==============================================');
        $this->line('');

        $payload = $audit->instagram_audit;
        if ($payload === null) {
            $this->warn('instagram_audit column is NULL (status-only outcome).');
            return self::SUCCESS;
        }

        if ($cap > 0) {
            $payload = $this->truncateStrings($payload, $cap);
        }

        $this->line('instagram_audit (truncated to ' . ($cap > 0 ? $cap . ' chars/string' : 'full') . '):');
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (isset($payload['scorecard']['overall'])) {
            $overall = $payload['scorecard']['overall'];
            $this->line('');
            $this->line('---- scorecard.overall (server-computed) ----');
            $this->line(json_encode($overall, JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    private function truncateStrings(mixed $value, int $cap): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > $cap
                ? mb_substr($value, 0, $cap) . sprintf(' …[+%d chars]', mb_strlen($value) - $cap)
                : $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->truncateStrings($v, $cap);
            }
            return $out;
        }
        return $value;
    }
}
