<?php

namespace App\Modules\Menu;

use App\Modules\Menu\Models\BranchPrice;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\ComboItem;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Models\Modifier;
use App\Modules\Menu\Models\ModifierGroup;
use App\Modules\Menu\Observers\MenuCacheBuster;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Service provider for the Menu module.
 *
 * Register event → listener pairs in $listen, and container bindings
 * (interfaces → implementations) in register(). Routes, migrations, views
 * and Livewire components are auto-loaded by ModuleServiceProvider.
 */
class MenuServiceProvider extends ServiceProvider
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

        foreach ([Category::class, Item::class, ItemVariant::class, ModifierGroup::class, Modifier::class, BranchPrice::class, ComboItem::class] as $model) {
            $model::observe(MenuCacheBuster::class);
        }
        // Pivot rows (item ↔ modifier group) are synced without model events; the Filament form saves the item too.
    }
}
