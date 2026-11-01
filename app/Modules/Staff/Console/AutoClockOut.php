<?php

namespace App\Modules\Staff\Console;

use App\Modules\Staff\Services\TimeClockService;
use Illuminate\Console\Command;

/** Closes clock-ins forgotten for more than 16 hours. Runs every hour from the scheduler. */
class AutoClockOut extends Command
{
    protected $signature = 'rms:staff:auto-clock-out {--hours=16 : Close entries open longer than this}';

    protected $description = 'Clock out staff who forgot to, after a very long shift';

    public function handle(TimeClockService $clock): int
    {
        $count = $clock->autoClockOut(null, (int) $this->option('hours'));
        $this->components->info("Closed {$count} forgotten clock-in(s).");

        return self::SUCCESS;
    }
}
