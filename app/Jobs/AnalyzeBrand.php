<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 10 BB55: 3-phase pipeline orchestrator.
 *
 *   Phase 1: GATHER   (parallel)  — GatherEvidenceJob fans out
 *                                   FetchPlacesApi / FetchGMapsReviews /
 *                                   FetchInstagramAudit. Each writes
 *                                   to audit_evidence; allowFailures
 *                                   so partial evidence is acceptable.
 *
 *   Phase 2: VALIDATE             — ValidateEvidenceJob fuzzy-matches
 *                                   scraped names + city against
 *                                   typed brand_name; LLM tie-breaker
 *                                   via ClaudeService::validateBrandMatch.
 *                                   Flags low-confidence audits with
 *                                   status=validation_warning (still
 *                                   completes — banner in PDF/dash).
 *
 *   Phase 3: SCORE + INSIGHTS     — ScorePillarsJob pulls inputs from
 *                                   EvidenceMapper, scores the 4
 *                                   pillars in turn, applies experience
 *                                   penalties, aggregates. Then
 *                                   GenerateInsightsJob runs the three
 *                                   apikprimadya generators serially
 *                                   and chains GeneratePdfJob.
 *
 * Each phase's job dispatches the next at the end of its handle()
 * (Phase 1 via its inner batch's then() callback). AnalyzeBrand only
 * seeds audit_steps + flips status + kicks off Phase 1.
 *
 * Replaces the Phase 7-B Track A / Track B Bus::batch pattern. Wall
 * time is roughly comparable — gather phase runs the three fetches in
 * parallel; the validate phase adds ~3-5s for the haiku tie-breaker;
 * scoring runs sequentially as before.
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

        // Phase 1 kicks the chain. Phase 1 -> Phase 2 -> Phase 3 are
        // wired job-to-job (GatherEvidenceJob's inner batch then() ->
        // ValidateEvidenceJob -> ScorePillarsJob via its own batch ->
        // GenerateInsightsJob via existing serial dispatch).
        GatherEvidenceJob::dispatch($this->auditId);
    }

    /**
     * Pre-create the new step set so the loading view (BB21) can
     * render the planned 12-step pipeline immediately. The validate
     * step is its own row; the implicit penalty + aggregate phases
     * inside ScorePillarsJob no longer surface as user-visible steps
     * (BB55 removed them — they were noise; the per-pillar score steps
     * already convey progress).
     */
    private function seedAuditSteps(): void
    {
        $steps = [
            // Phase 1 — gather (parallel)
            ['key' => 'gather_places',              'track' => 'gather',   'order' => 1],
            ['key' => 'gather_gmaps',               'track' => 'gather',   'order' => 2],
            ['key' => 'gather_instagram',           'track' => 'gather',   'order' => 3],
            // BB69: IG Claude analysis split out from gather_instagram;
            // currently dispatched synchronously at the end of
            // FetchInstagramAuditJob, will move to a Phase 2 parallel
            // batch in BB71.
            ['key' => 'analyze_instagram',          'track' => 'gather',   'order' => 4],
            // Phase 2 — validate
            ['key' => 'validate_evidence',          'track' => 'validate', 'order' => 5],
            // Phase 3 — score (serial inside ScorePillarsJob)
            ['key' => 'score_recall',               'track' => 'score',    'order' => 6],
            ['key' => 'score_digital',              'track' => 'score',    'order' => 7],
            ['key' => 'score_konsistensi',          'track' => 'score',    'order' => 8],
            ['key' => 'score_experience',           'track' => 'score',    'order' => 9],
            // Phase 3 (continued) — insights + PDF
            ['key' => 'generate_recommendations',   'track' => 'final',    'order' => 10],
            ['key' => 'generate_quick_wins',        'track' => 'final',    'order' => 11],
            ['key' => 'generate_positioning',       'track' => 'final',    'order' => 12],
            ['key' => 'generate_pdf',               'track' => 'final',    'order' => 13],
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
