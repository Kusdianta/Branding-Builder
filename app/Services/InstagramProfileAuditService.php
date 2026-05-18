<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BrandAudit;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Nema\WorkerClient\DTO\Highlight;
use Nema\WorkerClient\DTO\InstagramProfileAudit;
use Nema\WorkerClient\DTO\ProfileMetadata;
use Nema\WorkerClient\DTO\RecentPost;
use Nema\WorkerClient\Exceptions\ProfileAuditException;
use Nema\WorkerClient\Exceptions\WorkerAuthException;
use Nema\WorkerClient\Exceptions\WorkerNotAvailableException;
use Nema\WorkerClient\NemaWorkerClient;
use RuntimeException;
use Throwable;

/**
 * Phase 7-B orchestrator: fetch a healthy IG credential from Hub, call the
 * worker for a comprehensive profile audit, hand the result to ClaudeService
 * for apikprimadya-style analysis, persist the payload + status on the
 * BrandAudit row.
 *
 * BB69 (Phase 11): the prior atomic audit() pass is now split into two
 * public methods that the pipeline can drive as separate jobs:
 *
 *   scrape()  — credential fetch + worker scrape + visual-asset persistence.
 *               On success, sets instagram_audit_status='scraped' and writes
 *               a sanitized DTO snapshot to evidence.instagram_audit.raw_payload
 *               so the Claude analysis step can run in a separate job.
 *
 *   analyze() — reconstitutes the DTO from the persisted snapshot, calls
 *               Claude, persists the full analysis payload and flips status
 *               to 'done'. No-op when scrape didn't produce a 'scraped' state.
 *
 *   audit()   — backward-compat orchestrator (scrape() then analyze() inline)
 *               kept so existing callers and tests don't need to migrate.
 *               BB71 pipeline rewires the two phases to run as parallel
 *               batches; this entry point will be retained for ad-hoc
 *               regeneration commands.
 *
 * NEVER throws back to AnalyzeBrand — every failure mode lands on the
 * BrandAudit row as a status enum value + (when applicable) an `error`-shaped
 * instagram_audit payload. Audit pipeline continues with other pillars
 * regardless of what happens here.
 *
 * Status enum (mirror the schema spec):
 *
 *  - pending                      → initial migration default; never written here
 *  - scraped                      → BB69: worker pass succeeded, awaiting analyze()
 *  - done                         → success, instagram_audit populated
 *  - no_instagram_url_provided    → wizard didn't capture one or it was unparseable
 *  - no_credentials_available     → Hub has no healthy IG credential
 *  - credentials_stale            → login_wall_hit on every attempt; operator
 *                                   action required (Bootstrap Sesi / paste new
 *                                   Cookie-Editor export)
 *  - rate_limited                 → worker rate-limited this username
 *  - profile_not_found            → IG handle 404
 *  - audit_failed                 → catch-all; detail in instagram_audit.error
 */
class InstagramProfileAuditService
{
    /**
     * Hardcoded by spec — 1 fallback credential attempt on login_wall_hit.
     * Higher values risk burning multiple credentials on a single bad
     * request. The Hub claims-by-last_used_at ordering means the second
     * fetch naturally returns a different row when more than one healthy
     * credential exists; we still defensively de-dup by id.
     */
    private const MAX_CREDENTIAL_ATTEMPTS = 2;

    public function __construct(
        private readonly NemaWorkerClient $worker,
        private readonly HubCredentialsClient $hub,
        private readonly ClaudeService $claude,
        private readonly IgUsernameExtractor $extractor,
    ) {}

    /**
     * BB69: backward-compat orchestrator. Equivalent to scrape() then
     * analyze() when scrape produced a 'scraped' state.
     */
    public function audit(BrandAudit $audit): void
    {
        $this->scrape($audit);

        $audit->refresh();
        if ((string) $audit->instagram_audit_status === 'scraped') {
            $this->analyze($audit);
        }
    }

    /**
     * BB69: phase-1 entry. Performs the worker scrape + visual asset
     * persistence and writes a sanitized DTO snapshot to evidence so the
     * separate analyze() pass can run without re-scraping.
     */
    public function scrape(BrandAudit $audit): void
    {
        $touchpoints  = (array) $audit->touchpoints;
        $instagramUrl = (string) ($touchpoints['instagram_url'] ?? '');

        $username = $this->extractor->extract($instagramUrl);
        if ($username === null) {
            $this->persistStatus($audit, 'no_instagram_url_provided');
            return;
        }

        $usedCredentialIds = [];

        for ($attempt = 1; $attempt <= self::MAX_CREDENTIAL_ATTEMPTS; $attempt++) {
            $credential = $this->fetchCredential($audit);

            if ($credential === null) {
                $status = $attempt === 1 ? 'no_credentials_available' : 'credentials_stale';
                $this->persistStatus($audit, $status);
                return;
            }

            $credentialId = (string) ($credential['id'] ?? '');
            if ($credentialId === '' || in_array($credentialId, $usedCredentialIds, true)) {
                $this->persistStatus($audit, 'credentials_stale');
                return;
            }
            $usedCredentialIds[] = $credentialId;

            $sessionCookies = $credential['session_cookies'] ?? null;
            if (! is_array($sessionCookies) || $sessionCookies === []) {
                Log::warning('InstagramProfileAuditService: credential has empty session_cookies', [
                    'audit_id'      => $audit->id,
                    'credential_id' => $credentialId,
                ]);
                $this->reportCredentialStale($credentialId, 'empty session_cookies in credential record');
                if ($attempt < self::MAX_CREDENTIAL_ATTEMPTS) {
                    continue;
                }
                $this->persistStatus($audit, 'credentials_stale');
                return;
            }

            try {
                $result = $this->worker->auditInstagramProfile($username, $sessionCookies);
            } catch (ProfileAuditException $e) {
                $resolution = $this->handleProfileAuditException(
                    $audit,
                    $e,
                    $credentialId,
                    isLastAttempt: $attempt >= self::MAX_CREDENTIAL_ATTEMPTS,
                );
                if ($resolution === 'retry') {
                    continue;
                }
                return;
            } catch (WorkerAuthException $e) {
                Log::error('InstagramProfileAuditService: worker auth rejected', [
                    'audit_id' => $audit->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, 'worker_auth_failed: ' . $e->getMessage());
                return;
            } catch (WorkerNotAvailableException $e) {
                Log::warning('InstagramProfileAuditService: worker unreachable', [
                    'audit_id' => $audit->id,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, 'worker_unavailable: ' . $e->getMessage());
                return;
            } catch (\Nema\WorkerClient\Exceptions\WorkerException $e) {
                // BB109 — WorkerException now carries httpStatus + rawBody
                // + parsedBody. The diagnostic() summary lands in the
                // log AND the audit_steps detail so the operator can
                // tell the difference between a Playwright crash
                // (error_code=internal_error, exception_type=
                // NotImplementedError → restart worker), a stale-cookie
                // failure (error_code=login_wall_hit), and a worker
                // bug (any other internal_error). Pre-BB109 they all
                // logged as "unexpected" with no useful diagnostic.
                $diag = $e->diagnostic();
                Log::error('InstagramProfileAuditService: worker error', [
                    'audit_id' => $audit->id,
                ] + $diag);
                $code = $diag['error_code']
                    ?? $diag['exception_type']
                    ?? 'worker_error';
                $this->persistFailure($audit, $code . ': ' . $e->getMessage());
                return;
            } catch (Throwable $e) {
                Log::warning('InstagramProfileAuditService: unexpected error', [
                    'audit_id' => $audit->id,
                    'class'    => $e::class,
                    'error'    => $e->getMessage(),
                ]);
                $this->persistFailure($audit, $e->getMessage());
                return;
            }

            $this->persistScrapeResult($audit, $result);
            return;
        }

        $this->persistStatus($audit, 'credentials_stale');
    }

    /**
     * BB69: phase-2 entry. Reads the scrape snapshot persisted by scrape(),
     * reconstitutes the DTO, calls Claude, and writes the final analysis.
     * Idempotent: skips when status is anything other than 'scraped'.
     */
    public function analyze(BrandAudit $audit): void
    {
        $status = (string) $audit->instagram_audit_status;
        if ($status !== 'scraped') {
            // Either scrape didn't succeed (terminal status already set) or
            // analyze() already ran ('done'). Either way, nothing to do.
            return;
        }

        $snapshot = $this->readScrapeSnapshot($audit);
        if ($snapshot === null) {
            // Defensive: status='scraped' without a snapshot is an
            // inconsistent state. Mark failed so the pipeline doesn't
            // hang forever expecting an analyze pass.
            $this->persistFailure($audit, 'analyze_called_without_snapshot');
            return;
        }

        try {
            $result = $this->reconstituteDto($snapshot);
        } catch (Throwable $e) {
            Log::error('InstagramProfileAuditService::analyze: DTO reconstitution failed', [
                'audit_id' => $audit->id,
                'error'    => $e->getMessage(),
            ]);
            $this->persistFailure($audit, 'snapshot_corrupt: ' . $e->getMessage());
            return;
        }

        $meta = (array) ($snapshot['_meta'] ?? []);

        // BB66: tag the IG analyze Claude call with the audit id.
        $this->claude->setAuditContext($audit->id);

        try {
            $analysis = $this->claude->analyzeInstagramProfile(
                $result,
                (string) $audit->brand_name,
                (string) $audit->service_type,
                $audit->city !== null && $audit->city !== '' ? (string) $audit->city : null,
            );
            $analysis['_meta'] = $meta;
            $audit->update([
                'instagram_audit_status' => 'done',
                'instagram_audit'        => $analysis,
            ]);
        } catch (Throwable $e) {
            Log::error('InstagramProfileAuditService::analyze: Claude analysis failed', [
                'audit_id' => $audit->id,
                'class'    => $e::class,
                'error'    => $e->getMessage(),
            ]);
            $this->persistFailure($audit, 'claude_analysis_failed: ' . $e->getMessage());
        }
    }

    /**
     * Decide what to do with a ProfileAuditException. Returns:
     *  - 'retry'         caller should `continue` the credential loop
     *  - 'done-handling' caller should `return` (status already persisted)
     */
    private function handleProfileAuditException(
        BrandAudit $audit,
        ProfileAuditException $e,
        string $credentialId,
        bool $isLastAttempt,
    ): string {
        Log::info('InstagramProfileAuditService: ProfileAuditException', [
            'audit_id'      => $audit->id,
            'credential_id' => $credentialId,
            'error_code'    => $e->errorCode,
            'http_status'   => $e->httpStatus,
            'detail'        => $e->detail,
        ]);

        switch ($e->errorCode) {
            case 'login_wall_hit':
                $this->reportCredentialStale(
                    $credentialId,
                    'login_wall_hit during Phase 7-B profile audit',
                );
                if (! $isLastAttempt) {
                    return 'retry';
                }
                $this->persistStatus($audit, 'credentials_stale');
                return 'done-handling';

            case 'interstitial_blocked':
                $this->reportCredentialStale(
                    $credentialId,
                    'interstitial_blocked: ' . ($e->detail ?? 'Bloks chooser redirect'),
                );
                if (! $isLastAttempt) {
                    return 'retry';
                }
                $this->persistStatus($audit, 'credentials_stale');
                return 'done-handling';

            case 'rate_limited':
                $this->persistStatus($audit, 'rate_limited');
                return 'done-handling';

            case 'profile_not_found':
                $this->persistStatus($audit, 'profile_not_found');
                return 'done-handling';

            default:
                $detail = $e->detail !== null && $e->detail !== ''
                    ? $e->errorCode . ': ' . $e->detail
                    : $e->errorCode;
                $this->persistFailure($audit, $detail);
                return 'done-handling';
        }
    }

    /**
     * BB69: persist the scrape result + sanitized snapshot. The snapshot
     * drops the heavy base64 fields (visual assets are already on disk via
     * persistInstagramAssets); analyze() doesn't need them for Claude's
     * text-based analysis path. The on-disk assets are still available to
     * KonsistensiScorer's vision path via _meta.
     */
    private function persistScrapeResult(BrandAudit $audit, InstagramProfileAudit $result): void
    {
        $meta = $this->persistInstagramAssets($audit, $result);

        $snapshot = $this->buildScrapeSnapshot($result, $meta);

        $audit->update([
            'instagram_audit_status' => 'scraped',
            'instagram_audit'        => $snapshot,
        ]);
    }

    /**
     * BB69: build a sanitized snapshot of the DTO + _meta for inter-job
     * persistence. Drops base64 fields (recoverable via _meta paths on
     * disk). Keeps everything analyze() needs to reconstitute the DTO and
     * everything FetchInstagramAuditJob's evidence mirror needs.
     *
     * @return array<string,mixed>
     */
    private function buildScrapeSnapshot(InstagramProfileAudit $result, array $meta): array
    {
        return [
            'raw_payload' => [
                'username'     => $result->username,
                'captured_at'  => $result->capturedAt->format(\DateTimeInterface::ATOM),
                'is_private'   => $result->isPrivate,
                'duration_ms'  => $result->durationMs,
                // BB117 — story-ring presence. Carried at the raw_payload
                // top level so InstagramActivityScorer (v3) can read it
                // alongside recent_posts without re-walking the slice.
                'has_active_story' => $result->hasActiveStory,
                'profile'      => [
                    'name'          => $result->profile->name,
                    'bio'           => $result->profile->bio,
                    'external_url'  => $result->profile->externalUrl,
                    'followers'     => $result->profile->followers,
                    'following'     => $result->profile->following,
                    'posts_count'   => $result->profile->postsCount,
                    'is_verified'   => $result->profile->isVerified,
                    'is_business'   => $result->profile->isBusiness,
                ],
                'profile_pic_fetch_error' => $result->profilePicFetchError,
                'recent_posts' => array_map(static fn (RecentPost $p): array => [
                    'shortcode'       => $p->shortcode,
                    'url'             => $p->url,
                    'type'            => $p->type,
                    'caption'         => $p->caption,
                    'approximate_age' => $p->approximateAge,
                ], $result->recentPosts),
                'highlights'   => array_map(static fn (Highlight $h): array => [
                    'name' => $h->name,
                    // coverBase64 dropped on purpose — large field, not
                    // read by Claude text analysis; cover images aren't
                    // persisted to disk in BB13.
                ], $result->highlights),
            ],
            '_meta' => $meta,
        ];
    }

    /**
     * BB69: read the snapshot persisted by scrape(). Returns null when
     * the current instagram_audit JSON is not in snapshot shape (e.g. an
     * old 'done' audit being re-analyzed via the legacy audit() entry).
     *
     * @return array<string,mixed>|null
     */
    private function readScrapeSnapshot(BrandAudit $audit): ?array
    {
        $payload = $audit->instagram_audit;
        if (! is_array($payload)) {
            return null;
        }
        if (! isset($payload['raw_payload']) || ! is_array($payload['raw_payload'])) {
            return null;
        }
        return $payload;
    }

    /**
     * BB69: rebuild the InstagramProfileAudit DTO from a persisted
     * snapshot. Base64 fields are restored as empty strings — they were
     * dropped from the snapshot; analyze()'s Claude call doesn't need
     * them (text-only analysis), and KonsistensiScorer reads visual
     * assets via _meta paths on disk.
     *
     * @param array<string,mixed> $snapshot
     */
    private function reconstituteDto(array $snapshot): InstagramProfileAudit
    {
        $raw = (array) ($snapshot['raw_payload'] ?? []);
        $profile = (array) ($raw['profile'] ?? []);

        return new InstagramProfileAudit(
            username: (string) ($raw['username'] ?? ''),
            capturedAt: new DateTimeImmutable((string) ($raw['captured_at'] ?? 'now')),
            isPrivate: (bool) ($raw['is_private'] ?? false),
            profile: new ProfileMetadata(
                name: (string) ($profile['name'] ?? ''),
                bio: (string) ($profile['bio'] ?? ''),
                externalUrl: $profile['external_url'] ?? null,
                followers: (int) ($profile['followers'] ?? 0),
                following: (int) ($profile['following'] ?? 0),
                postsCount: (int) ($profile['posts_count'] ?? 0),
                isVerified: (bool) ($profile['is_verified'] ?? false),
                isBusiness: (bool) ($profile['is_business'] ?? false),
                profilePicBase64: '',
            ),
            profilePicFetchError: $raw['profile_pic_fetch_error'] ?? null,
            screenshotBase64: '',
            recentPosts: array_map(
                static fn (array $p): RecentPost => new RecentPost(
                    shortcode: (string) ($p['shortcode'] ?? ''),
                    url: (string) ($p['url'] ?? ''),
                    type: (string) ($p['type'] ?? 'image'),
                    thumbnailBase64: '',
                    caption: $p['caption'] ?? null,
                    approximateAge: $p['approximate_age'] ?? null,
                ),
                (array) ($raw['recent_posts'] ?? []),
            ),
            highlights: array_map(
                static fn (array $h): Highlight => new Highlight(
                    name: (string) ($h['name'] ?? ''),
                    coverBase64: '',
                ),
                (array) ($raw['highlights'] ?? []),
            ),
            durationMs: (int) ($raw['duration_ms'] ?? 0),
        );
    }

    /**
     * BB13: persist the worker payload's visual assets + return a structured
     * profile-metadata record for the PDF + dashboard renderers.
     *
     * Files land on the ``local`` disk (storage/app/private/) under
     * ``audits/{audit_id}/instagram/`` so they're private by default
     * (never web-served, only readable from server-side render paths).
     *
     * @return array<string,mixed>
     */
    private function persistInstagramAssets(BrandAudit $audit, InstagramProfileAudit $result): array
    {
        $basePath = "audits/{$audit->id}/instagram";
        $disk     = Storage::disk('local');

        $meta = [
            'username'             => $result->username,
            'name'                 => $result->profile->name,
            'bio'                  => $result->profile->bio,
            'external_url'         => $result->profile->externalUrl,
            'followers'            => $result->profile->followers,
            'following'            => $result->profile->following,
            'posts_count'          => $result->profile->postsCount,
            'is_verified'          => $result->profile->isVerified,
            'is_business'          => $result->profile->isBusiness,
            'is_private'           => $result->isPrivate,
            'captured_at'          => $result->capturedAt->format(\DateTimeInterface::ATOM),
            'duration_ms'          => $result->durationMs,
            'profile_pic_path'     => null,
            'screenshot_path'      => null,
            'post_thumbnail_paths' => [],
            'highlight_names'      => array_map(
                static fn ($h) => $h->name,
                $result->highlights,
            ),
        ];

        $picBase64 = $result->profile->profilePicBase64;
        if ($picBase64 !== '') {
            $bytes = base64_decode($picBase64, true);
            if ($bytes !== false && $bytes !== '') {
                $path = "{$basePath}/profile_pic.jpg";
                $disk->put($path, $bytes);
                $meta['profile_pic_path'] = $path;
            }
        }

        if ($result->screenshotBase64 !== '') {
            try {
                $bytes = $result->screenshotBytes();
                if ($bytes !== '') {
                    $path = "{$basePath}/screenshot.png";
                    $disk->put($path, $bytes);
                    $meta['screenshot_path'] = $path;
                }
            } catch (RuntimeException) {
                // Malformed base64 — diagnostic only, skip.
            }
        }

        $thumbPaths = [];
        foreach (array_slice($result->recentPosts, 0, 6) as $idx => $post) {
            if ($post->thumbnailBase64 === '') {
                continue;
            }
            try {
                $bytes = $post->thumbnailBytes();
                if ($bytes === '') {
                    continue;
                }
                $path = "{$basePath}/posts/{$idx}.png";
                $disk->put($path, $bytes);
                $thumbPaths[$idx] = $path;
            } catch (RuntimeException) {
                continue;
            }
        }
        $meta['post_thumbnail_paths'] = $thumbPaths;

        return $meta;
    }

    /** @return array<string,mixed>|null */
    private function fetchCredential(BrandAudit $audit): ?array
    {
        try {
            return $this->hub->getNextCredential('instagram');
        } catch (Throwable $e) {
            Log::warning('InstagramProfileAuditService: Hub credentials fetch failed', [
                'audit_id' => $audit->id,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function reportCredentialStale(string $credentialId, string $reason): void
    {
        try {
            $this->hub->reportCredentialStatus($credentialId, 'requires_2fa', $reason);
        } catch (Throwable $e) {
            Log::warning('InstagramProfileAuditService: failed to report credential status', [
                'credential_id' => $credentialId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    private function persistStatus(BrandAudit $audit, string $status): void
    {
        $audit->update([
            'instagram_audit_status' => $status,
            'instagram_audit'        => null,
        ]);
    }

    private function persistFailure(BrandAudit $audit, string $detail): void
    {
        $audit->update([
            'instagram_audit_status' => 'audit_failed',
            'instagram_audit'        => ['error' => $detail],
        ]);
    }
}
