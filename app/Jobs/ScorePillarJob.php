<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandAudit;
use App\Services\ClaudeService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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

        try {
            $score = $claude->scorePillar($this->pillarSlug, $this->fetcherData);

            DB::transaction(function () use ($score): void {
                $audit = BrandAudit::findOrFail($this->auditId);

                $pillarScores = $audit->pillar_scores ?? [];
                $pillarScores[$this->pillarSlug] = $score->toArray();

                $subBuckets = $audit->sub_bucket_scores ?? [];
                $subBuckets[$this->pillarSlug] = $score->subBucketScores;

                $audit->update([
                    'pillar_scores'     => $pillarScores,
                    'sub_bucket_scores' => $subBuckets,
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('ScorePillarJob failed', [
                'audit_id'    => $this->auditId,
                'pillar_slug' => $this->pillarSlug,
                'error'       => $e->getMessage(),
            ]);

            DB::transaction(function () use ($e): void {
                $audit = BrandAudit::find($this->auditId);
                if ($audit === null) {
                    return;
                }

                $pillarScores = $audit->pillar_scores ?? [];
                $pillarScores[$this->pillarSlug] = ['error' => $e->getMessage()];
                $audit->update(['pillar_scores' => $pillarScores]);
            });
        }
    }
}
