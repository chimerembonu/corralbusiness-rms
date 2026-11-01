<?php

namespace App\Modules\Printing;

use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Printing\Console\ListPrintQueues;
use App\Modules\Printing\Contracts\RawSpooler;
use App\Modules\Printing\Listeners\OpenDrawerOnCashPayment;
use App\Modules\Printing\Listeners\PrintReceiptWhenPaid;
use App\Modules\Printing\Services\CupsSpooler;
use App\Modules\Printing\Services\PrinterDriverRegistry;
use App\Modules\Printing\Services\WindowsSpooler;
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
        PaymentRecorded::class => [OpenDrawerOnCashPayment::class],
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PrinterDriverRegistry::class);
        // The computer's own print queue: CUPS on Mac/Linux, shares/COM ports on Windows.
        $this->app->singleton(RawSpooler::class, fn () => PHP_OS_FAMILY === 'Windows' ? new WindowsSpooler : new CupsSpooler);
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([ListPrintQueues::class]);
        }
    }
}
