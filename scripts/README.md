# Keeping the branding-builder queue worker alive

Audits run on the **database queue**. If no `queue:work` process is draining
it, audits stall at 0% and the wizard shows the **"Sistem belum siap"** banner
(the queue sub-check reports no worker). Keep a worker running with ONE of:

## A. Logon auto-start — no admin, RECOMMENDED (currently active)

A launcher in your Startup folder runs the keep-alive loop at every logon:

```
%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\nema-queue-worker.vbs
```

It calls `scripts\run-queue-worker.ps1`, which starts **N parallel** `queue:work`
loops (default 3) and restarts any that exit within seconds (crash, fatal job,
--max-time). Running them in parallel lets the audit's gather/analyze
`Bus::batch` jobs execute concurrently instead of one at a time. Override the
count with `-Workers N` or `$env:BB_QUEUE_WORKERS`. To recreate the launcher
(adjust the repo path on another machine):

```vbs
Set sh = CreateObject("WScript.Shell")
sh.Run "powershell -NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File ""C:\Users\<you>\Documents\Herd\nema-platform\branding-builder\scripts\run-queue-worker.ps1""", 0, False
```

Disable: delete the `.vbs` from the Startup folder. Activate now without a
logoff: double-click the `.vbs` (or run `scripts\run-queue-worker.ps1`).

## B. Windows Scheduled Task — needs admin once

In an **elevated** PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install-queue-worker-task.ps1
Start-ScheduledTask -TaskName NemaBrandingQueueWorker
```

Registers a task that runs the same keep-alive loop at logon and restarts it on
failure. Use this instead of (A) if you prefer a managed task.

## C. Dev: `composer dev`

`composer dev` runs the queue worker (+ Vite + the Python worker) while the
terminal is open. Fine for active development; not durable across reboots.

---

**Notes**
- **Parallel workers (BB139).** The launcher runs `BB_QUEUE_WORKERS` workers
  (default **3** in dev; use **2** on the memory-tight NAS prod) so the gather
  (places / gmaps / instagram / website) and analyze batches run at once instead
  of serially. Each concurrent scrape spawns a Chromium in the nema-worker, so
  only raise N if the box has the RAM. The launcher tops up to N and won't stack
  duplicates on a re-run or alongside `composer dev`.
- **`DB_QUEUE_RETRY_AFTER=660` is required** with more than one worker. It must
  exceed the longest job timeout (`FetchGMapsReviewsJob` = 600s); otherwise a
  slow scrape's reservation expires at the default 90s and an idle sibling worker
  re-runs it (double scrape / double browser). See `.env` / `.env.example`.
- The worker uses `--timeout=600` to match `FetchGMapsReviewsJob` (the BB130
  full-corpus GMaps scrape can run 1–3 min). A lower worker timeout would kill
  the scrape mid-run on platforms where the timeout is enforced (Linux/prod).
- Laravel reserves jobs atomically, so concurrent workers never double-process a
  single job (given `DB_QUEUE_RETRY_AFTER` above the job timeout).
