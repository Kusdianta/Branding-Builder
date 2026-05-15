<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateInsightsJob;
use App\Models\BrandAudit;
use Illuminate\Console\Command;

/**
 * Phase 9.1 BB49: regenerate Phase 9 insight columns
 * (recommendations / quick_wins / competitive_positioning) for audits
 * that finished BEFORE Phase 9 shipped, OR for any audit whose
 * generators failed (and need a manual rerun).
 *
 * Pre-Phase-9 audits have either NULL or legacy-shape recommendations
 * (without priority/effort/impact pills). The PDF
 * pdf/sections/recommendations.blade.php silently drops legacy-shape
 * items at render time, so those audits show empty Recommendations /
 * Quick Wins / Positioning sections in their PDFs until regenerated.
 *
 * Usage:
 *   php artisan audits:regenerate-insights                   (interactive)
 *   php artisan audits:regenerate-insights {audit_id}         (one row)
 *   php artisan audits:regenerate-insights --all              (everything missing)
 *   php artisan audits:regenerate-insights --all --dry-run    (list, don't dispatch)
 *
 * Each successful dispatch chains GeneratePdfJob, so the audit's
 * activation_kit_path file gets re-rendered with the new sections
 * populated automatically (~30-60s per audit for the 3 LLM calls +
 * ~3s for the PDF render).
 */
class RegenerateInsights extends Command
{
    /** @var string */
    protected $signature = 'audits:regenerate-insights
        {audit_id? : Specific audit id; omit to operate on rows missing insights}
        {--all : Process every audit missing insights without prompting}
        {--dry-run : List what would be regenerated without dispatching}';

    /** @var string */
    protected $description = 'Phase 9.1 BB49: regenerate Phase 9 insight columns + PDF for audits missing them.';

    public function handle(): int
    {
        $auditId = $this->argument('audit_id');
        if ($auditId !== null) {
            return $this->regenerateOne((string) $auditId);
        }

        return $this->regenerateMany();
    }

    private function regenerateOne(string $auditId): int
    {
        $audit = BrandAudit::find($auditId);
        if ($audit === null) {
            $this->error("Audit {$auditId} not found.");
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("DRY-RUN: would dispatch GenerateInsightsJob for audit {$auditId} ({$audit->brand_name})");
            return self::SUCCESS;
        }

        $this->dispatchOne($audit);
        return self::SUCCESS;
    }

    private function regenerateMany(): int
    {
        $candidates = $this->findCandidates();
        $count = $candidates->count();

        if ($count === 0) {
            $this->info('No audits missing insights. Nothing to do.');
            return self::SUCCESS;
        }

        $this->line("Found {$count} audit(s) missing Phase 9 insights:");
        foreach ($candidates as $audit) {
            $this->line(sprintf(
                '  - %s  brand=%s  city=%s  status=%s  has_recs=%s',
                $audit->id,
                $audit->brand_name,
                $audit->city ?: '(none)',
                $audit->status,
                $this->hasNewShapeRecs($audit) ? 'yes' : 'no',
            ));
        }

        if ($this->option('dry-run')) {
            $this->line('DRY-RUN: not dispatching any jobs.');
            return self::SUCCESS;
        }

        if (! $this->option('all')) {
            if (! $this->confirm('Dispatch GenerateInsightsJob for all listed audits?', false)) {
                $this->line('Aborted by operator.');
                return self::SUCCESS;
            }
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();
        foreach ($candidates as $audit) {
            $this->dispatchOne($audit, /* quiet */ true);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$count} GenerateInsightsJob(s) to the queue.");
        return self::SUCCESS;
    }

    /**
     * Match audits where any of the three Phase 9 columns is missing
     * its expected shape:
     *   - recommendations is NULL, empty array, OR has only
     *     legacy-shape rows (without `priority` key).
     *   - quick_wins is NULL.
     *   - competitive_positioning is NULL or has empty narrative /
     *     growth_opportunity.
     *
     * Done in PHP after the SQL fetch because SQLite doesn't expose a
     * portable JSON-shape predicate worth the optimisation here. The
     * scan is bounded by audit count and runs in <100ms even at 10K
     * rows.
     *
     * @return \Illuminate\Support\Collection<int, BrandAudit>
     */
    private function findCandidates(): \Illuminate\Support\Collection
    {
        return BrandAudit::query()
            ->where('status', BrandAudit::STATUS_DONE)
            ->orderBy('created_at')
            ->get()
            ->filter(fn (BrandAudit $a) => ! $this->hasFullPhase9Shape($a))
            ->values();
    }

    private function hasFullPhase9Shape(BrandAudit $audit): bool
    {
        return $this->hasNewShapeRecs($audit)
            && $this->hasNonEmptyQuickWins($audit)
            && $this->hasFullPositioning($audit);
    }

    private function hasNewShapeRecs(BrandAudit $audit): bool
    {
        $recs = (array) ($audit->recommendations ?? []);
        if (count($recs) === 0) {
            return false;
        }
        // Sample first row — if it carries `priority`, it's the BB37 shape.
        $first = $recs[0] ?? null;
        return is_array($first) && isset($first['priority'], $first['effort'], $first['impact']);
    }

    private function hasNonEmptyQuickWins(BrandAudit $audit): bool
    {
        $qw = (array) ($audit->quick_wins ?? []);
        return count($qw) > 0;
    }

    private function hasFullPositioning(BrandAudit $audit): bool
    {
        $cp = (array) ($audit->competitive_positioning ?? []);
        return ! empty($cp['narrative']) && ! empty($cp['growth_opportunity']);
    }

    private function dispatchOne(BrandAudit $audit, bool $quiet = false): void
    {
        if (! $quiet) {
            $this->line("Dispatching GenerateInsightsJob for {$audit->id} ({$audit->brand_name})...");
        }
        // Surface to stderr too so operators tailing the queue log can
        // correlate.
        fwrite(STDERR, sprintf("[regenerate-insights] dispatch audit_id=%s brand=%s\n", $audit->id, $audit->brand_name));
        GenerateInsightsJob::dispatch($audit->id);
    }
}
