<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /**
     * Wizard schema generations.
     *
     * V1 = pre-Phase-12c single-page form: required city + free-text
     *      touchpoint URLs + photo uploads.
     * V2 = Phase-12c 4-step wizard: anchored by Google place_id, all
     *      other fields hydrated from Places Details + Step 3 inputs.
     * V3 = Phase 12c.2-rubric-alignment: V2 wizard + multi-select
     *      service types (primary + secondary), three operational
     *      declarations (express/pickup/SOP) surfaced in Step 3,
     *      and TikTok back as availability-check-only via the BB113
     *      JSON endpoint. New audits stamp V3 so the dashboard knows
     *      to render with full per-row source attribution; legacy
     *      V1/V2 audits keep their old render path for backwards
     *      compat (BB118 honest-degradation rule).
     */
    public const WIZARD_V1 = 'v1';
    public const WIZARD_V2 = 'v2';
    public const WIZARD_V3 = 'v3';

    protected $fillable = [
        'session_token',
        'user_id',
        'credits_charged',
        'ip_address',
        'brand_name',
        'place_id',
        'place_name',
        'place_address',
        'place_lat',
        'place_lng',
        'place_phone',
        'place_website',
        'place_categories',
        'place_raw',
        'city',
        'service_type',
        'touchpoints',
        'operator_declarations',
        'notes',
        'wizard_version',
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
            'touchpoints'           => 'array',
            'operator_declarations' => 'array',
            'place_categories'      => 'array',
            'place_raw'             => 'array',
            'place_lat'             => 'decimal:7',
            'place_lng'             => 'decimal:7',
            'pillar_scores'         => 'array',
            'sub_bucket_scores'  => 'array',
            'score_breakdown'    => 'array',
            'overall_score'      => 'integer',
            'credits_charged'    => 'integer',
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

    /**
     * @deprecated Phase 12c BB90 — V2 audits derive a display-ready
     * location from place_address; the standalone city column is kept
     * only for legacy V1 audit rendering and admin/CSV exports. New
     * code should read place_address (formatted by Google) or
     * place_raw['address_components'] for structured access. The
     * column will not be dropped because pre-12c audits depend on it.
     */
    public function isV2(): bool
    {
        return $this->wizard_version === self::WIZARD_V2;
    }

    public function brandKit(): HasOne
    {
        return $this->hasOne(BrandKit::class);
    }

    /** @return BelongsTo<User, BrandAudit> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
