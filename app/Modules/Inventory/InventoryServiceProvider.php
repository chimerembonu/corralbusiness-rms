<?php

namespace App\Modules\Inventory;

use App\Modules\Inventory\Listeners\DeductStockWhenSent;
use App\Modules\Inventory\Listeners\RestockWhenVoided;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Menu\Models\Item;
use App\Modules\Orders\Events\ItemVoided;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Orders\Events\OrderVoided;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Inventory module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class InventoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        OrderSentToKitchen::class => [DeductStockWhenSent::class],
        ItemVoided::class => [RestockWhenVoided::class],
        OrderVoided::class => [RestockWhenVoided::class],
    ];

    public function register(): void
    {
        parent::register();
    }

    public function boot(): void
    {
        parent::boot();

        // A dish's recipe lines (E5.1). Defined here so the Menu module does not need to know about stock.
        Item::resolveRelationUsing('recipeLines', fn (Item $item) => $item->hasMany(RecipeLine::class, 'item_id'));
    }
}
