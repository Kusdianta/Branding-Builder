<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AnalysisOrchestratorJob;
use App\Jobs\AnalyzeInstagramJob;
use App\Jobs\ExtractServiceSignalsJob;
use App\Models\BrandAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BB71 — AnalysisOrchestratorJob (Phase 2) coverage. Verifies the
 * batch dispatch shape: parallel AnalyzeInstagram + ExtractServiceSignals
 * with allowFailures + named batch label.
 */
class AnalysisOrchestratorJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeAudit(): BrandAudit
    {
        return BrandAudit::create([
            'session_token' => Str::random(64),
            'ip_address'    => '127.0.0.1',
            'brand_name'    => 'Less Worry',
            'city'          => 'Bandung',
            'service_type'  => 'kiloan',
            'touchpoints'   => ['instagram_url' => 'https://instagram.com/x'],
            'status'        => BrandAudit::STATUS_ANALYZING,
            'expires_at'    => now()->addDays(30),
        ]);
    }

    #[Test]
    public function it_dispatches_two_analysis_jobs_as_inner_batch_with_allow_failures(): void
    {
        Bus::fake();
        $audit = $this->makeAudit();

        (new AnalysisOrchestratorJob($audit->id))->handle();

        Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) use ($audit): bool {
            $classes = collect($batch->jobs->all())->map(fn ($j) => get_class($j))->all();
            return $batch->name === "audit:{$audit->id}:analyze"
                && in_array(AnalyzeInstagramJob::class, $classes, true)
                && in_array(ExtractServiceSignalsJob::class, $classes, true)
                && $batch->options['allowFailures'] === true;
        });
    }

    #[Test]
    public function it_aborts_silently_when_audit_no_longer_exists(): void
    {
        Bus::fake();
        // Non-existent audit id — defence-in-depth path.
        (new AnalysisOrchestratorJob('non-existent-ulid-x'))->handle();
        Bus::assertNothingBatched();
    }
}
