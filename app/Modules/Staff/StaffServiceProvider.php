<?php

namespace App\Modules\Staff;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Staff module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class StaffServiceProvider extends ServiceProvider
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
    }
}
