<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('session_token', 64)->unique();
            $table->string('ip_address', 45);
            $table->string('brand_name');
            $table->string('city')->nullable();
            $table->string('service_type');
            $table->json('touchpoints');
            $table->string('status', 32)->default('pending')->index();
            $table->json('pillar_scores')->nullable();
            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->string('overall_label')->nullable();
            $table->json('key_findings')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('evidence')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_audits');
    }
};
