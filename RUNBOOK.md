# Branding Builder — Operator Runbook

How to diagnose audit-pipeline failures without grep-ing logs for an hour.

---

## Failure family: "Audit Instagram gagal karena error teknis"

User-facing symptom on `/audit/{token}`:
> ⚠ Audit Instagram gagal karena error teknis
> Scrape ulasan gagal

### Step 1 — Read `audit_steps.detail` first

Since BB109, every failed gather step writes a structured diagnostic into
`audit_steps.detail` JSON. Open tinker and inspect:

```bash
php artisan tinker --execute='
  $a = App\Models\BrandAudit::orderBy("created_at","desc")->first();
  foreach ($a->steps as $s) {
    echo str_pad($s->step_key, 28) . " " . str_pad($s->status, 8)
       . " " . json_encode($s->detail) . PHP_EOL;
  }
'
```

The `detail` JSON tells you exactly what failed. Branch from there:

| `detail` contents                            | Root cause                                                    | Fix                                          |
|---------------------------------------------|---------------------------------------------------------------|----------------------------------------------|
| `"error":"internal_error: NotImplementedError"` | Worker Playwright spawn crash (Windows asyncio bug)            | Restart `composer dev` (see Step 2)          |
| `"error":"login_wall_hit: ..."`              | IG session cookies expired                                    | Refresh cookies in Hub admin (see Step 3)    |
| `"error":"interstitial_blocked: ..."`        | IG served an "Open in app" interstitial cookies couldn't bypass | Refresh cookies                              |
| `"error":"credentials_stale: ..."`           | Google session cookies for GMaps expired                      | Refresh in Hub admin                         |
| `"error":"rate_limited: ..."`                | Worker hit IG's per-handle 5-min cooldown                     | Wait + retry, or add a fresh credential      |
| `"status":"scrape_failed"` with no `error`   | Pre-BB109 audit — re-run and inspect the new detail           | Naufal: re-trigger the audit, then retry this table |

### Step 2 — If `NotImplementedError`: restart `composer dev`

The worker MUST be launched via `run_dev.py` (which monkey-patches uvicorn's
loop factory to use `ProactorEventLoop` — required for Playwright on Windows).

Bare `uvicorn ... --reload` does NOT apply the patch in the reload child, so
every Playwright call dies with `NotImplementedError`. Symptom: 500 with
`{"error":"internal_error","exception_type":"NotImplementedError"}`.

BB109 fixed `composer.json` so `composer dev` now launches the worker via
`run_dev.py`. To activate after pulling BB109:

1. Ctrl+C the running `composer dev` terminal.
2. Run `composer dev` again.
3. Verify the worker name in the concurrently output still says `worker`.
4. In a new terminal: `curl http://localhost:9878/health` — should be 200.
5. Test Playwright by submitting a fresh audit through the wizard.

### Step 3 — If `login_wall_hit` / `credentials_stale`: refresh cookies

IG and Google session cookies in the Hub vault have a finite lifespan
(roughly 7–14 days for IG, similar for Google). When they expire, every
audit fails the same way.

To refresh:
1. Open the Hub admin: `https://nema.creativeapq.online/admin` (or
   `http://nema-hub.test/admin` locally).
2. Navigate to **Credentials → Instagram** (or Google).
3. Click **Re-bootstrap** → log into the account in the popup → confirm
   the captured cookies look right (sessionid present, ds_user_id set).
4. Status flips back from `stale` to `active`.
5. Retry the failed audit.

Per BB75: keep at least 2 active credentials per platform so a single
expiry doesn't take the pipeline offline.

---

## Diagnostic flow chart

```
audit fails
  │
  ├─ Check `audit_steps.detail` for the failed step (Step 1)
  │
  ├─ `error_code` present?
  │     YES → see the table above
  │     NO  → check `body_excerpt` for raw worker output
  │
  ├─ Worker /health reachable?
  │     NO  → composer dev died; restart
  │     YES → continue
  │
  ├─ Probe the worker directly with curl:
  │     POST /v1/instagram/profile-audit with a fresh-looking fake username
  │     500 NotImplementedError → Step 2 (restart composer dev)
  │     422 Pydantic error      → branding-builder schema drift; check FetchInstagramAuditJob
  │     200 with empty profile  → Playwright works but cookies aren't authenticated → Step 3
  │
  └─ Still stuck? Check `storage/logs/laravel.log` for the most recent
     `InstagramProfileAuditService: worker error` entry; it carries the full
     `diagnostic()` payload including `trace_id`. Grep the worker log
     (workers/nema-worker/worker.log or stdout) for that `trace_id` to
     recover the full Python traceback.
```

---

## Long-term failure modes added in BB109

These exist so future Claude sessions and future-Naufal don't waste hours
re-diagnosing the same bugs:

- **Structured 500s from the worker.** Every uncaught exception now returns
  `{error, detail, trace_id, exception_type}` JSON. No more "Internal Server
  Error" black holes. See `workers/nema-worker/app/main.py` exception handler.

- **`WorkerException.diagnostic()` on the client.** PHP-side
  `Nema\WorkerClient\Exceptions\WorkerException` exposes `httpStatus`,
  `rawBody`, `parsedBody`, `errorCode()`, `traceId()`, and a
  `diagnostic()` method that returns the full structured payload for
  audit_steps and log writes.

- **Audit-step detail enrichment.** `FetchInstagramAuditJob` and
  `FetchGMapsReviewsJob` now pull the actual error from
  `BrandAudit.instagram_audit` / `gmaps_reviews` into `audit_steps.detail`.

- **Worker launches via run_dev.py.** `composer.json` `dev` script now uses
  `python ../workers/nema-worker/run_dev.py` instead of bare `uvicorn`, so
  the Windows asyncio Playwright monkey-patch actually applies.

- **Belt-and-suspenders patch in main.py.** Same monkey-patch is also
  installed in `workers/nema-worker/app/main.py` for any path that imports
  the FastAPI app directly (tests, alternate launchers).

Related memory:
- `~/.claude/projects/.../memory/project_bb109_worker_diagnostic.md`

---

## Known limitations

### BB131 — Instagram audit can run in an anonymous (logged-out) render

**Symptom:** The scrape succeeds and returns real data, but the captured
screenshot shows Instagram's **"Log in / Sign up"** buttons in the top nav
(the logged-out public view) instead of an authenticated session.

**Why it still works (for now):** A **public** profile serves its bio,
follower counts, and post grid to anonymous visitors, so the audit gets real
data even when the operator's session cookies aren't actually authenticating
the browser. Diagnosed on the Dhobi Laundry audit (`@dhobilaundryofficial`,
2026-05-21): real 199 followers / 429 posts captured, real bio visible in the
screenshot — but logged-out chrome.

**The latent risk:** A **private** profile (or heavier IG anti-bot) will yield
empty/degraded data in this anonymous state, because the grid + highlights are
gated to followers. The audit would then silently score a "profile shell."

**Status:** Filed as a BB131 follow-up (operator referenced "BB132", but that
number is already used for the audit_failed banner work — pick a fresh tracker
ID). NOT fixed in BB131 — out of scope. The root cause is likely that the
worker's injected cookies aren't taking effect (stale, or Playwright context
not applying them), so IG falls back to the public render. Investigate the
cookie injection path in `_make_context` / `cookies_to_playwright` and confirm
`sessionid` is actually present and accepted at navigation time.

### BB131 — Instagram bio + screenshot proof

- **Bio extraction** no longer falls back to Instagram's `og:description`
  boilerplate ("See Instagram photos and videos from …"). The worker keeps the
  header DOM selector as the primary read, then backfills the real bio from the
  `web_profile_info` JSON endpoint **executed inside the authenticated browser
  page** (`_fetch_bio_via_web_profile_info`). A bio starting with the boilerplate
  phrase is treated as "no real bio captured" and replaced.
- **Existing audits keep the old (boilerplate) bio** — it can't be migrated
  without re-scraping. Re-run the audit (BB59 retry-step `gather_instagram`) to
  get the corrected bio.
- **Screenshot proof** is served through `GET /audit/{token}/instagram/screenshot`
  (token-scoped, streams from the private `local` disk — never moved to the
  public disk). The dashboard "Bukti Scrape" card renders it. Screenshots were
  always persisted at `audits/{id}/instagram/screenshot.png`; BB131 only exposes
  the existing file.
