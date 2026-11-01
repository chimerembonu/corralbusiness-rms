<?php

namespace App\Modules\Settings;

use App\Modules\Settings\Console\BackupCommand;
use App\Modules\Settings\Console\ImportCommand;
use App\Modules\Settings\Console\ListBackupsCommand;
use App\Modules\Settings\Console\RestoreCommand;
use App\Modules\Settings\Console\SetupCommand;
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
    }

    public function boot(): void
    {
        parent::boot();

        $this->commands([SetupCommand::class, BackupCommand::class, RestoreCommand::class, ListBackupsCommand::class, ImportCommand::class]);
    }
}
