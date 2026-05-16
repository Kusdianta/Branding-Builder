<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10 BB51: unified data-gathering evidence layer for the 3-phase
 * audit pipeline (gather -> validate -> score).
 *
 * Why a NEW column ('audit_evidence') instead of reusing the existing
 * 'evidence' column from create_brand_audits_table? The existing
 * 'evidence' column already holds per-pillar evidence ITEMS (citations
 * the scorer used: "GMaps rating 4.5/5 from 142 reviews",
 * "IG bio mentions 'organik'", ...). Reusing it for a different shape
 * would collide with AggregateAuditJob, ScorePillarsJob, ClaudeService,
 * and the PillarScore DTO. Renaming preserves semantic clarity.
 *
 * Column shape (audit_evidence):
 *
 *   {
 *     "places_api": { rating, review_count, place_id, address, photos[], hours, attributes },
 *     "gmaps_scrape": { business_name, rating, total_review_count, reviews[], scraped_at, source, gmaps_screenshot_path },
 *     "instagram_audit": { followers, posts_count, name, bio, recent_posts[], highlights[], profile_pic_path, screenshot_path },
 *     "instagram_analysis": { ...Phase 7-B Claude analysis output... },
 *     "validation": { brand_name_match: bool|null, city_match: bool|null, warnings: [], confidence: 0.0-1.0 }
 *   }
 *
 * Each top-level key is independently nullable so partial evidence is
 * a valid intermediate state (BB52 allowFailures pattern). Scorers
 * (BB54) must handle missing keys gracefully.
 *
 * Status enum (audit_evidence_status):
 *   pending             initial default, never written by gather job
 *   gathering           BB52 GatherEvidenceJob in flight
 *   gathered            all 3 sub-fetches complete (success or graceful failure)
 *   validated           BB53 confidence >= 0.5
 *   validation_warning  BB53 confidence < 0.5 — pipeline continues but PDF + dashboard flag the audit
 *   legacy_backfilled   BB51 backfill of pre-Phase-10 row; raw scrapes copied from gmaps_reviews + instagram_audit columns
 *
 * Backfill strategy: non-destructive. Existing 'gmaps_reviews' and
 * 'instagram_audit' columns are RETAINED as legacy fallback. The
 * backfill copies their values into audit_evidence so BB54 scorers
 * can read uniformly, but the original columns stay populated. Any
 * future re-run of a legacy audit through BB55's 3-phase pipeline
 * will overwrite audit_evidence with freshly gathered data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->json('audit_evidence')
                ->nullable()
                ->after('gmaps_reviews_status');

            $table->string('audit_evidence_status', 32)
                ->default('pending')
                ->after('audit_evidence');
        });

        $this->backfillFromLegacyColumns();
    }

    public function down(): void
    {
        Schema::table('brand_audits', function (Blueprint $table): void {
            $table->dropColumn(['audit_evidence', 'audit_evidence_status']);
        });
    }

    /**
     * Copy existing gmaps_reviews + instagram_audit JSON into the
     * unified audit_evidence shape. Marks each row with status
     * 'legacy_backfilled' so BB55 can distinguish pre-Phase-10 rows
     * from fresh-pipeline rows. Idempotent: only touches rows still
     * on default 'pending' status.
     *
     * Mapping nuance — the legacy 'instagram_audit' column holds the
     * Phase 7-B CLAUDE ANALYSIS OUTPUT (executive_summary,
     * profile_branding, scorecard, ...), not the raw scrape. The raw
     * scrape was passed transiently to the analyzer and never
     * persisted. So:
     *
     *   legacy gmaps_reviews    -> audit_evidence.gmaps_scrape (raw scrape, shape matches)
     *   legacy instagram_audit  -> audit_evidence.instagram_analysis (Claude output)
     *   audit_evidence.instagram_audit -> NULL for legacy rows (raw scrape never persisted)
     *
     * Pre-Phase-10 audits will not have raw IG scrape data for BB54
     * scorers; the analyzed payload remains accessible for downstream
     * read paths under audit_evidence.instagram_analysis.
     */
    private function backfillFromLegacyColumns(): void
    {
        DB::table('brand_audits')
            ->where('audit_evidence_status', 'pending')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $gmaps      = $this->safeJsonDecode($row->gmaps_reviews);
                    $igAnalysis = $this->safeJsonDecode($row->instagram_audit);

                    $evidence = [
                        'places_api'         => null,
                        'gmaps_scrape'       => $gmaps,
                        'instagram_audit'    => null,
                        'instagram_analysis' => $igAnalysis,
                        'validation'         => null,
                        '_meta'              => [
                            'backfilled_at' => now()->toIso8601String(),
                            'source'        => 'BB51_migration',
                            'note'          => 'Pre-Phase-10 row; raw IG scrape never persisted, so audit_evidence.instagram_audit is null and the Claude analysis lives under instagram_analysis. gmaps_reviews + instagram_audit legacy columns retained as fallback.',
                        ],
                    ];

                    DB::table('brand_audits')
                        ->where('id', $row->id)
                        ->update([
                            'audit_evidence'        => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'audit_evidence_status' => 'legacy_backfilled',
                        ]);
                }
            });
    }

    private function safeJsonDecode(?string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $decoded;
    }
};
