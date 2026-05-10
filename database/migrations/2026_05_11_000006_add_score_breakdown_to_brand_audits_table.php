<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->json('score_breakdown')->nullable()->after('sub_bucket_scores');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->dropColumn('score_breakdown');
        });
    }
};
