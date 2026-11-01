<?php

namespace App\Modules\Orders;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderVoided;
use App\Modules\Orders\Listeners\ReleaseMergedTables;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Orders module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class OrdersServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderPaid::class => [ReleaseMergedTables::class],
        OrderVoided::class => [ReleaseMergedTables::class],
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
