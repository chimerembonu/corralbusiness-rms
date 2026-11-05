<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Staff who forgot to clock out are clocked out automatically after 16 hours (Phase 2.1).
Schedule::command('rms:staff:auto-clock-out')->hourly();

// Nightly backup of the database and uploads to storage/backups (Phase 3.2). Old ones are pruned after 14 days.
Schedule::command('rms:backup --note=nightly')->dailyAt('02:30');

// Payment-provider prompts (MoMo) not settled yet are re-checked every minute, in case the customer approved late (Complete Edition E2).
Schedule::command('rms:payments:check')->everyMinute()->withoutOverlapping();

// Customers' details are removed after the restaurant's retention period without a visit, when one is set (Complete Edition E9.3).
Schedule::command('rms:customers:clean-up')->dailyAt('03:15');

// Tamper-evident audit log (Complete Edition E9.4): new entries are sealed into the chain each minute, and the whole chain is checked nightly.
Schedule::command('rms:audit:seal')->everyMinute()->withoutOverlapping();
Schedule::command('rms:audit:check')->dailyAt('03:45');
