<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BB84.1 — append-only ledger of manual credit adjustments. Read-only by
 * convention; rows are written inside CreditLedger::adjust() and never
 * mutated thereafter.
 */
class CreditAdjustment extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'amount',
        'reason',
        'adjusted_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, CreditAdjustment> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
