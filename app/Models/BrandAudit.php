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

    /**
     * BB53: audit completed but the cross-touchpoint validation flagged
     * the input URLs as possibly belonging to a different brand than
     * the typed brand_name + city. The PDF + dashboard render normally
     * but with a warning banner; user can re-edit URLs and re-run.
     */
    public const STATUS_VALIDATION_WARNING = 'validation_warning';

    protected $fillable = [
        'session_token',
        'ip_address',
        'brand_name',
        'city',
        'service_type',
        'touchpoints',
        'status',
        'pillar_scores',
        'sub_bucket_scores',
        'overall_score',
        'overall_label',
        'key_findings',
        'recommendations',
        'evidence',
        'error_message',
        'activation_kit_path',
        'score_breakdown',
        'instagram_audit',
        'instagram_audit_status',
        'gmaps_reviews',
        'gmaps_reviews_status',
        'audit_evidence',
        'audit_evidence_status',
        'quick_wins',
        'competitive_positioning',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'touchpoints'       => 'array',
            'pillar_scores'     => 'array',
            'sub_bucket_scores'  => 'array',
            'score_breakdown'    => 'array',
            'overall_score'      => 'integer',
            'key_findings'      => 'array',
            'recommendations'   => 'array',
            'evidence'          => 'array',
            'instagram_audit'         => 'array',
            'gmaps_reviews'           => 'array',
            'audit_evidence'          => 'array',
            'quick_wins'              => 'array',
            'competitive_positioning' => 'array',
            'expires_at'              => 'datetime',
        ];
    }

    public function brandKit(): HasOne
    {
        return $this->hasOne(BrandKit::class);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_DONE
            || $this->status === self::STATUS_VALIDATION_WARNING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasValidationWarning(): bool
    {
        return $this->status === self::STATUS_VALIDATION_WARNING;
    }
}
