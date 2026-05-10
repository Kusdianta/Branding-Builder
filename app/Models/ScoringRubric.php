<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ScoringRubric extends Model
{
    use HasUlids;

    public const PILLAR_KONSISTENSI = 'brand-konsistensi';

    public const PILLAR_RECALL = 'brand-recall';

    public const PILLAR_EXPERIENCE = 'brand-experience';

    public const PILLAR_DIGITAL = 'digital-presence';

    protected $fillable = [
        'pillar_slug',
        'version',
        'is_active',
        'system_prompt',
        'input_schema',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'input_schema' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForPillar(Builder $query, string $slug): Builder
    {
        return $query->where('pillar_slug', $slug);
    }
}
