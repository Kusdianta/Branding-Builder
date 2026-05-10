<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_audit_id')
                ->constrained('brand_audits')
                ->cascadeOnDelete();
            $table->json('generated_payload');
            $table->string('pdf_path')->nullable();
            $table->timestamps();

            $table->index('brand_audit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_kits');
    }
};
