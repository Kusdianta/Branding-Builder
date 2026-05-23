<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use App\Services\CreditLedger;
use App\Services\Recommendation\TargetScoreReasoningGenerator;
use App\Services\TargetScoreCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AggregateAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(CreditLedger $ledger): void
    {
        $audit = BrandAudit::findOrFail($this->auditId);
        $pillarScores = (array) ($audit->pillar_scores ?? []);

        // ── Tally failures ────────────────────────────────────────────────────
        $failedPillars   = [];
        $succeededScores = [];

        foreach ($pillarScores as $slug => $data) {
            if (isset($data['error'])) {
                $failedPillars[] = $slug;
            } else {
                $succeededScores[$slug] = $data;
            }
        }

        if (count($failedPillars) >= 2) {
            $audit->update([
                'status'        => BrandAudit::STATUS_FAILED,
                'error_message' => 'Gagal menskoring pilar: ' . implode(', ', $failedPillars),
            ]);

            // BB82: refund the credit so a hard-failed audit does not cost
            // the user anything. CreditLedger::refund() is idempotent and
            // a no-op for anonymous (pre-Phase-12a) audits with user_id null.
            $ledger->refund($audit->fresh());

            return;
        }

        try {
            $overallScore    = $this->computeOverall($succeededScores);
            $overallLabel    = $this->applyLabel($overallScore);
            $keyFindings     = $this->buildKeyFindings($succeededScores);
            $recommendations = $this->buildRecommendations(
                $succeededScores,
                (array) ($audit->sub_bucket_scores ?? []),
                (string) $audit->brand_name,
            );

            $errorMessage = count($failedPillars) === 1
                ? 'Skor pilar ' . $failedPillars[0] . ' gagal diambil — hasil mungkin tidak lengkap.'
                : null;

            // BB145 — DO NOT flip status to DONE here. Aggregation is a
            // mid-pipeline step: GenerateInsightsJob (LLM recommendations,
            // quick wins, positioning) and GeneratePdfJob still run after
            // it. The wizard reveals the full dashboard the instant
            // status === 'done', so flipping it here surfaced an
            // incomplete preview while insights + PDF were still
            // generating. GeneratePdfJob — the guaranteed final step — is
            // now the SOLE setter of STATUS_DONE. The audit stays in its
            // current status (analyzing / validation_warning) until then,
            // so the loading view keeps showing live step progress.
            $audit->update([
                'overall_score'   => $overallScore,
                'overall_label'   => $overallLabel,
                'key_findings'    => $keyFindings,
                'recommendations' => $recommendations,
                'error_message'   => $errorMessage,
            ]);

            // Phase 12c.4 FIX E — generate the target-skor reasoning
            // paragraph and persist into audit_evidence. Fire-and-
            // forget: an LLM failure never blocks the audit's DONE
            // flip. The view degrades to "no reasoning shown" when
            // the field is absent.
            $this->generateTargetScoreReasoning($audit->fresh(), $overallScore);
        } catch (\Throwable $e) {
            Log::error('AggregateAuditJob failed', [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);

            $audit->update([
                'status'        => BrandAudit::STATUS_FAILED,
                'error_message' => 'Agregasi gagal: ' . $e->getMessage(),
            ]);

            // BB82: terminal failure → refund.
            $ledger->refund($audit->fresh());
        }
    }

    // ── Overall score ─────────────────────────────────────────────────────────

    /** @param array<string,array<string,mixed>> $scores */
    private function computeOverall(array $scores): int
    {
        $weights = (array) config('branding.pillar_weights');
        $total   = 0.0;

        foreach ($weights as $slug => $weight) {
            $score  = isset($scores[$slug]) ? (int) ($scores[$slug]['score'] ?? 0) : 0;
            $total += $score * (float) $weight;
        }

        return (int) round($total);
    }

    private function applyLabel(int $score): string
    {
        foreach ((array) config('branding.label_thresholds') as $threshold => $label) {
            if ($score >= (int) $threshold) {
                return (string) $label;
            }
        }

        return 'CRITICAL — Brand Belum Terbangun';
    }

    // ── Key findings ──────────────────────────────────────────────────────────

    /**
     * Top 2 positive + top 2 negative evidence items across all pillars.
     *
     * @param  array<string,array<string,mixed>>  $scores
     * @return list<array<string,string>>
     */
    private function buildKeyFindings(array $scores): array
    {
        $positive = [];
        $negative = [];

        foreach ($scores as $pillarData) {
            foreach ((array) ($pillarData['evidence'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $impact = (string) ($item['impact'] ?? 'neutral');

                if ($impact === 'positive') {
                    $positive[] = $item;
                } elseif ($impact === 'negative') {
                    $negative[] = $item;
                }
            }
        }

        return array_values(array_merge(
            array_slice($positive, 0, 2),
            array_slice($negative, 0, 2),
        ));
    }

    // ── Recommendations ───────────────────────────────────────────────────────

    /**
     * 3 recommendations for the lowest-scoring improvable sub-buckets.
     *
     * Uses sub_bucket_scores already stored per-pillar on the audit row (written
     * by each ScorePillarJob) so we don't need to re-parse pillar_scores.
     *
     * @param  array<string,array<string,mixed>>  $scores
     * @param  array<string,array<string,mixed>>  $subBucketScores  keyed by pillar_slug
     * @return list<array<string,mixed>>
     */
    private function buildRecommendations(array $scores, array $subBucketScores, string $brandName): array
    {
        $subBucketDefs = (array) config('branding.pillar_sub_buckets');
        $recTemplates  = (array) config('branding-recommendations', []);

        $gaps = [];

        foreach ($subBucketScores as $pillarSlug => $buckets) {
            $defs = (array) ($subBucketDefs[$pillarSlug] ?? []);

            foreach ((array) $buckets as $bucketKey => $actualScore) {
                $def  = $defs[$bucketKey] ?? null;
                $type = is_array($def) ? (string) ($def['type'] ?? '') : '';
                $cap  = is_array($def) ? (int) ($def['cap'] ?? 0) : 0;

                if (in_array($type, ['base', 'penalty'], true)) {
                    continue;
                }

                if (! array_key_exists($bucketKey, $recTemplates)) {
                    continue;
                }

                $gap = $cap - max(0, (int) $actualScore);

                if ($gap <= 0) {
                    continue;
                }

                $gaps[] = ['bucket' => $bucketKey, 'gap' => $gap];
            }
        }

        usort($gaps, static fn (array $a, array $b): int => $b['gap'] <=> $a['gap']);

        $recs = [];

        foreach (array_slice($gaps, 0, 3) as $entry) {
            $template = $recTemplates[$entry['bucket']];
            $recs[] = [
                'priority' => $template['priority'],
                'title'    => $template['title'],
                'body'     => str_replace('{{brand_name}}', $brandName, $template['body']),
                'bucket'   => $entry['bucket'],
                'gap'      => $entry['gap'],
            ];
        }

        return $recs;
    }

    // ── Phase 12c.4 FIX E — Target Skor LLM reasoning ─────────────────────────

    private function generateTargetScoreReasoning(BrandAudit $audit, int $overallScore): void
    {
        try {
            $pillarScores = (array) ($audit->pillar_scores ?? []);
            $pillarInts   = [];
            foreach ($pillarScores as $slug => $data) {
                if (is_array($data) && isset($data['score'])) {
                    $pillarInts[$slug] = (int) $data['score'];
                }
            }
            $payload = TargetScoreCalculator::compute(
                $overallScore,
                $pillarInts,
                (array) ($audit->sub_bucket_scores ?? []),
                (array) ($audit->score_breakdown ?? []),
            );
            if ($payload['actions'] === []) {
                return;
            }

            /** @var TargetScoreReasoningGenerator $generator */
            $generator = app(TargetScoreReasoningGenerator::class);
            $generator->setAuditContext($audit->id);
            $reasoning = $generator->generate(
                $audit,
                $payload['current'],
                $payload['target'],
                $payload['actions'],
            );

            DB::transaction(function () use ($audit, $payload, $reasoning): void {
                $fresh    = BrandAudit::findOrFail($audit->id);
                $evidence = (array) ($fresh->audit_evidence ?? []);
                $evidence['target_score_reasoning'] = [
                    'current'      => $payload['current'],
                    'target'       => $payload['target'],
                    'delta'        => $payload['delta'],
                    'actions'      => $payload['actions'],
                    'paragraphs'   => (array) ($reasoning['paragraphs'] ?? []),
                    'generated_at' => (string) ($reasoning['generated_at'] ?? now()->toIso8601String()),
                ];
                $fresh->update(['audit_evidence' => $evidence]);
            });
        } catch (Throwable $e) {
            // Never block the DONE flip on this. The view safely
            // hides the reasoning block when audit_evidence
            // .target_score_reasoning is absent.
            Log::warning('AggregateAuditJob: target_score_reasoning generation failed', [
                'audit_id' => $audit->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
