# Branding Builder

> AI Brand Health Check for laundry brands — a touchpoint-driven 4-pillar audit wizard.

## What it does

The user submits their brand touchpoints (Google Place ID, Instagram handle, optional TikTok handle, website URL, and outlet photos) through a 4-step wizard. The app dispatches a pipeline of Laravel jobs that scrape each touchpoint via the FastAPI worker (IG, TikTok, Google Maps reviews, website) and score four pillars — Brand Konsistensi, Brand Recall, Brand Experience, and Digital Presence — using Claude. Results are shown in an interactive dashboard with charts; a downloadable activation-kit PDF is generated at the end. Users sign in through the **Hub SSO gateway** (centralized Google login — this spoke no longer talks to Google directly) and consume credits per audit.

## Tech Stack

| Layer | Package / Version |
|---|---|
| Runtime | PHP 8.3, Laravel 13 |
| Reactivity | Livewire 4 + Volt 1 |
| Frontend build | Vite 8 + Tailwind 4 |
| Charts | chart.js ^4.5 |
| Places autocomplete | places-autocomplete-js ^1.2 |
| AI | anthropic-ai/sdk ^0.8 |
| PDF | barryvdh/laravel-dompdf ^3.1 |
| Auth | Hub SSO (signed-token validation; no `laravel/socialite`) |
| Shared UI | nema/ui-kit (path, local) |
| Worker client | nema/worker-client (path, local) |
| Database | SQLite (WAL mode) |

## Architecture

Spoke in the Nema hub-and-spoke platform. Depends on the FastAPI scraping worker (`workers/nema-worker`) via the `nema/worker-client` package for Instagram, TikTok, Google Maps reviews, and website scraping + Playwright screenshots. Operator credentials (Instagram cookies, API keys) are read from the shared vault (`vault/branding-builder.json`) via `VaultServiceProvider`. Usage data and audit timing metrics are reported back to the Hub via `/api/internal/*` (bearer-guarded). User login is delegated to the **Hub SSO gateway** (the Hub owns Google OAuth); this spoke validates the Hub's signed token with `SsoTokenValidator` and links a local user by `hub_user_id` → `google_id` → `email`. Each user has a per-audit credit balance managed by `CreditLedger`.

## Routes

### Web (`routes/web.php`)

| Method | URI | Purpose |
|---|---|---|
| GET | `/health` | Liveness probe |
| GET | `/api/health/platform` | Platform health — auth session |
| GET | `/api/internal/health/platform` | Platform health — shared worker token (Hub) |
| GET | `/` | Audit wizard (Volt `brand-audit-wizard`) |
| GET | `/audits` | Per-user audit history (auth) |
| GET | `/audit/{token}` | Audit dashboard (same Volt component) |
| GET | `/audit/{token}/status` | Poll pipeline status (JSON) |
| POST | `/audit/{token}/kit` | Trigger kit generation |
| GET | `/audit/{token}/kit/download` | Download PDF |
| POST | `/audit/{token}/retry-step` | Retry a gather step + re-score |
| GET | `/audit/{token}/place-photo/{idx}` | Proxy Google Places photo (throttle 60/min) |
| GET | `/audit/{token}/instagram/screenshot/{section?}` | Stream IG screenshot (private disk) |
| POST | `/check-handle/instagram` | Handle availability check (throttle 30/min) |
| POST | `/check-handle/tiktok` | Handle availability check (throttle 30/min) |
| GET | `/auth/login` | Bounce to the Hub SSO gateway (`SsoCallbackController@login`) |
| GET | `/auth/sso/callback` | Validate Hub token + establish session |
| POST | `/auth/logout` | Local logout + notify Hub for platform-wide logout |

### API (`routes/api.php`) — Hub bearer (`hub.users` middleware)

`GET /internal/users`, `GET /internal/users/{id}`, `POST /internal/users/{id}/credits/adjust`, `DELETE /internal/users/{id}`, `POST /internal/auth/logout` (Hub broadcasts platform-wide logout to this spoke)

### Auth (Hub SSO)

This spoke no longer runs Google OAuth. `app/Http/Controllers/Auth/SsoCallbackController.php` bounces unauthenticated users to `HUB_SSO_URL` (the Hub's `/auth/sso/redirect`) with `?spoke=branding-builder&callback=<this spoke's /auth/sso/callback>`. The Hub signs the user in and redirects back with a `?sso_token=`; `app/Services/SsoTokenValidator.php` verifies the HMAC signature + 60s expiry against `SSO_SHARED_SECRET`. Logout calls `HubSsoClient::notifyLogout()` so the Hub clears the platform-wide session. See the root `CLAUDE.md` "SSO Architecture" and `nema-hub/RUNBOOK.md`.

## Job Pipeline

`app/Jobs/` — approximate execution order:

1. `AnalyzeBrand` — entry point, dispatches gather phase
2. `GatherEvidenceJob` — orchestrates parallel gather jobs
3. Parallel gather: `FetchInstagramAuditJob` (worker `/v1/instagram/profile-audit`), `FetchGMapsReviewsJob`, `FetchPlacesApiJob`, `FetchWebsiteJob`, `ExtractServiceSignalsJob`
4. `AnalyzeInstagramJob` — Claude analysis of raw IG data
5. `ValidateEvidenceJob` → `ScorePillarJob` (×4 pillars) → `ScorePillarsJob`
6. `AggregateAuditJob` — merges scores
7. `AnalysisOrchestratorJob` / `GenerateInsightsJob` — recommendations + positioning via Claude
8. `GeneratePdfJob` — renders PDF, sole setter of `status = done`
9. `GenerateActivationKit` — standalone re-trigger via `POST /audit/{token}/kit`

`GenerateInsightsJob::failed()` is the safety net if PDF generation fails.

## Services

### Top-level (`app/Services/`)

`ClaudeService` — anthropic-ai/sdk wrapper (scoring + IG analysis prompts) | `CreditLedger` — per-user credit charges/refunds | `EvidenceMapper` — raw scrape → canonical evidence | `GMapsReviewsService` — worker GMaps call + normalisation | `HubCredentialsClient` — fetch operator IG/TT creds from Hub | `HubUsageLogger` — report audit timings/usage to Hub `/api/internal/*` | `IgUsernameExtractor` — normalise IG handle input | `InstagramProfileAuditService` — IG worker call + credential fallback | `PlacesApiService` — Google Places details/autocomplete/photos | `PlatformHealthChecker` — worker/queue/DB/Places health probe | `TargetScoreCalculator` — per-pillar target score from rubric

### Subdirectories

- `Fetchers/` — `GoogleMapsReviewsFetcher`, `WebsiteFetcher`, `TouchpointPresenceDetector`
- `HandleCheckers/` — `InstagramHandleChecker`, `TikTokHandleChecker`, `TikTokHandleCheckerLegacy` (+ result DTOs)
- `Scoring/` — one scorer per pillar sub-dimension: `KonsistensiScorer`, `RecallScorer`, `ExperienceScorer`, `DigitalPresenceScorer`, `InstagramActivityScorer`, `OwnerReplyRateScorer`, `SearchRecallScorer`, penalty/signal detectors, `WebsiteLivenessScorer` + `Support/`
- `Recommendation/` — `AbstractClaudeGenerator`, `RecommendationGenerator`, `QuickWinsGenerator`, `CompetitivePositioningGenerator`, `TargetScoreReasoningGenerator`

## Database

SQLite (`database/database.sqlite`), WAL mode + `synchronous=NORMAL` enabled on first migration. All PKs are ULIDs.

Key tables: `users` (Google OAuth, credit balance), `brand_audits` (core record — inputs, scores, evidence JSON, status, PDF path, IG/GMaps/insight columns, operator declarations), `audit_steps` (per-job pipeline log), `scoring_rubrics`, `brand_kits`, `credit_adjustments`. Standard Laravel `jobs` and `cache` tables also present.

## Environment Variables

Credentials are loaded from `vault/branding-builder.json` via `VaultServiceProvider`. `env()` values are local-dev fallbacks. Only `APP_KEY`, `APP_URL`, `DB_DATABASE` must be in `.env` directly.

| Variable | Group | Notes |
|---|---|---|
| `ANTHROPIC_API_KEY` | Anthropic | Claude API key |
| `ANTHROPIC_MODEL` | Anthropic | Pillar scoring model (default `claude-sonnet-4-6`) |
| `ANTHROPIC_MODEL_ANALYSIS` | Anthropic | IG analysis model; can differ |
| `GOOGLE_MAPS_API_KEY` | Google | Places API + photo proxy (separate key from the Hub's OAuth client) |
| `GOOGLE_MAPS_COUNTRY_BIAS` | Google | Autocomplete country bias (default `id`) |
| `SSO_SHARED_SECRET` | SSO | HMAC secret for the Hub's signed token — must equal the Hub's value (deploy-time, `.env` not vault) |
| `HUB_SSO_URL` | SSO | Hub SSO entry point (default `http://nema-hub.test/auth/sso/redirect`) |
| `NEMA_WORKER_URL` | Worker | FastAPI worker base URL |
| `NEMA_WORKER_API_KEY` | Worker | Worker bearer token |
| `NEMA_WORKER_TIMEOUT` | Worker | HTTP timeout seconds (default `10.0`) |
| `HUB_URL` | Hub | Hub base URL (default `http://nema-hub.test`) |
| `HUB_INBOUND_API_KEY` | Hub | Bearer for spoke→Hub calls |
| `HUB_USERS_API_KEY` | Hub | Bearer for Hub→spoke users API |
| `HUB_TIMEOUT` | Hub | Hub HTTP timeout seconds (default `10.0`) |
| `QUEUE_CONNECTION` | Queue | Must be `database` |
| `DB_QUEUE_RETRY_AFTER` | Queue | Set ≥660 to avoid re-running 600 s GMaps jobs |
| `WIZARD_SHOW_TIKTOK` | Flag | Show TikTok field in wizard Step 3 (default `true`) |

## Dev Setup & Queue Workers

See `./RUNBOOK.md` for failure diagnosis and cookie refresh procedures. See `../SETUP.md` for monorepo bootstrap.

**Start everything (3 queue workers + Vite + Python worker):**

```powershell
composer dev
```

Runs 3 × `queue:work --tries=1 --timeout=600`, Vite, and the Python worker via `run_dev.py`. The worker **must** use `run_dev.py` (Windows `ProactorEventLoop` patch for Playwright) — bare `uvicorn` breaks Playwright.

**Start without the Python worker (if worker is running separately):**

```powershell
composer dev:no-worker
```

**Durable workers** (auto-start on Windows login): `scripts/run-queue-worker.ps1` tops up to `BB_QUEUE_WORKERS` workers (default 3 dev / 2 prod), `--timeout=600`.

**Run tests:**

```powershell
composer test
```
