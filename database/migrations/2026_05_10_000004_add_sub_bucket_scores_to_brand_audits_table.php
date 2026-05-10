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
            $table->json('sub_bucket_scores')->nullable()->after('pillar_scores');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->dropColumn('sub_bucket_scores');
        });
    }
};
