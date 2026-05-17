<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12c BB90 — extend brand_audits for the new 4-step wizard.
 *
 * v2 audits are anchored by Google Place ID instead of a typed
 * brand_name + free-text URLs. The wizard hydrates the Place columns
 * up-front from the Places Autocomplete + Details session; downstream
 * jobs (FetchPlacesApiJob, FetchInstagramAuditJob, FetchWebsiteJob)
 * still read from `touchpoints` for backward compatibility, but the
 * Place columns are the new source of truth.
 *
 * Backward compatibility:
 *   - All new columns are nullable. Existing v1 audits keep their
 *     null place_* values and continue to render via legacy fallback
 *     in the /audits history view.
 *   - `wizard_version` defaults to 'v1' so historical rows are
 *     correctly labelled without a backfill UPDATE; the BB91 wizard
 *     stamps 'v2' explicitly on every new submission.
 *   - `city` and `touchpoints` are intentionally left in place.
 *     `city` is now derived from place_address for v2 audits but
 *     remains writable for v1 readers; BrandAudit's PHPDoc flags it
 *     @deprecated so future code stops adding to it.
 *
 * place_raw stores the full Places Details response so that future
 * scoring features (opening hours, attributes, plus_code, viewport)
 * can be derived without re-calling the API. Cost-bounded — each
 * audit triggers exactly one Details call, billed at session-lock
 * pricing when a session_token is presented.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            // Primary Place anchor. Indexed so audit history can be
            // filtered/aggregated per Place without a JSON scan.
            $table->string('place_id', 255)->nullable()->after('brand_name');
            $table->string('place_name', 255)->nullable()->after('place_id');
            $table->text('place_address')->nullable()->after('place_name');
            $table->decimal('place_lat', 10, 7)->nullable()->after('place_address');
            $table->decimal('place_lng', 10, 7)->nullable()->after('place_lat');
            $table->string('place_phone', 50)->nullable()->after('place_lng');
            $table->string('place_website', 500)->nullable()->after('place_phone');
            $table->json('place_categories')->nullable()->after('place_website');
            $table->json('place_raw')->nullable()->after('place_categories');

            // Step 4 free-form input. Optional; the LLM analysis layer
            // (GenerateInsightsJob) reads it as additional context when
            // present, ignores it when null.
            $table->text('notes')->nullable()->after('operator_declarations');

            // Discriminator so the /audits history view + future
            // migrations can branch on schema generation without
            // having to probe column emptiness. 'v1' default keeps
            // historical rows correctly labelled with zero backfill.
            $table->string('wizard_version', 10)->default('v1')->after('notes');

            $table->index('place_id', 'brand_audits_place_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->dropIndex('brand_audits_place_id_index');
            $table->dropColumn([
                'place_id',
                'place_name',
                'place_address',
                'place_lat',
                'place_lng',
                'place_phone',
                'place_website',
                'place_categories',
                'place_raw',
                'notes',
                'wizard_version',
            ]);
        });
    }
};
