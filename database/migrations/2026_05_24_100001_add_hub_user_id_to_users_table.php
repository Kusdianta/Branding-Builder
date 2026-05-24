<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BB02 — link this spoke's users to the Hub identity registry. Additive:
 * existing users (matched by google_id on first SSO sign-in) keep their
 * credits + audit history; hub_user_id is back-filled then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('hub_user_id')->nullable()->unique()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['hub_user_id']);
            $table->dropColumn('hub_user_id');
        });
    }
};
