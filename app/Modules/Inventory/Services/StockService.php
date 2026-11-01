<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMoved;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;

/**
 * Stock levels change only through here: every change is a StockMovement row (who, when, why),
 * and ingredients / counted menu items are updated in the same transaction.
 */
class StockService
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Record a movement. $quantity is signed: + into stock, − out of stock.
     * Ingredients may go negative only for sales (the kitchen already used it); nothing else may.
     */
    public function move(Ingredient|Item $subject, StockMovementType $type, float $quantity, ?User $by = null, ?string $reason = null, ?Model $reference = null, ?Money $unitCost = null): StockMovement
    {
        if ($quantity == 0.0) {
            throw new InventoryException(__('inventory::inventory.errors.quantity_required'));
        }

        return DB::transaction(function () use ($subject, $type, $quantity, $by, $reason, $reference, $unitCost) {
            $subject = $subject->newQuery()->lockForUpdate()->findOrFail($subject->getKey());
            $column = $subject instanceof Ingredient ? 'on_hand' : 'stock_qty';
            $after = round((float) $subject->{$column} + $quantity, 3);

            if ($after < 0 && ! in_array($type, [StockMovementType::Sale, StockMovementType::StockTake], true)) {
                throw new InventoryException(__('inventory::inventory.errors.not_enough', ['name' => $subject->name, 'on_hand' => $this->qty((float) $subject->{$column})]));
            }

            $subject->{$column} = $after;
            if ($subject instanceof Ingredient && $unitCost && $type === StockMovementType::Purchase) {
                $subject->cost_per_unit = $unitCost; // last cost
            }
            $subject->saveQuietly();

            if ($subject instanceof Item) {
                $this->syncSoldOut($subject, $by);
            }

            $movement = new StockMovement([
                'ingredient_id' => $subject instanceof Ingredient ? $subject->id : null,
                'item_id' => $subject instanceof Item ? $subject->id : null,
                'type' => $type,
                'quantity' => $quantity,
                'on_hand_after' => $after,
                'unit_cost' => $unitCost ?? ($subject instanceof Ingredient ? $subject->cost_per_unit : null),
                'reason' => filled($reason) ? trim($reason) : null,
                'reference_type' => $reference?->getTable(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $by?->id,
                'happened_at' => now(),
            ]);
            $movement->branch_id = $subject->branch_id ?? $this->context->branchId();
            $movement->save();

            StockMoved::dispatch($movement);

            return $movement;
        });
    }

    /** A new ingredient created with stock already on the shelf: record where that figure came from. */
    public function recordOpening(Ingredient $ingredient, ?User $by = null): ?StockMovement
    {
        if ((float) $ingredient->on_hand <= 0) {
            return null;
        }
        $movement = new StockMovement([
            'ingredient_id' => $ingredient->id,
            'type' => StockMovementType::Opening,
            'quantity' => (float) $ingredient->on_hand,
            'on_hand_after' => (float) $ingredient->on_hand,
            'unit_cost' => $ingredient->cost_per_unit,
            'reason' => __('inventory::inventory.reasons.opening'),
            'user_id' => $by?->id,
            'happened_at' => now(),
        ]);
        $movement->branch_id = $ingredient->branch_id ?? $this->context->branchId();
        $movement->save();

        return $movement;
    }

    /** Set the level to a new figure (the difference is posted as an adjustment). */
    public function setLevel(Ingredient|Item $subject, float $newLevel, ?User $by, string $reason): ?StockMovement
    {
        if (blank($reason)) {
            throw new InventoryException(__('orders::orders.errors.reason_required'));
        }
        $current = (float) ($subject instanceof Ingredient ? $subject->fresh()->on_hand : $subject->fresh()->stock_qty);
        $delta = round($newLevel - $current, 3);

        return $delta == 0.0 ? null : $this->move($subject, StockMovementType::Adjustment, $delta, $by, $reason);
    }

    public function adjust(Ingredient|Item $subject, float $delta, ?User $by, string $reason): StockMovement
    {
        if (blank($reason)) {
            throw new InventoryException(__('orders::orders.errors.reason_required'));
        }

        return $this->move($subject, StockMovementType::Adjustment, $delta, $by, $reason);
    }

    public function waste(Ingredient|Item $subject, float $quantity, ?User $by, string $reason): StockMovement
    {
        if (blank($reason)) {
            throw new InventoryException(__('orders::orders.errors.reason_required'));
        }
        if ($quantity <= 0) {
            throw new InventoryException(__('inventory::inventory.errors.quantity_required'));
        }

        return $this->move($subject, StockMovementType::Wastage, -$quantity, $by, $reason);
    }

    /**
     * Lines just sent to the kitchen: every menu item with "count stock" goes down by the quantity sold.
     *
     * @param  BaseCollection<int, OrderItem>  $lines
     */
    public function deductForSale(Order $order, BaseCollection $lines, ?User $by = null): int
    {
        $count = 0;
        foreach ($lines as $line) {
            $item = $line->item_id ? Item::query()->find($line->item_id) : null;
            if (! $item || ! $item->track_stock) {
                continue;
            }
            $this->move($item, StockMovementType::Sale, -$line->quantity, $by, __('inventory::inventory.reasons.sale', ['number' => $order->displayNumber()]), $order);
            $count++;
        }

        return $count;
    }

    /** A sent line was voided (or its order cancelled): the stock comes back. */
    public function restockLine(Order $order, OrderItem $line, ?User $by = null): ?StockMovement
    {
        if ($line->sent_at === null) {
            return null; // never deducted
        }
        $item = $line->item_id ? Item::query()->find($line->item_id) : null;
        if (! $item || ! $item->track_stock) {
            return null;
        }
        if (StockMovement::query()->where('reference_type', 'orders')->where('reference_id', $order->id)->where('item_id', $item->id)->where('type', StockMovementType::Void->value)->where('reason', 'like', '%#'.$line->id.'%')->exists()) {
            return null; // already put back
        }

        return $this->move($item, StockMovementType::Void, $line->quantity, $by, __('inventory::inventory.reasons.void', ['number' => $order->displayNumber(), 'line' => $line->id]), $order);
    }

    /** @return BaseCollection<int, Ingredient|Item> everything at or below its re-order level */
    public function lowStock(?string $branchId = null): BaseCollection
    {
        $branchId ??= $this->context->branchId();
        $ingredients = Ingredient::query()->active()->where('branch_id', $branchId)->lowStock()->orderBy('name')->get();
        $items = Item::query()->active()->where('track_stock', true)->where('stock_reorder_level', '>', 0)->whereColumn('stock_qty', '<=', 'stock_reorder_level')->orderBy('name')->get();

        return $ingredients->toBase()->merge($items->toBase());
    }

    /** Total value of ingredient stock at last cost, in pesewas. */
    public function valuation(?string $branchId = null): Money
    {
        return Money::of(Ingredient::query()->active()->where('branch_id', $branchId ?? $this->context->branchId())->get()->sum(fn (Ingredient $i) => $i->stockValue()));
    }

    /** @return Collection<int, StockMovement> */
    public function movements(Ingredient|Item|null $subject = null, ?string $from = null, ?string $to = null, ?string $branchId = null): Collection
    {
        return StockMovement::query()->with(['ingredient', 'item', 'user'])
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->when($subject instanceof Ingredient, fn ($q) => $q->where('ingredient_id', $subject->id))
            ->when($subject instanceof Item, fn ($q) => $q->where('item_id', $subject->id))
            ->when($from, fn ($q) => $q->where('happened_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('happened_at', '<=', $to.' 23:59:59'))
            ->latest('happened_at')->get();
    }

    /** Counted items sell out by themselves at zero and come back when stock arrives. */
    private function syncSoldOut(Item $item, ?User $by): void
    {
        $shouldBeSoldOut = (float) $item->stock_qty <= 0;
        if ($item->is_sold_out !== $shouldBeSoldOut) {
            app(MenuService::class)->setSoldOut($item, $shouldBeSoldOut, $by?->id);
        }
    }

    public static function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');
    }
}
