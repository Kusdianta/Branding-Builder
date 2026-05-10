<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class EvidenceItem
{
    public const IMPACT_POSITIVE = 'positive';

    public const IMPACT_NEGATIVE = 'negative';

    public const IMPACT_NEUTRAL = 'neutral';

    public function __construct(
        public string $touchpoint,
        public string $observation,
        public string $impact,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $impact = (string) ($data['impact'] ?? self::IMPACT_NEUTRAL);
        if (! in_array($impact, [self::IMPACT_POSITIVE, self::IMPACT_NEGATIVE, self::IMPACT_NEUTRAL], true)) {
            $impact = self::IMPACT_NEUTRAL;
        }

        return new self(
            touchpoint: (string) ($data['touchpoint'] ?? 'unknown'),
            observation: (string) ($data['observation'] ?? ''),
            impact: $impact,
        );
    }

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'touchpoint' => $this->touchpoint,
            'observation' => $this->observation,
            'impact' => $this->impact,
        ];
    }
}
