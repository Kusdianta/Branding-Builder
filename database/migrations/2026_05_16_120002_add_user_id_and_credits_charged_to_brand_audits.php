<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BB80 — Phase 12a: link audits to authenticated users + record how many
 * credits a given audit consumed. user_id stays nullable so the legacy
 * token-only anonymous flow (pre-Phase-12a rows) remains accessible via
 * session_token without breaking foreign-key constraints. credits_charged
 * defaults to 0; new audits set it to 1, refunded audits roll it back to 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table) {
            $table->ulid('user_id')->nullable()->after('session_token');
            $table->unsignedInteger('credits_charged')->default(0)->after('user_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn(['user_id', 'credits_charged']);
        });
    }
};
