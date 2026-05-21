<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use App\Services\ClaudeService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScorePillarJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    /**
     * BB133 — retry persist on SQLite lock contention. Belt to the
     * config/database.php busy_timeout suspenders: if the writer queue
     * is still backed up after 10s, fall back to manual retries with
     * a short jittered backoff before failing the pillar.
     */
    private const PERSIST_MAX_ATTEMPTS = 4;
    private const PERSIST_BASE_BACKOFF_MS = 150;

    /**
     * @param array<string,mixed> $fetcherData  pre-fetched touchpoint inputs for this pillar
     */
    public function __construct(
        public readonly string $auditId,
        public readonly string $pillarSlug,
        public readonly array $fetcherData,
    ) {}

    public function handle(ClaudeService $claude): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // BB66: tag the api_usage_log rows from this Claude call with
        // the audit id for per-audit cost rollups in the Hub dashboard.
        $claude->setAuditContext($this->auditId);

        try {
            $score = $claude->scorePillar($this->pillarSlug, $this->fetcherData);

            $this->persistPillarScoreWithRetry($score);
        } catch (\Throwable $e) {
            Log::error('ScorePillarJob failed', [
                'audit_id'    => $this->auditId,
                'pillar_slug' => $this->pillarSlug,
                'error'       => $e->getMessage(),
            ]);

            $this->persistPillarErrorWithRetry($e->getMessage());
        }
    }

    /**
     * BB133 — persist pillar score with retry on SQLite lock errors.
     * On Windows + Herd the brand_audits row sees contention between
     * GatherEvidenceJob's evidence mirror, the queue worker's
     * reservation update, and this job's pillar persist. busy_timeout
     * handles the common case; this retry catches the rare runs that
     * exceed it instead of dropping the pillar score entirely.
     */
    private function persistPillarScoreWithRetry(\App\DTO\PillarScore $score): void
    {
        $this->retryOnLock(function () use ($score): void {
            DB::transaction(function () use ($score): void {
                $audit = BrandAudit::findOrFail($this->auditId);

                $pillarScores = $audit->pillar_scores ?? [];
                $pillarScores[$this->pillarSlug] = $score->toArray();

                $subBuckets = $audit->sub_bucket_scores ?? [];
                $subBuckets[$this->pillarSlug] = $score->subBucketScores;

                $scoreBreakdown = $audit->score_breakdown ?? [];
                $scoreBreakdown[$this->pillarSlug] = $score->scoreBreakdown;

                $audit->update([
                    'pillar_scores'     => $pillarScores,
                    'sub_bucket_scores' => $subBuckets,
                    'score_breakdown'   => $scoreBreakdown,
                ]);
            });
        });
    }

    private function persistPillarErrorWithRetry(string $message): void
    {
        try {
            $this->retryOnLock(function () use ($message): void {
                DB::transaction(function () use ($message): void {
                    $audit = BrandAudit::find($this->auditId);
                    if ($audit === null) {
                        return;
                    }

                    $pillarScores = $audit->pillar_scores ?? [];
                    $pillarScores[$this->pillarSlug] = ['error' => $message];
                    $audit->update(['pillar_scores' => $pillarScores]);
                });
            });
        } catch (\Throwable $persistError) {
            Log::error('ScorePillarJob: failed to even persist pillar error', [
                'audit_id'    => $this->auditId,
                'pillar_slug' => $this->pillarSlug,
                'inner_error' => $message,
                'outer_error' => $persistError->getMessage(),
            ]);
        }
    }

    /**
     * Run $callback up to PERSIST_MAX_ATTEMPTS times. Re-throw only when
     * the error is not a SQLite lock or attempts are exhausted. Lock
     * errors surface as QueryException with SQLSTATE HY000 and the
     * literal "database is locked" / "database table is locked" substring.
     */
    private function retryOnLock(callable $callback): void
    {
        $attempt = 0;
        while (true) {
            try {
                $callback();
                return;
            } catch (QueryException $e) {
                $attempt++;
                if (! $this->isLockError($e) || $attempt >= self::PERSIST_MAX_ATTEMPTS) {
                    throw $e;
                }
                $backoffMs = self::PERSIST_BASE_BACKOFF_MS * (2 ** ($attempt - 1));
                $jitterMs  = random_int(0, $backoffMs);
                Log::info('ScorePillarJob: SQLite lock — retrying persist', [
                    'audit_id'    => $this->auditId,
                    'pillar_slug' => $this->pillarSlug,
                    'attempt'     => $attempt,
                    'backoff_ms'  => $backoffMs + $jitterMs,
                ]);
                usleep(($backoffMs + $jitterMs) * 1000);
            }
        }
    }

    private function isLockError(QueryException $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked');
    }
}
