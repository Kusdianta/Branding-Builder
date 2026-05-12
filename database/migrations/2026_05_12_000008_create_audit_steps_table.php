<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BB19: audit_steps table — per-step progress tracking for the
     * parallelized AnalyzeBrand pipeline (BB20). Each row represents
     * one discrete unit of work (fetch_gmaps, score_recall, ig_scrape,
     * generate_pdf, etc.). The loading view polls this table to render
     * real-time progress for both tracks instead of a generic spinner.
     */
    public function up(): void
    {
        Schema::create('audit_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('brand_audit_id')
                ->constrained('brand_audits')
                ->cascadeOnDelete();
            $table->string('step_key', 64);     // 'fetch_gmaps', 'score_recall', 'ig_scrape', 'generate_pdf', ...
            $table->string('track', 16);        // 'a' (pillars), 'b' (instagram), 'final' (pdf)
            $table->string('status', 16)->default('pending'); // pending | running | done | failed
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->json('detail')->nullable(); // error message, progress note, sub-results
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestampsTz();

            $table->index(['brand_audit_id', 'order']);
            $table->index(['brand_audit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_steps');
    }
};
