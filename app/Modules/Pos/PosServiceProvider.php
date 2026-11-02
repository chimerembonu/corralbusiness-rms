<?php

namespace App\Modules\Pos;

use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Pos\Services\TrainingMode;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Pos module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class PosServiceProvider extends ServiceProvider
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

        // One answer per request: the till that opens an order and the service that marks it must agree.
        $this->app->singleton(TrainingMode::class);
        $this->app->singleton(CurrentTill::class);
    }

    public function boot(): void
    {
        parent::boot();
    }
}
