<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Hourly, not twice daily: "8am" means 8am where each user is, so the command
 * checks every account's local clock and sends to the ones whose hour has just
 * come round. It is safe to run more often — a delivery row per user, kind and
 * day stops anything going out twice.
 */
Schedule::command('push:digests')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Images dropped into a note that was then abandoned. Daily is often enough:
 * they are unreadable by anyone the moment the composer closes, so this is
 * reclaiming disk space rather than closing a hole.
 */
Schedule::command('attachments:prune-orphans')
    ->dailyAt('03:20')
    ->withoutOverlapping();
