<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BB73 — Phase 11: operator declarations for Brand Experience evidence.
     *
     * Optional self-reported declarations from the wizard form. The
     * ExperienceScorer's tier A/B/C/D logic (BB75) combines these with
     * the auto-extracted service_signals (BB74) to attribute bonus
     * sub-bucket scores:
     *
     *   A (declared + verified)   = 100% bonus
     *   B (detected only)         =  80% bonus
     *   C (declared, no signals)  =  67% bonus (capped, prompt operator
     *                                            to publicize service)
     *   D (neither)               =   0
     *
     * Schema (all optional, free-form):
     *   {
     *     "has_ekspres":          bool|null,
     *     "ekspres_url":          string|null,   // optional proof URL
     *     "has_antar_jemput":     bool|null,
     *     "antar_jemput_url":     string|null,
     *     "service_variants":     list<string>,  // kiloan, satuan, dry_cleaning, ...
     *     "has_sop_keluhan":      bool|null,
     *     "sop_keluhan_url":      string|null,
     *     "has_price_list":       bool|null,
     *     "price_list_url":       string|null
     *   }
     *
     * Nullable, defaults to null so existing wizards/audits stay valid.
     * BB75's tier classifier treats null as "not declared" (Tier D when
     * also undetected).
     */
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->json('operator_declarations')->nullable()->after('touchpoints');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->dropColumn('operator_declarations');
        });
    }
};
