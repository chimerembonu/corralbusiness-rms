<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/** `php artisan rms:restore <file>` — put a backup back (a safety backup is taken first). */
class RestoreCommand extends Command
{
    protected $signature = 'rms:restore {file? : Backup file name or path (default: the newest)} {--force : Do not ask for confirmation}';

    protected $description = 'Restore the database and uploaded files from a backup';

    public function handle(BackupService $backups): int
    {
        try {
            $path = $this->argument('file') ? $backups->resolve($this->argument('file')) : ($backups->latest()['path'] ?? null);
            if (! $path) {
                $this->components->error('No backup found in storage/backups.');

                return self::FAILURE;
            }

            $this->components->warn('This replaces EVERYTHING in the database with the contents of '.basename($path).'.');
            if (! $this->option('force') && ! $this->confirm('Continue?', false)) {
                $this->components->info('Nothing changed.');

                return self::SUCCESS;
            }

            $safety = $backups->restore($path);
            $this->components->info('Restored from '.basename($path).'.');
            $this->components->info('The state from just before the restore was saved as '.basename($safety).' in case you need it.');
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
