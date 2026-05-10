<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class PillarScore
{
    /**
     * @param list<EvidenceItem>   $evidence
     * @param array<string,mixed>  $subBucketScores  per-pillar breakdown (empty for legacy)
     */
    public function __construct(
        public string $pillarSlug,
        public int $score,
        public array $evidence,
        public string $reasoning,
        public array $subBucketScores = [],
    ) {}

    /** @return array{pillar_slug:string,score:int,evidence:list<array<string,string>>,reasoning:string,sub_bucket_scores:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'pillar_slug'       => $this->pillarSlug,
            'score'             => $this->score,
            'evidence'          => array_map(static fn (EvidenceItem $e): array => $e->toArray(), $this->evidence),
            'reasoning'         => $this->reasoning,
            'sub_bucket_scores' => $this->subBucketScores,
        ];
    }
}
