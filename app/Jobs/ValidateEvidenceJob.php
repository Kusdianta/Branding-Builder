<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditStep;
use App\Models\BrandAudit;
use App\Services\ClaudeService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BB53 — Phase 10 validate phase (between gather and score).
 *
 * Cross-checks the gathered evidence against the user-typed brand name
 * + city to catch the failure mode where someone pastes the wrong
 * GMaps or Instagram URL: scoring proceeds, but the PDF and dashboard
 * (BB60) get a warning banner so the operator/user can correct + re-run.
 *
 * Pipeline:
 *   1. Heuristic fuzzy match on the scraped names (GMaps business_name
 *      + Instagram profile name) against input brand_name. Substring,
 *      shared-token (3+ chars), and normalized-Levenshtein checks.
 *   2. Heuristic city-token check on the scraped GMaps address.
 *   3. LLM tie-breaker via ClaudeService::validateBrandMatch — catches
 *      semantic equivalents heuristic misses (e.g.
 *      "Less Worry Laundry" vs "Less Worry | Laundry Bebas Worry").
 *   4. Combine into a single validation block written to
 *      audit_evidence.validation:
 *      {confidence, brand_name_match, city_match, warnings, reasoning}
 *   5. If confidence < 0.5, transition audit.status to
 *      STATUS_VALIDATION_WARNING and audit_evidence_status to
 *      'validation_warning'. Otherwise transition to 'validated'.
 *
 * Pipeline never blocks: a failed validation still produces a
 * scored audit; the operator just sees a warning. Catch-all wraps
 * any LLM exception into a neutral validation result via
 * ClaudeService's own internal fallback.
 */
class ValidateEvidenceJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public readonly string $auditId) {}

    public function handle(ClaudeService $claude): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $step = $this->step('validate_evidence');
        $step?->markRunning();

        $audit = BrandAudit::findOrFail($this->auditId);
        $evidence = (array) ($audit->audit_evidence ?? []);

        $heuristic = $this->heuristicChecks(
            brandName: (string) $audit->brand_name,
            city: (string) ($audit->city ?? ''),
            evidence: $evidence,
        );

        $llm = $this->safeLlmJudgment($claude, (string) $audit->brand_name, (string) ($audit->city ?? ''), $evidence);

        $validation = $this->combine($heuristic, $llm);

        try {
            $this->persistValidation($validation);
            $step?->markDone([
                'confidence'       => $validation['confidence'],
                'brand_name_match' => $validation['brand_name_match'],
                'city_match'       => $validation['city_match'],
                'warning_count'    => count($validation['warnings']),
            ]);
        } catch (Throwable $e) {
            Log::error('ValidateEvidenceJob: persistence failed', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
            $step?->markFailed($e->getMessage());
        }

        $this->chainScorePhase();
    }

    /**
     * Phase 3 kicker: wrap ScorePillarsJob in a one-job batch so we can
     * hang ->then(GenerateInsightsJob::dispatch) off completion.
     * GenerateInsightsJob already chains GeneratePdfJob at the end of
     * its own pipeline (BB38).
     *
     * Why a single-job batch instead of straight dispatch + relying on
     * ScorePillarsJob itself to dispatch insights? The current
     * ScorePillarsJob ends with AggregateAuditJob::dispatchSync — its
     * handle() doesn't return until the pillar work is done, so the
     * batch boundary is a clean completion signal. Using ::dispatch
     * directly would race the queue worker against subsequent reads.
     */
    private function chainScorePhase(): void
    {
        $auditId = $this->auditId;

        Bus::batch([new ScorePillarsJob($auditId)])
            ->name("audit:{$auditId}:score")
            ->then(static function (Batch $batch) use ($auditId): void {
                GenerateInsightsJob::dispatch($auditId);
            })
            ->catch(static function (Batch $batch, Throwable $e) use ($auditId): void {
                Log::error('ValidateEvidenceJob: score batch failed', [
                    'audit_id' => $auditId, 'error' => $e->getMessage(),
                ]);
                // Still try to land a PDF even when scoring fails —
                // GenerateInsightsJob will degrade gracefully on missing
                // pillar_scores (BB38 contract).
                GenerateInsightsJob::dispatch($auditId);
            })
            ->dispatch();
    }

    private function step(string $key): ?AuditStep
    {
        return AuditStep::where('brand_audit_id', $this->auditId)
            ->where('step_key', $key)
            ->first();
    }

    /**
     * @param array<string,mixed> $evidence
     * @return array{brand_name_match: bool|null, city_match: bool|null, warnings: list<string>, scraped_names: list<string>}
     */
    private function heuristicChecks(string $brandName, string $city, array $evidence): array
    {
        $gmaps      = (array) ($evidence['gmaps_scrape'] ?? []);
        $igAudit    = (array) ($evidence['instagram_audit'] ?? []);
        $igAnalysis = (array) ($evidence['instagram_analysis'] ?? []);

        $scrapedNames = array_values(array_filter([
            (string) ($gmaps['business_name'] ?? ''),
            (string) ($igAudit['username'] ?? ''),
            (string) ($igAnalysis['profile_branding']['name'] ?? ''),
        ], fn (string $s): bool => $s !== ''));

        $brandNameMatch = $this->matchAnyName($brandName, $scrapedNames);

        $cityMatch = null;
        $warnings  = [];
        if ($city !== '') {
            $address = trim((string) ($gmaps['address'] ?? ''))
                ?: trim((string) (($evidence['places_api']['address'] ?? '')));
            if ($address !== '') {
                $cityMatch = $this->cityAppearsInAddress($city, $address);
                if ($cityMatch === false) {
                    $warnings[] = "Kota '{$city}' tidak ditemukan di alamat lokasi yang discrap.";
                }
            }
        }

        if ($brandNameMatch === false && $scrapedNames !== []) {
            $warnings[] = "Nama brand tidak cocok dengan profil yang discrap (input: '{$brandName}').";
        }

        return [
            'brand_name_match' => $brandNameMatch,
            'city_match'       => $cityMatch,
            'warnings'         => $warnings,
            'scraped_names'    => $scrapedNames,
        ];
    }

    /**
     * @param list<string> $candidates
     * @return bool|null  null when no candidates to compare
     */
    private function matchAnyName(string $input, array $candidates): ?bool
    {
        if ($candidates === []) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if ($this->namesMatch($input, $candidate)) {
                return true;
            }
        }
        return false;
    }

    private function namesMatch(string $a, string $b): bool
    {
        $na = $this->normalize($a);
        $nb = $this->normalize($b);
        if ($na === '' || $nb === '') {
            return false;
        }

        // Substring (either direction) catches "less worry" inside
        // "less worry laundry bebas worry".
        if (str_contains($na, $nb) || str_contains($nb, $na)) {
            return true;
        }

        // Shared 3+-char tokens, comparing token sets — catches
        // re-ordering and additional descriptor words.
        $tokensA = $this->tokens($na);
        $tokensB = $this->tokens($nb);
        $shared  = array_intersect($tokensA, $tokensB);
        if ($shared !== []) {
            return true;
        }

        // Normalized Levenshtein for typos / single-character drift.
        $distance = levenshtein($na, $nb);
        $maxLen   = max(strlen($na), strlen($nb));
        if ($maxLen > 0 && ($distance / $maxLen) < 0.4) {
            return true;
        }

        return false;
    }

    private function cityAppearsInAddress(string $city, string $address): bool
    {
        $needle   = $this->normalize($city);
        $haystack = $this->normalize($address);
        if ($needle === '' || $haystack === '') {
            return false;
        }
        return str_contains($haystack, $needle);
    }

    private function normalize(string $s): string
    {
        $s = strtolower(trim($s));
        // Strip common laundry-suffix noise so "Less Worry Laundry"
        // and "Less Worry" match as identity (we want them to).
        $s = preg_replace('/\b(laundry|kiloan|express|cuci|express)\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** @return list<string> */
    private function tokens(string $normalized): array
    {
        $parts = explode(' ', $normalized);
        return array_values(array_filter($parts, fn (string $t): bool => strlen($t) >= 3));
    }

    /**
     * @param array<string,mixed> $evidence
     * @return array{confidence: float, brand_name_match: bool|null, city_match: bool|null, warnings: list<string>, reasoning: string}
     */
    private function safeLlmJudgment(ClaudeService $claude, string $brandName, ?string $city, array $evidence): array
    {
        try {
            return $claude->validateBrandMatch($brandName, $city, $evidence);
        } catch (Throwable $e) {
            // ClaudeService::validateBrandMatch is supposed to be
            // never-throw — but defence-in-depth wraps it anyway.
            Log::warning('ValidateEvidenceJob: LLM judgment threw despite contract', [
                'audit_id' => $this->auditId, 'error' => $e->getMessage(),
            ]);
            return [
                'confidence'       => 0.5,
                'brand_name_match' => null,
                'city_match'       => null,
                'warnings'         => [],
                'reasoning'        => 'LLM validation skipped due to error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Combine heuristic + LLM into the canonical validation block.
     * LLM confidence is the authoritative number; heuristic findings
     * surface in warnings + populate the match flags when LLM returned
     * null (e.g. parse failure).
     *
     * @param array{brand_name_match: bool|null, city_match: bool|null, warnings: list<string>, scraped_names: list<string>} $heuristic
     * @param array{confidence: float, brand_name_match: bool|null, city_match: bool|null, warnings: list<string>, reasoning: string} $llm
     * @return array{confidence: float, brand_name_match: bool|null, city_match: bool|null, warnings: list<string>, reasoning: string, source: array{heuristic: array<string,mixed>, llm: array<string,mixed>}}
     */
    private function combine(array $heuristic, array $llm): array
    {
        $brandNameMatch = $llm['brand_name_match'] ?? $heuristic['brand_name_match'];
        $cityMatch      = $llm['city_match'] ?? $heuristic['city_match'];

        // Merge warnings, dedup keep order.
        $warnings = array_values(array_unique(array_merge($heuristic['warnings'], $llm['warnings'])));

        return [
            'confidence'       => $llm['confidence'],
            'brand_name_match' => $brandNameMatch,
            'city_match'       => $cityMatch,
            'warnings'         => $warnings,
            'reasoning'        => $llm['reasoning'],
            'source'           => [
                'heuristic' => [
                    'brand_name_match' => $heuristic['brand_name_match'],
                    'city_match'       => $heuristic['city_match'],
                    'scraped_names'    => $heuristic['scraped_names'],
                ],
                'llm' => [
                    'confidence'       => $llm['confidence'],
                    'brand_name_match' => $llm['brand_name_match'],
                    'city_match'       => $llm['city_match'],
                ],
            ],
        ];
    }

    /**
     * @param array{confidence: float, brand_name_match: bool|null, city_match: bool|null, warnings: list<string>, reasoning: string, source: array<string,mixed>} $validation
     */
    private function persistValidation(array $validation): void
    {
        DB::transaction(function () use ($validation): void {
            $audit = BrandAudit::findOrFail($this->auditId);
            $evidence = (array) ($audit->audit_evidence ?? []);
            $evidence['validation'] = $validation;

            $updates = ['audit_evidence' => $evidence];

            if ($validation['confidence'] < 0.5) {
                $updates['audit_evidence_status'] = 'validation_warning';
                // Don't override a terminal STATUS_FAILED, but if the
                // audit is still analyzing flag the validation_warning
                // up to the top-level status so PDF + dashboard banner
                // (BB60) can pick it up via $audit->hasValidationWarning().
                if ($audit->status === BrandAudit::STATUS_ANALYZING) {
                    $updates['status'] = BrandAudit::STATUS_VALIDATION_WARNING;
                }
            } else {
                $updates['audit_evidence_status'] = 'validated';
            }

            $audit->update($updates);
        });
    }
}
