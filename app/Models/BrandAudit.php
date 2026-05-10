<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BrandAudit extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ANALYZING = 'analyzing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'session_token',
        'ip_address',
        'brand_name',
        'city',
        'service_type',
        'touchpoints',
        'status',
        'pillar_scores',
        'overall_score',
        'overall_label',
        'key_findings',
        'recommendations',
        'evidence',
        'error_message',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'touchpoints' => 'array',
            'pillar_scores' => 'array',
            'overall_score' => 'integer',
            'key_findings' => 'array',
            'recommendations' => 'array',
            'evidence' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function brandKit(): HasOne
    {
        return $this->hasOne(BrandKit::class);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
