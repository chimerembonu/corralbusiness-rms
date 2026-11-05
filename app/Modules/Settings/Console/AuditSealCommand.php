<?php

namespace App\Modules\Settings\Console;

use App\Support\Audit\AuditChain;
use Illuminate\Console\Command;

/** `php artisan rms:audit:seal` — every minute: seal new audit log entries into the tamper-evident chain (E9.4). */
class AuditSealCommand extends Command
{
    protected $signature = 'rms:audit:seal';

    protected $description = 'Seal new audit log entries into the tamper-evident chain';

    public function handle(AuditChain $chain): int
    {
        $count = $chain->seal();
        $this->components->info("Sealed {$count} entr".($count === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
