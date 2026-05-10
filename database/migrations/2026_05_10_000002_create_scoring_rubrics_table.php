<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scoring_rubrics', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('pillar_slug', 64)->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(false);
            $table->text('system_prompt');
            $table->json('input_schema');
            $table->timestamps();

            $table->unique(['pillar_slug', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_rubrics');
    }
};
