<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\FetchGMapsReviewsJob;
use App\Jobs\FetchInstagramAuditJob;
use App\Jobs\GenerateActivationKit;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScorePillarsJob;
use App\Models\AuditStep;
use App\Models\BrandAudit;
use Illuminate\Bus\Batch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditController extends Controller
{
    public function status(string $token): JsonResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        // BB22: include per-step progress so external clients can build
        // their own live dashboards without scraping Livewire state. The
        // wizard view itself uses Livewire wire:poll, but this endpoint
        // gives a stable JSON contract for ops/monitoring.
        $steps = AuditStep::where('brand_audit_id', $audit->id)
            ->orderBy('order')
            ->get()
            ->map(fn ($s) => [
                'key'          => $s->step_key,
                'track'        => $s->track,
                'status'       => $s->status,
                'order'        => $s->order,
                'started_at'   => $s->started_at?->toIso8601String(),
                'completed_at' => $s->completed_at?->toIso8601String(),
                'elapsed_s'    => $s->elapsedSeconds(),
                'detail'       => $s->detail,
            ])
            ->all();

        return response()->json([
            'status'              => $audit->status,
            'overall_score'       => $audit->overall_score,
            'pillar_scores'       => collect((array) $audit->pillar_scores)
                ->mapWithKeys(fn ($data, $slug) => [
                    $slug => is_array($data) ? ($data['score'] ?? null) : null,
                ])
                ->toArray(),
            'activation_kit_path'    => $audit->activation_kit_path,
            'instagram_audit_status' => $audit->instagram_audit_status,
            'steps'                  => $steps,
        ]);
    }

    public function generateKit(string $token): JsonResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        if (! $audit->isComplete()) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'audit_not_done',
            ], 422);
        }

        GenerateActivationKit::dispatch($audit);

        return response()->json(['status' => 'queued'], 202);
    }

    /**
     * BB59: re-run a single gather step then re-flow scoring + PDF.
     * Allowed step_key values are the data-fetching gather steps —
     * scoring and final steps re-run automatically when their inputs
     * change.
     */
    public function retryStep(Request $request, string $token): JsonResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        $stepKey = (string) $request->input('step_key', '');
        $allowed = ['gather_gmaps', 'gather_instagram'];

        if (! in_array($stepKey, $allowed, true)) {
            return response()->json([
                'status' => 'rejected',
                'reason' => 'step_not_retryable',
                'allowed' => $allowed,
            ], 422);
        }

        $step = AuditStep::where('brand_audit_id', $audit->id)
            ->where('step_key', $stepKey)
            ->first();
        if ($step === null) {
            return response()->json(['status' => 'rejected', 'reason' => 'step_not_found'], 404);
        }

        // Reset the step row so the re-dispatched job's markRunning ->
        // markDone transitions start clean.
        $step->update([
            'status'       => AuditStep::STATUS_PENDING,
            'started_at'   => null,
            'completed_at' => null,
            'detail'       => null,
        ]);

        // Reset the downstream scoring + final step rows so the
        // re-run progress UI starts in pending state instead of
        // showing stale "done" badges.
        $downstreamKeys = [
            'score_recall', 'score_digital', 'score_konsistensi', 'score_experience',
            'generate_recommendations', 'generate_quick_wins', 'generate_positioning', 'generate_pdf',
        ];
        AuditStep::where('brand_audit_id', $audit->id)
            ->whereIn('step_key', $downstreamKeys)
            ->update([
                'status'       => AuditStep::STATUS_PENDING,
                'started_at'   => null,
                'completed_at' => null,
                'detail'       => null,
            ]);

        // Re-flip the audit status so the wizard view shows the
        // analyzing screen during the re-run.
        $audit->update(['status' => BrandAudit::STATUS_ANALYZING]);

        $auditId = $audit->id;
        $fetchJob = $stepKey === 'gather_gmaps'
            ? new FetchGMapsReviewsJob($auditId)
            : new FetchInstagramAuditJob($auditId);

        // Wrap the single fetch + score in a batch chain so the
        // re-run lands a fresh PDF the same way the original pipeline
        // does. allowFailures() because the re-tried gather might
        // still fail (rate limit, credentials_stale); we still want
        // to roll forward to a re-rendered PDF.
        Bus::batch([$fetchJob])
            ->name("audit:{$auditId}:retry-{$stepKey}")
            ->allowFailures()
            ->then(static function (Batch $batch) use ($auditId): void {
                Bus::batch([new ScorePillarsJob($auditId)])
                    ->name("audit:{$auditId}:retry-score")
                    ->then(static function (Batch $b2) use ($auditId): void {
                        GenerateInsightsJob::dispatch($auditId);
                    })
                    ->dispatch();
            })
            ->dispatch();

        return response()->json([
            'status' => 'queued',
            'step'   => $stepKey,
        ], 202);
    }

    /**
     * BB144 — proxy a Google Places photo through our backend so the
     * dashboard can render outlet thumbnails without leaking the
     * server-side API key into the HTML. Photo references live on
     * $audit->place_raw['photos'][$idx]['photo_reference'] (captured
     * by the wizard Step 1 selectPlace handler via PlacesApiService).
     *
     * The image binary is cached in storage/places-photos/ keyed by
     * (placeId, photoIdx, maxWidth) so a second page-view doesn't
     * re-bill us against the Google Places Photo quota. TTL: 30 days.
     *
     * Returns 404 when:
     *   - the audit token doesn't resolve
     *   - place_raw is null (legacy audit before BB90)
     *   - the index is out of bounds
     *   - Google returns a non-2xx (rate limit, ref expired)
     *
     * @return Response|StreamedResponse
     */
    public function placePhoto(Request $request, string $token, int $idx)
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        $placeRaw = is_array($audit->place_raw) ? $audit->place_raw : [];
        $photos   = is_array($placeRaw['photos'] ?? null) ? $placeRaw['photos'] : [];
        $photo    = $photos[$idx] ?? null;
        if (! is_array($photo)) {
            abort(404);
        }
        $photoRef = (string) ($photo['photo_reference'] ?? '');
        if ($photoRef === '') {
            abort(404);
        }

        $maxWidth = (int) min(800, max(120, (int) $request->input('w', 400)));
        $apiKey   = (string) config('services.google.maps_api_key', '');
        if ($apiKey === '') {
            abort(404);
        }

        $cacheKey = sprintf(
            'place_photo:%s:%d:%d',
            (string) ($audit->place_id ?? $token),
            $idx,
            $maxWidth,
        );

        // Cache the binary as base64 — Laravel cache drivers (file,
        // redis) round-trip strings reliably. 30-day TTL: photo
        // references typically rotate at a slower cadence; if Google
        // returns 404 next refresh we'll re-cache the failure as a
        // shorter-lived empty string.
        $imageData = Cache::remember($cacheKey, 60 * 60 * 24 * 30, function () use ($photoRef, $maxWidth, $apiKey): ?string {
            try {
                $response = Http::timeout(8)
                    ->withOptions(['allow_redirects' => true])
                    ->get('https://maps.googleapis.com/maps/api/place/photo', [
                        'maxwidth'        => $maxWidth,
                        'photo_reference' => $photoRef,
                        'key'             => $apiKey,
                    ]);
            } catch (\Throwable) {
                return null;
            }
            return $response->successful() ? $response->body() : null;
        });

        if (! is_string($imageData) || $imageData === '') {
            abort(404);
        }

        return response($imageData, 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400, stale-while-revalidate=604800',
        ]);
    }

    /**
     * BB131 — stream the Instagram scrape screenshot as proof the real
     * profile was captured. The PNG lives on the PRIVATE ``local`` disk at
     * audits/{id}/instagram/screenshot.png (persisted by
     * InstagramProfileAuditService::persistInstagramAssets); its path is
     * recorded in instagram_audit._meta.screenshot_path.
     *
     * Access control mirrors kit/download + place-photo: the unguessable
     * session_token in the URL is the capability. We deliberately do NOT
     * move screenshots to the public disk — they stay private and are only
     * reachable through this token-scoped route.
     *
     * 404 when: token doesn't resolve, no screenshot_path recorded, or the
     * file is missing on disk (legacy audit / failed scrape).
     *
     * @return StreamedResponse|Response
     */
    public function instagramScreenshot(string $token)
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        $payload = is_array($audit->instagram_audit) ? $audit->instagram_audit : [];
        $meta    = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $path    = (string) ($meta['screenshot_path'] ?? '');

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        // Inline (not download) so the dashboard <img> renders it. Private
        // Cache-Control — never let a shared proxy cache an audit screenshot.
        return Storage::disk('local')->response($path, 'instagram-screenshot.png', [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function downloadKit(string $token): StreamedResponse
    {
        $audit = BrandAudit::where('session_token', $token)->firstOrFail();

        if (! $audit->activation_kit_path || ! Storage::disk('local')->exists($audit->activation_kit_path)) {
            abort(404);
        }

        $brandSlug = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $audit->brand_name) ?: 'brand';
        $filename  = "activation-kit-{$brandSlug}.pdf";

        return Storage::disk('local')->download($audit->activation_kit_path, $filename);
    }
}
