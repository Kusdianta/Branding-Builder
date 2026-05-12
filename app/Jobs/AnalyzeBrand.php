<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB20: outer orchestrator. Pre-creates audit_steps rows for live UI
 * progress (BB21), flips audit.status='analyzing', dispatches the outer
 * Bus::batch with Track A (ScorePillarsJob — fetch+score+aggregate) +
 * Track B (ScoreInstagramJob — IG scrape+Claude). The ->then() callback
 * fires GeneratePdfJob after BOTH tracks complete.
 *
 * Total wall time: max(Track A ≈ 35s, Track B ≈ 50-75s) + PDF ≈ 3s.
 * Previous sequential design was ~70-90s; the parallelization win is
 * marginal in wall time but substantial in user-facing visibility —
 * BB21's loading view shows per-step progress instead of a generic
 * spinner.
 */
class AnalyzeBrand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public readonly string $auditId) {}

    public function handle(): void
    {
        $audit = BrandAudit::findOrFail($this->auditId);
        $audit->update(['status' => BrandAudit::STATUS_ANALYZING]);

        $this->seedAuditSteps();

        $auditId = $this->auditId;

        Bus::batch([
            new ScorePillarsJob($auditId),
            new ScoreInstagramJob($auditId),
        ])
            ->name("audit:{$auditId}")
            ->allowFailures()
            ->then(static function (Batch $batch) use ($auditId): void {
                GeneratePdfJob::dispatch($auditId);
            })
            ->catch(static function (Batch $batch, Throwable $e) use ($auditId): void {
                Log::error('AnalyzeBrand: outer batch failed', [
                    'audit_id' => $auditId,
                    'error'    => $e->getMessage(),
                ]);
                // Still try to land a PDF so the user gets *something*.
                GeneratePdfJob::dispatch($auditId);
            })
            ->dispatch();
    }

    /**
     * Pre-create the full step set so the loading view (BB21) can render
     * the planned pipeline immediately and transition each row through
     * pending → running → done without race-window flicker.
     */
    private function seedAuditSteps(): void
    {
        $steps = [
            // Track A (pillars) — BB27 inserted fetch_gmaps_reviews
            // between Places-API metadata and pillar scoring, and
            // apply_experience_penalties after the pillar loop.
            ['key' => 'fetch_gmaps',                'track' => 'a',     'order' => 1],
            ['key' => 'fetch_gmaps_reviews',        'track' => 'a',     'order' => 2],
            ['key' => 'score_recall',               'track' => 'a',     'order' => 3],
            ['key' => 'score_digital',              'track' => 'a',     'order' => 4],
            ['key' => 'score_konsistensi',          'track' => 'a',     'order' => 5],
            ['key' => 'score_experience',           'track' => 'a',     'order' => 6],
            ['key' => 'apply_experience_penalties', 'track' => 'a',     'order' => 7],
            ['key' => 'aggregate_pillars',          'track' => 'a',     'order' => 8],
            // Track B (Instagram)
            ['key' => 'ig_scrape',                  'track' => 'b',     'order' => 9],
            ['key' => 'ig_analysis',                'track' => 'b',     'order' => 10],
            // Final
            ['key' => 'generate_pdf',               'track' => 'final', 'order' => 11],
        ];

        foreach ($steps as $s) {
            AuditStep::create([
                'brand_audit_id' => $this->auditId,
                'step_key'       => $s['key'],
                'track'          => $s['track'],
                'status'         => AuditStep::STATUS_PENDING,
                'order'          => $s['order'],
            ]);
        }
    }
}
