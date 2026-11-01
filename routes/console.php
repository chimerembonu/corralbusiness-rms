<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Staff who forgot to clock out are clocked out automatically after 16 hours (Phase 2.1).
Schedule::command('rms:staff:auto-clock-out')->hourly();
