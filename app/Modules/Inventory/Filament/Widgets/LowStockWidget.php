<?php

namespace App\Modules\Inventory\Filament\Widgets;

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Menu\Models\Item;
use App\Modules\Staff\Enums\Permission;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/** Dashboard card: everything at or below its re-order level. */
class LowStockWidget extends Widget
{
    protected static ?int $sort = 5;

    /** Rendered with the page (not lazily) so the alert is there as soon as the dashboard opens. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'inventory::filament.low-stock-widget';

    public static function canView(): bool
    {
        return auth()->user()?->canAny([Permission::InventoryManage->value, Permission::InventoryCount->value]) ?? false;
    }

    /** @return Collection<int, array{name: string, qty: string, reorder: string, kind: string}> */
    public function rows(): Collection
    {
        return app(StockService::class)->lowStock()->map(fn (Ingredient|Item $r) => $r instanceof Ingredient
            ? ['name' => $r->name, 'qty' => StockService::qty((float) $r->on_hand).' '.$r->unit->short(), 'reorder' => StockService::qty((float) $r->reorder_level), 'kind' => $r->category ?? '']
            : ['name' => $r->name, 'qty' => StockService::qty((float) $r->stock_qty), 'reorder' => StockService::qty((float) $r->stock_reorder_level), 'kind' => __('inventory::inventory.low_stock.item')]);
    }
}
