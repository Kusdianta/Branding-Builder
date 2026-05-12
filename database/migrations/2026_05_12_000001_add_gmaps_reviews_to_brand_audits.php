<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 BB24: persist the GMaps reviews scrape result on the
 * BrandAudit row + a status enum string for orchestration branching.
 *
 * Status enum values (mirrors the IG audit pattern):
 *   pending                   — initial default, never written by service
 *   done                      — success, gmaps_reviews populated
 *   no_gmaps_url_provided     — wizard didn't capture one
 *   no_credentials_available  — Hub has no healthy gmaps credential
 *   credentials_stale         — Google sign-in redirect on every attempt
 *   rate_limited              — worker rate-limited this place URL
 *   place_not_found           — h1.DUwDvf missing on the loaded page
 *   captcha_blocked           — CAPTCHA / consent interstitial hit
 *   scrape_failed             — catch-all; detail in gmaps_reviews.error
 *   legacy_places_api_only    — BB30 sentinel for pre-Phase-8 audits
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table) {
            $table->json('gmaps_reviews')->nullable()->after('instagram_audit');
            $table->string('gmaps_reviews_status', 32)
                ->default('pending')
                ->after('gmaps_reviews');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table) {
            $table->dropColumn(['gmaps_reviews', 'gmaps_reviews_status']);
        });
    }
};
