<?php

namespace App\Modules\Customers;

use App\Modules\Customers\Contracts\LoyaltyProvider;
use App\Modules\Customers\Listeners\RememberDeliveryCustomer;
use App\Modules\Customers\Listeners\UpdateCustomerWhenPaid;
use App\Modules\Customers\Services\SimplePointsProvider;
use App\Modules\Orders\Events\DeliveryDetailsChanged;
use App\Modules\Orders\Events\OrderPaid;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Customers module.
 */
class CustomersServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderPaid::class => [UpdateCustomerWhenPaid::class],
        DeliveryDetailsChanged::class => [RememberDeliveryCustomer::class],
    ];

    public function register(): void
    {
        parent::register();

        // Swap this binding for Corral Rewards (Phase 7+) without touching the till.
        $this->app->bind(LoyaltyProvider::class, SimplePointsProvider::class);
    }

    public function boot(): void
    {
        parent::boot();
    }
}
