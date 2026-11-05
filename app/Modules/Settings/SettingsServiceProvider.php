<?php

namespace App\Modules\Settings;

use App\Modules\Settings\Console\AuditCheckCommand;
use App\Modules\Settings\Console\AuditSealCommand;
use App\Modules\Settings\Console\BackupCommand;
use App\Modules\Settings\Console\ImportCommand;
use App\Modules\Settings\Console\LicenceCommand;
use App\Modules\Settings\Console\LicenceIssueCommand;
use App\Modules\Settings\Console\LicenceKeysCommand;
use App\Modules\Settings\Console\ListBackupsCommand;
use App\Modules\Settings\Console\RestoreCommand;
use App\Modules\Settings\Console\SetupCommand;
use App\Support\Licence\LicenceService;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Settings module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        //
    ];

    public function register(): void
    {
        parent::register();

        // One per request: the licence is read and checked once, then reused by every screen and service.
        $this->app->scoped(LicenceService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([SetupCommand::class, BackupCommand::class, RestoreCommand::class, ListBackupsCommand::class, ImportCommand::class,
            LicenceCommand::class, LicenceIssueCommand::class, LicenceKeysCommand::class, AuditSealCommand::class, AuditCheckCommand::class]);
    }
}
