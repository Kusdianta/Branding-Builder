<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// BB148 — safety net: re-dispatch the next phase for any audit stranded at a
// pipeline phase boundary (a swallowed batch-callback dispatch). The IMMEDIATE
// transaction mode is the primary fix; this catches the rare residual case.
// No-op unless `schedule:run` is driven by a cron/Task Scheduler.
Schedule::command('audits:resume-stranded')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground();
