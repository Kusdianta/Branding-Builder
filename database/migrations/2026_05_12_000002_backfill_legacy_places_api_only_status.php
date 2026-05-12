<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8 BB30: backfill sentinel for existing brand_audits rows that
 * pre-date the W8 GMaps scraper. Sets gmaps_reviews_status to
 * 'legacy_places_api_only' for any row still on the migration default
 * 'pending'.
 *
 * Why a sentinel and not a re-score? Re-scoring would silently change
 * the score_breakdown of audits whose PDFs operators have already
 * downloaded. The user-facing degraded banner ("Audit ini menggunakan
 * sample 5 ulasan dari Places API. Audit baru akan menggunakan
 * scraping ulasan lengkap.") shows in pdf/_gmaps-reviews.blade.php
 * (BB28) and livewire/_gmaps-reviews-section.blade.php (BB29) so
 * operators see clearly why this older audit looks different from
 * one regenerated post-Phase-8.
 *
 * Idempotent — only updates rows that are still on the default. Any
 * audit that has already been re-run through the W8 pipeline will
 * carry one of {done, no_credentials_available, ...} and will not be
 * touched here.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('brand_audits')
            ->where('gmaps_reviews_status', 'pending')
            ->update(['gmaps_reviews_status' => 'legacy_places_api_only']);
    }

    public function down(): void
    {
        DB::table('brand_audits')
            ->where('gmaps_reviews_status', 'legacy_places_api_only')
            ->update(['gmaps_reviews_status' => 'pending']);
    }
};
