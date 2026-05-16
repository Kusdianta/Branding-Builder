<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\Scoring\ServiceSignalsExtractor;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB74 — Phase 11 Phase-2 analysis job.
 *
 * Runs ServiceSignalsExtractor (Stage 1 pure-PHP keyword scan + Stage 2
 * batched Claude verification for ambiguous-band signals) and persists
 * the result to audit_evidence.analysis.service_signals.
 *
 * Consumed downstream by BB75 ExperienceScorer's tier classifier:
 *   Tier A (declared + verified)      → 100% bonus
 *   Tier B (detected only)            →  80% bonus
 *   Tier C (declared, no signals)     →  67% bonus, capped
 *   Tier D (neither)                  →   0
 *
 * Wired into the AnalyzeBrand pipeline in BB71 (Phase 2 parallel batch
 * alongside AnalyzeInstagramJob + AnalyzeVisualConsistencyJob). For
 * now the job exists but isn't called by the orchestrator.
 *
 * Never throws: ServiceSignalsExtractor's contract is never-raise.
 * Stage 2 LLM failure leaves Stage 1 scores intact.
 */
class ExtractServiceSignalsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Stage 1 is instant; Stage 2 LLM call is ~3-5s. Headroom for queue jitter. */
    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(ServiceSignalsExtractor $extractor): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('extract_service_signals');
        $step?->markRunning();

        try {
            $audit = BrandAudit::findOrFail($this->auditId);
            $evidence = (array) ($audit->audit_evidence ?? []);
            $decls    = $audit->operator_declarations;

            $signals = $extractor->extract($evidence, is_array($decls) ? $decls : null);
            $this->writeAnalysisSlice($signals);

            $detail = [
                'detected' => $this->summarizeDetected($signals),
                'verified_by_llm_count' => $this->countLlmVerified($signals),
            ];
            $step?->markDone($detail);
        } catch (Throwable $e) {
            Log::warning('ExtractServiceSignalsJob: extraction failed', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
            // Persist an empty payload so downstream scorers can detect
            // the absence without re-running the extractor.
            $this->writeAnalysisSlice([]);
            $step?->markFailed($e->getMessage());
        }
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    private function writeAnalysisSlice(array $signals): void
    {
        DB::transaction(function () use ($signals): void {
            $audit = BrandAudit::findOrFail($this->auditId);
            $evidence = (array) ($audit->audit_evidence ?? []);
            $analysis = (array) ($evidence['analysis'] ?? []);
            $analysis['service_signals'] = $signals;
            $evidence['analysis'] = $analysis;
            $audit->update(['audit_evidence' => $evidence]);
        });
    }

    /** @return list<string> */
    private function summarizeDetected(array $signals): array
    {
        $out = [];
        foreach ($signals as $key => $signal) {
            if ($key === 'variasi_layanan') {
                if (! empty($signal['detected_variants'])) {
                    $out[] = "variasi_layanan:" . count($signal['detected_variants']);
                }
                continue;
            }
            if (is_array($signal) && ($signal['detected'] ?? false)) {
                $out[] = (string) $key;
            }
        }
        return $out;
    }

    private function countLlmVerified(array $signals): int
    {
        $count = 0;
        foreach ($signals as $signal) {
            if (is_array($signal) && ($signal['verified_by_llm'] ?? false)) {
                $count++;
            }
        }
        return $count;
    }
}
