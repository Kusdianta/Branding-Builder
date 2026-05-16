<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BB80 — Phase 12a: replace the default Laravel scaffold users table with an
 * OAuth-first schema (ULID PK, no password column, Google identity, credit
 * counters). The scaffold table was unused in branding-builder; no rows are
 * destroyed by the drop. password_reset_tokens is dropped because OAuth-only
 * auth does not need it. sessions.user_id is widened to ULID so Laravel's
 * database session driver continues to bind sessions to authenticated users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('google_id')->nullable()->unique();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('avatar_url')->nullable();
            $table->unsignedInteger('credits_balance')->default(1);
            $table->unsignedInteger('credits_lifetime_earned')->default(1);
            $table->unsignedInteger('credits_lifetime_spent')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // sessions table was created with foreignId('user_id') (bigint). Now
        // that users.id is a ULID string, the column type must match. The
        // index on user_id must be dropped before the column can be dropped
        // on SQLite — otherwise the index references a non-existent column.
        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
            Schema::table('sessions', function (Blueprint $table) {
                $table->string('user_id', 26)->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sessions')) {
            Schema::table('sessions', function (Blueprint $table) {
                $table->dropColumn('user_id');
            });
            Schema::table('sessions', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->index()->after('id');
            });
        }

        Schema::dropIfExists('users');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
