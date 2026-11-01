<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Services\BackupService;
use Illuminate\Console\Command;

class ListBackupsCommand extends Command
{
    protected $signature = 'rms:backups';

    protected $description = 'List the backups in storage/backups';

    public function handle(BackupService $backups): int
    {
        $list = $backups->list();
        if ($list === []) {
            $this->components->warn('No backups yet. Run: php artisan rms:backup');

            return self::SUCCESS;
        }
        $this->table(['File', 'Taken', 'Size'], array_map(fn ($b) => [$b['name'], $b['created_at']->format('d/m/Y H:i'), round($b['size'] / 1024 / 1024, 2).' MB'], $list));

        return self::SUCCESS;
    }
}
