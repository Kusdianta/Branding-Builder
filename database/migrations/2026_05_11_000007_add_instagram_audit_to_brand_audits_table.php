<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->json('instagram_audit')
                ->nullable()
                ->after('score_breakdown');

            $table->string('instagram_audit_status', 48)
                ->default('pending')
                ->after('instagram_audit');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->dropColumn(['instagram_audit', 'instagram_audit_status']);
        });
    }
};
