<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\StockTakeStatus;
use App\Modules\Inventory\Events\StockTakeCompleted;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Models\StockTakeLine;
use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Services\CounterService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Count sheets: expected (book) quantity vs what was counted; completing posts the differences. */
class StockTakeService
{
    public function __construct(private readonly StockService $stock, private readonly CounterService $counters, private readonly TenantContext $context) {}

    /** Start a count of every active ingredient (and counted menu items), or just one category. */
    public function start(?User $by = null, ?string $category = null, ?string $branchId = null): StockTake
    {
        $branchId ??= $this->context->branchId();

        if (StockTake::query()->open()->where('branch_id', $branchId)->where('category', $category)->exists()) {
            throw new InventoryException(__('inventory::inventory.errors.stock_take_open'));
        }

        return DB::transaction(function () use ($by, $category, $branchId) {
            $take = new StockTake([
                'number' => $this->counters->next('stock_takes', null, $branchId),
                'category' => $category ?: null,
                'started_at' => now(),
                'started_by_id' => $by?->id,
            ]);
            $take->branch_id = $branchId;
            $take->save();

            $sort = 0;
            $ingredients = Ingredient::query()->active()->where('branch_id', $branchId)
                ->when($category, fn ($q) => $q->where('category', $category))
                ->orderBy('category')->orderBy('name')->get();
            foreach ($ingredients as $ingredient) {
                $this->line($take, $ingredient, null, $ingredient->name, $ingredient->unit->value, (float) $ingredient->on_hand, $ingredient->cost_per_unit, $sort++);
            }
            if (! $category || strcasecmp($category, __('inventory::inventory.menu_items_category')) === 0) {
                foreach (Item::query()->active()->where('track_stock', true)->orderBy('name')->get() as $item) {
                    $this->line($take, null, $item, $item->name, 'pcs', (float) $item->stock_qty, Money::zero(), $sort++);
                }
            }
            if ($sort === 0) {
                throw new InventoryException(__('inventory::inventory.errors.nothing_to_count'));
            }

            return $take;
        });
    }

    public function count(StockTakeLine $line, ?float $counted, ?User $by = null): StockTakeLine
    {
        $take = $line->stockTake()->firstOrFail();
        if (! $take->isOpen()) {
            throw new InventoryException(__('inventory::inventory.errors.stock_take_closed'));
        }
        if ($counted !== null && $counted < 0) {
            throw new InventoryException(__('inventory::inventory.errors.quantity_required'));
        }

        $line->forceFill(['counted' => $counted, 'counted_by_id' => $counted === null ? null : $by?->id, 'counted_at' => $counted === null ? null : now()])->save();

        return $line;
    }

    /** Post every counted difference as a stock-take movement; uncounted lines are left alone. Needs inventory-manage. */
    public function complete(StockTake $take, User $by, ?string $notes = null): StockTake
    {
        if (! $by->can(Permission::InventoryManage->value)) {
            throw new ManagerPinRequiredException(Permission::InventoryManage->value);
        }
        if (! $take->isOpen()) {
            throw new InventoryException(__('inventory::inventory.errors.stock_take_closed'));
        }

        return DB::transaction(function () use ($take, $by, $notes) {
            $variance = 0;
            foreach ($take->lines()->with(['ingredient', 'item'])->get() as $line) {
                if (! $line->isCounted()) {
                    continue;
                }
                $subject = $line->ingredient ?? $line->item;
                if (! $subject) {
                    continue;
                }
                // Re-read the live level: sales may have happened while counting.
                $current = (float) ($subject instanceof Ingredient ? $subject->fresh()->on_hand : $subject->fresh()->stock_qty);
                $delta = round((float) $line->counted - $current, 3);
                $variance += $line->differenceValue();
                if ($delta != 0.0) {
                    $this->stock->move($subject, StockMovementType::StockTake, $delta, $by, __('inventory::inventory.reasons.stock_take', ['number' => $take->displayNumber()]), $take);
                }
            }

            $take->forceFill([
                'status' => StockTakeStatus::Completed,
                'completed_at' => now(),
                'completed_by_id' => $by->id,
                'variance_value' => $variance,
                'notes' => filled($notes) ? trim($notes) : $take->notes,
            ])->save();

            StockTakeCompleted::dispatch($take, $by->id);

            return $take;
        });
    }

    public function cancel(StockTake $take, ?User $by = null): StockTake
    {
        if (! $take->isOpen()) {
            throw new InventoryException(__('inventory::inventory.errors.stock_take_closed'));
        }
        $take->forceFill(['status' => StockTakeStatus::Cancelled, 'completed_at' => now(), 'completed_by_id' => $by?->id])->save();

        return $take;
    }

    /** @return Collection<int, StockTake> */
    public function open(?string $branchId = null): Collection
    {
        return StockTake::query()->open()->with('startedBy')->where('branch_id', $branchId ?? $this->context->branchId())->orderBy('started_at')->get();
    }

    /** @return array<int, string> ingredient categories in use at the branch, plus the menu-items pseudo category */
    public function categories(?string $branchId = null): array
    {
        $categories = Ingredient::query()->active()->where('branch_id', $branchId ?? $this->context->branchId())
            ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all();
        if (Item::query()->active()->where('track_stock', true)->exists()) {
            $categories[] = __('inventory::inventory.menu_items_category');
        }

        return $categories;
    }

    private function line(StockTake $take, ?Ingredient $ingredient, ?Item $item, string $name, string $unit, float $expected, Money $cost, int $sort): void
    {
        $line = new StockTakeLine([
            'stock_take_id' => $take->id,
            'ingredient_id' => $ingredient?->id,
            'item_id' => $item?->id,
            'name' => $name,
            'unit' => $unit,
            'expected' => $expected,
            'unit_cost' => $cost,
            'sort_order' => $sort,
        ]);
        $line->branch_id = $take->branch_id;
        $line->save();
    }
}
