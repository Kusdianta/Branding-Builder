<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BB84.1 — append-only audit trail for manual credit adjustments. Every
 * row written here is paired with a +/- update on users.credits_balance
 * inside the same transaction; the table never replaces the canonical
 * balance, just records WHY it changed. amount is signed (positive = add,
 * negative = remove). adjusted_by is a free-form identifier supplied by
 * the Hub admin caller (operator email or similar) — not an FK because
 * Hub admin users live in a different repo's DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_adjustments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->integer('amount');
            $table->string('reason');
            $table->string('adjusted_by');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_adjustments');
    }
};
