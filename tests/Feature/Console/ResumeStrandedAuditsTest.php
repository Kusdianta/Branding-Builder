<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\AnalysisOrchestratorJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\GeneratePdfJob;
use App\Jobs\ScorePillarsJob;
use App\Jobs\ValidateEvidenceJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB148 — ResumeStrandedAudits reconciler coverage. Locks the conservative
 * contract: resume ONLY a stale `analyzing` audit sitting at a clean phase
 * boundary, and never touch an active / partial / fresh / finished audit.
 */
class ResumeStrandedAuditsTest extends TestCase
{
    use RefreshDatabase;

    /** key => [track, order] — mirrors AnalyzeBrand::seedAuditSteps(). */
    private const STEPS = [
        'gather_places'            => ['gather', 1],
        'gather_gmaps'             => ['gather', 2],
        'gather_instagram'         => ['gather', 3],
        'fetch_website'            => ['gather', 4],
        'analyze_instagram'        => ['analyze', 5],
        'extract_service_signals'  => ['analyze', 6],
        'validate_evidence'        => ['validate', 7],
        'score_recall'             => ['score', 8],
        'score_digital'            => ['score', 9],
        'score_konsistensi'        => ['score', 10],
        'score_experience'         => ['score', 11],
        'generate_recommendations' => ['final', 12],
        'generate_quick_wins'      => ['final', 13],
        'generate_positioning'     => ['final', 14],
        'generate_pdf'             => ['final', 15],
    ];

    /**
     * Seed an analyzing audit whose steps up to (and including) $doneThrough
     * order are 'done' and the rest 'pending'. Stale unless $stale=false.
     */
    private function makeStranded(int $doneThrough, bool $stale = true, string $status = BrandAudit::STATUS_ANALYZING): BrandAudit
    {
        $audit = BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry Laundry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['instagram_url' => 'https://instagram.com/x'],
            'status'        => $status,
            'expires_at'    => now()->addDays(30),
        ]);

        foreach (self::STEPS as $key => [$track, $order]) {
            AuditStep::create([
                'brand_audit_id' => $audit->id,
                'step_key'       => $key,
                'track'          => $track,
                'order'          => $order,
                'status'         => $order <= $doneThrough ? AuditStep::STATUS_DONE : AuditStep::STATUS_PENDING,
            ]);
        }

        if ($stale) {
            DB::table('brand_audits')->where('id', $audit->id)->update(['updated_at' => now()->subMinutes(10)]);
        }

        return $audit;
    }

    private function runReconciler(): void
    {
        $this->artisan('audits:resume-stranded')->assertSuccessful();
    }

    #[Test]
    public function resumes_analyze_when_gather_complete(): void
    {
        Bus::fake();
        $this->makeStranded(4); // gather done, analyze+ pending
        $this->runReconciler();
        Bus::assertDispatched(AnalysisOrchestratorJob::class);
    }

    #[Test]
    public function resumes_validate_when_analyze_complete(): void
    {
        Bus::fake();
        $audit = $this->makeStranded(6); // gather+analyze done, validate pending
        $this->runReconciler();
        Bus::assertDispatched(ValidateEvidenceJob::class, fn ($j) => $j->auditId === $audit->id);
    }

    #[Test]
    public function resumes_score_batch_when_validate_complete(): void
    {
        Bus::fake();
        $this->makeStranded(7); // through validate done, score pending
        $this->runReconciler();
        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch): bool {
            return collect($batch->jobs->all())->contains(fn ($j) => $j instanceof ScorePillarsJob);
        });
    }

    #[Test]
    public function resumes_insights_when_score_complete(): void
    {
        Bus::fake();
        $this->makeStranded(11); // through score done, final pending
        $this->runReconciler();
        Bus::assertDispatched(GenerateInsightsJob::class);
    }

    #[Test]
    public function resumes_pdf_when_insights_done_but_pdf_pending(): void
    {
        Bus::fake();
        $this->makeStranded(14); // through generate_positioning done, pdf pending
        $this->runReconciler();
        Bus::assertDispatched(GeneratePdfJob::class);
    }

    #[Test]
    public function ignores_a_fresh_audit_within_the_stale_window(): void
    {
        Bus::fake();
        $this->makeStranded(6, stale: false); // analyze done but updated_at is recent
        $this->runReconciler();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function ignores_a_partial_phase(): void
    {
        Bus::fake();
        // gather done, analyze partial (instagram done, service_signals pending) → ambiguous
        $audit = $this->makeStranded(5);
        $this->runReconciler();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function ignores_an_audit_with_a_running_step(): void
    {
        Bus::fake();
        $audit = $this->makeStranded(6);
        AuditStep::where('brand_audit_id', $audit->id)
            ->where('step_key', 'validate_evidence')
            ->update(['status' => AuditStep::STATUS_RUNNING]);
        $this->runReconciler();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function ignores_a_non_analyzing_audit(): void
    {
        Bus::fake();
        $this->makeStranded(6, status: BrandAudit::STATUS_DONE);
        $this->runReconciler();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function dry_run_dispatches_nothing(): void
    {
        Bus::fake();
        $this->makeStranded(6);
        $this->artisan('audits:resume-stranded --dry-run')->assertSuccessful();
        Bus::assertNothingDispatched();
    }
}
