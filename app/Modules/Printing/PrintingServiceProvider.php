<?php

namespace App\Modules\Printing;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Printing\Listeners\PrintReceiptWhenPaid;
use App\Modules\Printing\Services\PrinterDriverRegistry;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Printing module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class PrintingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderPaid::class => [PrintReceiptWhenPaid::class],
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PrinterDriverRegistry::class);
    }

    public function boot(): void
    {
        parent::boot();
    }
}
