<?php

namespace App\Modules\Kitchen;

use App\Modules\Kitchen\Events\TicketChanged;
use App\Modules\Kitchen\Listeners\CreateTicketWhenSent;
use App\Modules\Kitchen\Listeners\FireHeldCourse;
use App\Modules\Kitchen\Listeners\FollowOrderToNewTable;
use App\Modules\Kitchen\Listeners\PrintTicket;
use App\Modules\Kitchen\Listeners\StrikeVoidedLine;
use App\Modules\Orders\Events\CourseFired;
use App\Modules\Orders\Events\ItemVoided;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Orders\Events\OrderTransferred;
use App\Modules\Orders\Events\TablesMerged;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Kitchen module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class KitchenServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderSentToKitchen::class => [CreateTicketWhenSent::class],
        CourseFired::class => [FireHeldCourse::class],
        ItemVoided::class => [StrikeVoidedLine::class],
        TicketChanged::class => [PrintTicket::class],
        OrderTransferred::class => [FollowOrderToNewTable::class],
        TablesMerged::class => [FollowOrderToNewTable::class],
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
