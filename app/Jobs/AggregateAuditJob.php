<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AggregateAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(): void
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

            $audit->update([
                'status'          => BrandAudit::STATUS_DONE,
                'overall_score'   => $overallScore,
                'overall_label'   => $overallLabel,
                'key_findings'    => $keyFindings,
                'recommendations' => $recommendations,
                'error_message'   => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            Log::error('AggregateAuditJob failed', [
                'audit_id' => $this->auditId,
                'error'    => $e->getMessage(),
            ]);

            $audit->update([
                'status'        => BrandAudit::STATUS_FAILED,
                'error_message' => 'Agregasi gagal: ' . $e->getMessage(),
            ]);
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
}
