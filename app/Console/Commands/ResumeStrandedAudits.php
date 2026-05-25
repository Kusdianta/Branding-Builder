<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AnalysisOrchestratorJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\GeneratePdfJob;
use App\Jobs\ScorePillarsJob;
use App\Jobs\ValidateEvidenceJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB148 — safety net for audits stranded at a pipeline phase boundary.
 *
 * The pipeline advances between phases via Bus::batch callbacks that run in
 * the worker and dispatch the next phase's job (gather→analyze→validate→
 * score→insights→pdf). If that dispatch throws — historically a SQLite
 * "database is locked" under worker contention — Batch::invokeHandlerCallback
 * SWALLOWS the throwable, so the next phase is never queued and the audit
 * sits in `analyzing` forever with no jobs to move it.
 *
 * The real fix is config/database.php transaction_mode=IMMEDIATE (which stops
 * the lock-upgrade deadlock that caused those throws). This command is the
 * belt-and-suspenders: it finds an audit that is genuinely stranded (status
 * `analyzing`, untouched for --stale-minutes, sitting at a CLEAN phase
 * boundary) and re-dispatches the entry job of the next phase.
 *
 * Deliberately CONSERVATIVE: it only resumes when the prior phase is fully
 * terminal AND the next phase is fully untouched (all pending). A partial
 * phase (a job died mid-batch) is a different failure mode — it is left for
 * an operator rather than guessed at, so this command can never mis-advance
 * an audit that is still doing work.
 */
class ResumeStrandedAudits extends Command
{
    protected $signature = 'audits:resume-stranded {--stale-minutes=3 : Only touch audits not updated within this many minutes} {--dry-run : Report what would be resumed without dispatching}';

    protected $description = 'Re-dispatch the next phase for audits stranded at a pipeline phase boundary (BB148).';

    /**
     * Phase → ordered step keys, in pipeline order. Mirrors
     * AnalyzeBrand::seedAuditSteps().
     *
     * @var array<string, list<string>>
     */
    private const PHASES = [
        'gather'   => ['gather_places', 'gather_gmaps', 'gather_instagram', 'fetch_website'],
        'analyze'  => ['analyze_instagram', 'extract_service_signals'],
        'validate' => ['validate_evidence'],
        'score'    => ['score_recall', 'score_digital', 'score_konsistensi', 'score_experience'],
        'final'    => ['generate_recommendations', 'generate_quick_wins', 'generate_positioning', 'generate_pdf'],
    ];

    public function handle(): int
    {
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subMinutes($staleMinutes);

        $stranded = BrandAudit::where('status', BrandAudit::STATUS_ANALYZING)
            ->where('updated_at', '<', $cutoff)
            ->get();

        $resumed = 0;
        foreach ($stranded as $audit) {
            $resumePoint = $this->resumePointFor($audit);
            if ($resumePoint === null) {
                continue; // not at a clean boundary — leave it for an operator
            }

            if ($dryRun) {
                $this->line("[dry-run] {$audit->id} would resume at: {$resumePoint}");
                $resumed++;
                continue;
            }

            $this->dispatchResume($audit->id, $resumePoint);
            Log::warning('ResumeStrandedAudits: re-dispatched stranded audit', [
                'audit_id' => $audit->id,
                'resume'   => $resumePoint,
                'stale_since' => (string) $audit->updated_at,
            ]);
            $this->info("Resumed {$audit->id} at: {$resumePoint}");
            $resumed++;
        }

        $this->line("Stranded audits checked: {$stranded->count()} | resumed: {$resumed}");

        return self::SUCCESS;
    }

    /**
     * Determine the clean phase boundary an audit is stranded at, or null when
     * the state is ambiguous (a partial phase, an active step, or nothing to
     * resume). Returns one of: analyze | validate | score | insights | pdf.
     */
    private function resumePointFor(BrandAudit $audit): ?string
    {
        $byKey = AuditStep::where('brand_audit_id', $audit->id)
            ->get()
            ->keyBy('step_key');

        // Any running step means the pipeline is still active — never touch.
        foreach ($byKey as $step) {
            if ($step->status === AuditStep::STATUS_RUNNING) {
                return null;
            }
        }

        $gather   = $this->phaseState($byKey, 'gather');
        $analyze  = $this->phaseState($byKey, 'analyze');
        $validate = $this->phaseState($byKey, 'validate');
        $score    = $this->phaseState($byKey, 'score');

        if ($gather === 'complete' && $analyze === 'untouched') {
            return 'analyze';
        }
        if ($analyze === 'complete' && $validate === 'untouched') {
            return 'validate';
        }
        if ($validate === 'complete' && $score === 'untouched') {
            return 'score';
        }

        // score → final boundary, split into the two jobs that own the final
        // track: GenerateInsightsJob (recommendations/quick_wins/positioning)
        // then GeneratePdfJob.
        if ($score === 'complete') {
            $insightsDone = $this->allTerminal($byKey, ['generate_recommendations', 'generate_quick_wins', 'generate_positioning']);
            $pdfPending = ($byKey['generate_pdf']->status ?? null) === AuditStep::STATUS_PENDING;

            if ($this->phaseState($byKey, 'final') === 'untouched') {
                return 'insights';
            }
            if ($insightsDone && $pdfPending) {
                return 'pdf';
            }
        }

        return null;
    }

    /**
     * 'complete'  — every step in the phase is terminal (done/failed).
     * 'untouched' — every step in the phase is still pending.
     * 'partial'   — a mix (don't auto-resume across a partial phase).
     */
    private function phaseState(\Illuminate\Support\Collection $byKey, string $phase): string
    {
        $keys = self::PHASES[$phase];
        $terminal = 0;
        $pending = 0;
        foreach ($keys as $key) {
            $status = $byKey[$key]->status ?? null;
            if ($status === null) {
                return 'partial'; // missing step row — treat as ambiguous
            }
            if (in_array($status, [AuditStep::STATUS_DONE, AuditStep::STATUS_FAILED], true)) {
                $terminal++;
            } elseif ($status === AuditStep::STATUS_PENDING) {
                $pending++;
            }
        }

        $total = count($keys);
        if ($terminal === $total) {
            return 'complete';
        }
        if ($pending === $total) {
            return 'untouched';
        }

        return 'partial';
    }

    /** @param list<string> $keys */
    private function allTerminal(\Illuminate\Support\Collection $byKey, array $keys): bool
    {
        foreach ($keys as $key) {
            $status = $byKey[$key]->status ?? null;
            if (! in_array($status, [AuditStep::STATUS_DONE, AuditStep::STATUS_FAILED], true)) {
                return false;
            }
        }

        return true;
    }

    private function dispatchResume(string $auditId, string $resumePoint): void
    {
        switch ($resumePoint) {
            case 'analyze':
                AnalysisOrchestratorJob::dispatch($auditId);
                break;

            case 'validate':
                ValidateEvidenceJob::dispatch($auditId);
                break;

            case 'score':
                // Replicate ValidateEvidenceJob::chainScorePhase() so scoring
                // chains GenerateInsightsJob (which chains the PDF) on completion.
                Bus::batch([new ScorePillarsJob($auditId)])
                    ->name("audit:{$auditId}:resume-score")
                    ->then(static function (Batch $batch) use ($auditId): void {
                        GenerateInsightsJob::dispatch($auditId);
                    })
                    ->catch(static function (Batch $batch, Throwable $e) use ($auditId): void {
                        GenerateInsightsJob::dispatch($auditId);
                    })
                    ->dispatch();
                break;

            case 'insights':
                GenerateInsightsJob::dispatch($auditId);
                break;

            case 'pdf':
                GeneratePdfJob::dispatch($auditId);
                break;
        }
    }
}
