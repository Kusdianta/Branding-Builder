<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;
use App\Models\CreditAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BB82 — Centralised credit-debit/refund logic so the wizard, the API, and
 * the failure-path jobs all converge on one atomic update path. Two methods:
 *
 *  - charge(): deduct 1 from credits_balance, increment credits_lifetime_spent,
 *    record credits_charged on the audit. Returns true on success.
 *
 *  - refund(): roll back charge() — only when the audit currently has
 *    credits_charged > 0 (idempotent: a re-failed audit cannot
 *    double-refund). Both happen inside a single DB::transaction so a
 *    concurrent dispatch + failure cannot leave the balance out of sync.
 *
 * Credits are an unsigned integer. A negative balance is structurally
 * impossible — charge() refuses if balance < cost.
 */
class CreditLedger
{
    public function __construct(private readonly int $costPerAudit = 1)
    {
    }

    /**
     * Charge $costPerAudit from $user and stamp credits_charged on $audit.
     * Returns true if the audit was successfully charged.
     *
     * The caller is responsible for creating the BrandAudit row beforehand
     * (the audit's user_id is already set) and for wrapping both create()
     * and charge() in an outer transaction when atomicity is required.
     */
    public function charge(User $user, BrandAudit $audit): bool
    {
        return DB::transaction(function () use ($user, $audit): bool {
            $fresh = User::lockForUpdate()->find($user->id);
            if (! $fresh || $fresh->credits_balance < $this->costPerAudit) {
                return false;
            }

            $fresh->decrement('credits_balance', $this->costPerAudit);
            $fresh->increment('credits_lifetime_spent', $this->costPerAudit);

            $audit->forceFill(['credits_charged' => $this->costPerAudit])->save();

            return true;
        });
    }

    /**
     * Refund the credits previously charged to $audit. Idempotent — if
     * credits_charged is already 0, the call is a no-op. Lock the user
     * row so a refund that races a new charge cannot lose either update.
     */
    public function refund(BrandAudit $audit): bool
    {
        return DB::transaction(function () use ($audit): bool {
            $charged = (int) $audit->credits_charged;
            if ($charged <= 0 || $audit->user_id === null) {
                return false;
            }

            $user = User::lockForUpdate()->find($audit->user_id);
            if (! $user) {
                Log::warning('Credit refund skipped: user missing', [
                    'audit_id' => $audit->id,
                    'user_id'  => $audit->user_id,
                ]);
                return false;
            }

            $user->increment('credits_balance', $charged);
            $user->decrement('credits_lifetime_spent', $charged);

            $audit->forceFill(['credits_charged' => 0])->save();

            return true;
        });
    }

    /**
     * BB84 — apply a manual operator adjustment to $user's balance and
     * append a credit_adjustments audit-trail row. Positive amounts add
     * credits; negative amounts deduct (clamped so the balance never goes
     * below zero). Returns the resulting balance after adjustment.
     */
    public function adjust(User $user, int $amount, string $reason, string $adjustedBy): int
    {
        return DB::transaction(function () use ($user, $amount, $reason, $adjustedBy): int {
            $fresh = User::lockForUpdate()->find($user->id);
            if (! $fresh) {
                throw new \RuntimeException('User missing during credit adjust.');
            }

            $effectiveAmount = $amount;
            if ($amount < 0) {
                // Clamp: never go below zero.
                $effectiveAmount = -min(abs($amount), $fresh->credits_balance);
            }

            if ($effectiveAmount > 0) {
                $fresh->increment('credits_balance', $effectiveAmount);
                $fresh->increment('credits_lifetime_earned', $effectiveAmount);
            } elseif ($effectiveAmount < 0) {
                $fresh->decrement('credits_balance', abs($effectiveAmount));
            }

            CreditAdjustment::create([
                'user_id'     => $fresh->id,
                'amount'      => $effectiveAmount,
                'reason'      => $reason,
                'adjusted_by' => $adjustedBy,
                'created_at'  => now(),
            ]);

            return (int) $fresh->fresh()->credits_balance;
        });
    }
}
