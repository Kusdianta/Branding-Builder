<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BB19: per-step progress row for the parallelized AnalyzeBrand pipeline.
 *
 * Each AuditStep represents a discrete unit of work (Maps fetch, single
 * pillar score, IG scrape, PDF generation, etc.) with state transitions
 * pending → running → done|failed, captured timings, and free-form detail
 * payload for diagnostics. Polled every 2s by the loading view.
 *
 * @property string  $id
 * @property string  $brand_audit_id
 * @property string  $step_key
 * @property string  $track          'a' | 'b' | 'final'
 * @property string  $status         'pending' | 'running' | 'done' | 'failed'
 * @property ?\Carbon\CarbonImmutable $started_at
 * @property ?\Carbon\CarbonImmutable $completed_at
 * @property ?array  $detail
 * @property int     $order
 */
class AuditStep extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public const TRACK_PILLARS   = 'a';
    public const TRACK_INSTAGRAM = 'b';
    public const TRACK_FINAL     = 'final';

    protected $fillable = [
        'brand_audit_id',
        'step_key',
        'track',
        'status',
        'started_at',
        'completed_at',
        'detail',
        'order',
    ];

    protected $casts = [
        'started_at'   => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'detail'       => 'array',
        'order'        => 'integer',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(BrandAudit::class, 'brand_audit_id');
    }

    public function markRunning(?array $detail = null): void
    {
        $this->update([
            'status'     => self::STATUS_RUNNING,
            'started_at' => now(),
            'detail'     => $detail ?? $this->detail,
        ]);
    }

    public function markDone(?array $detail = null): void
    {
        $this->update([
            'status'       => self::STATUS_DONE,
            'completed_at' => now(),
            'detail'       => $detail ?? $this->detail,
        ]);
    }

    public function markFailed(string $reason, ?array $detail = null): void
    {
        $payload = $detail ?? [];
        $payload['error'] = $reason;
        $this->update([
            'status'       => self::STATUS_FAILED,
            'completed_at' => now(),
            'detail'       => $payload,
        ]);
    }

    public function elapsedSeconds(): ?float
    {
        if ($this->started_at === null) {
            return null;
        }
        $end = $this->completed_at ?? now();
        return round($this->started_at->diffInMilliseconds($end) / 1000, 1);
    }
}
