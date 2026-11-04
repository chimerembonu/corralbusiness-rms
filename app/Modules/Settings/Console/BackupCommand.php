<?php

namespace App\Modules\Settings\Console;

use App\Modules\Settings\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/** `php artisan rms:backup` — back up the database and uploads now (also runs nightly). */
class BackupCommand extends Command
{
    protected $signature = 'rms:backup {--copy-to= : Also copy the backup to this folder, e.g. a USB stick} {--note=} {--show-password : Only show the password backups are locked with (write it down, keep it safe)}';

    protected $description = 'Back up the database and uploaded files to storage/backups';

    public function handle(BackupService $backups): int
    {
        if ($this->option('show-password')) {
            $this->components->info('Backups on this server are locked with this password. Write it down and keep it somewhere safe:');
            $this->line('    '.$backups->password());
            $this->components->warn('You need it to restore a backup on a different computer (e.g. after replacing the server).');

            return self::SUCCESS;
        }

        try {
            $path = $backups->create($this->option('note'));
            $this->components->info('Backup written: '.$path.' ('.round(filesize($path) / 1024 / 1024, 2).' MB)');

            if ($folder = $this->option('copy-to')) {
                $copied = $backups->copyTo($path, $folder);
                $this->components->info('Copied to: '.$copied);
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
