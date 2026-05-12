<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BrandAudit;
use App\Services\GMapsReviewsService;
use App\Services\Scoring\ExperiencePenaltyDetector;
use Illuminate\Console\Command;

/**
 * Phase 8 BB31 smoke: drive GMapsReviewsService + ExperiencePenaltyDetector
 * against an existing brand_audit row by session_token.
 *
 * Bypasses AnalyzeBrand on purpose — runs the gmaps scrape standalone so
 * we see the full payload in real time, with no queue worker required and
 * no pillar-batch noise in the output.
 *
 * Pre-conditions for the smoke to actually scrape (vs returning a status
 * banner):
 *
 *   1. Worker (workers/nema-worker) is running on the configured base URL
 *      with `uv run uvicorn app.main:app --port 9878 --reload`.
 *   2. Hub (nema-hub) has at least one healthy gmaps WorkerCredential
 *      row pasted via the H8.1/H8.2 Filament UI; the cookies are a fresh
 *      Cookie-Editor export from a logged-in google.com tab.
 *   3. The target brand_audit row has touchpoints.gmaps_url populated.
 *   4. The target place URL has not been scraped within the last 5 min
 *      (the worker rate-limits 1× per place URL per 5 min).
 *
 * Usage:
 *   php artisan smoke:phase8 {session_token}
 *
 * Less Worry audit token (kept from Phase 7-B closure for continuity):
 *   pSXlc3C8SxUdJ57j4FwlJz15uzHgsj1B8qRfWtqw3PfoMKjSBxAn970ixqkkLQ9t
 */
class SmokePhase8Command extends Command
{
    /** @var string */
    protected $signature = 'smoke:phase8 {session_token : The brand_audits.session_token to drive}';

    /** @var string */
    protected $description = 'Phase 8 smoke: run GMapsReviewsService + ExperiencePenaltyDetector standalone.';

    public function handle(
        GMapsReviewsService $service,
        ExperiencePenaltyDetector $detector,
    ): int {
        $token = (string) $this->argument('session_token');

        $audit = BrandAudit::query()->where('session_token', $token)->first();
        if ($audit === null) {
            $this->error("No brand_audit found for session_token={$token}");
            return self::FAILURE;
        }

        $touchpoints = (array) $audit->touchpoints;
        $gmapsUrl    = (string) ($touchpoints['gmaps_url'] ?? '');

        $this->line('==============================================');
        $this->line('Phase 8 smoke — GMaps scrape + penalty detector');
        $this->line('==============================================');
        $this->line("brand_audit_id      : {$audit->id}");
        $this->line("brand_name          : {$audit->brand_name}");
        $this->line("city                : " . ($audit->city ?: '(not set)'));
        $this->line("gmaps_url           : " . ($gmapsUrl !== '' ? $gmapsUrl : '(empty — scrape will skip)'));
        $this->line("status (pre-scrape) : " . ($audit->gmaps_reviews_status ?? '(null)'));
        $this->newLine();

        // Pre-snapshot of the experience pillar score so we can report the
        // penalty delta cleanly even when re-running on an audit that
        // already had W8 applied.
        $pillarScoresBefore = (array) ($audit->pillar_scores ?? []);
        $expBefore = (array) ($pillarScoresBefore['experience'] ?? []);
        $expScoreBefore = (int) ($expBefore['score'] ?? 0);

        $this->line('Calling GMapsReviewsService::fetch ...');
        $start = microtime(true);
        $service->fetch($audit);
        $elapsedScrape = round((microtime(true) - $start) * 1000);
        $audit->refresh();

        $status  = (string) ($audit->gmaps_reviews_status ?? '');
        $payload = (array) ($audit->gmaps_reviews ?? []);

        $this->line("status (post-scrape): {$status}  (took {$elapsedScrape}ms)");
        if ($status !== 'done') {
            $this->warn('Scrape did not complete with status=done. Payload:');
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $reviews = (array) ($payload['reviews'] ?? []);
        $this->line("business_name       : " . ($payload['business_name'] ?? ''));
        $this->line("rating              : " . ($payload['rating'] ?? '(null)'));
        $this->line("total_review_count  : " . ($payload['total_review_count'] ?? '(null)'));
        $this->line("scraped reviews     : " . count($reviews));
        $this->line("limitations         : " . count((array) ($payload['limitations'] ?? [])));
        foreach ((array) ($payload['limitations'] ?? []) as $note) {
            $this->line("  - {$note}");
        }
        $this->newLine();

        $this->line('Running ExperiencePenaltyDetector against scraped reviews ...');
        $detectorInput = array_map(static fn ($r) => [
            'author'       => (string) ($r['author'] ?? ''),
            'rating_value' => (int) ($r['rating_value'] ?? 0),
            'text'         => (string) ($r['text'] ?? ''),
        ], $reviews);
        $detection = $detector->detect($detectorInput);

        $this->line("reviews_scanned       : {$detection['reviews_scanned']}");
        $this->line("reviews_skipped_short : {$detection['reviews_skipped_short']}");
        $this->line("total_penalty         : {$detection['total_penalty']}");
        foreach ((array) $detection['penalties'] as $key => $delta) {
            $this->line("  {$key}: {$delta}");
        }
        $this->newLine();

        // Score delta summary — what the experience pillar would land at
        // after BB27's applyExperiencePenalties subtracts the deltas.
        $expScoreAfter = max(0, $expScoreBefore + (int) $detection['total_penalty']);
        $this->line('Experience pillar score delta (informational):');
        $this->line("  pre-penalty  : {$expScoreBefore}");
        $this->line("  post-penalty : {$expScoreAfter}");
        $this->line("  delta        : " . ($expScoreAfter - $expScoreBefore));

        return self::SUCCESS;
    }
}
