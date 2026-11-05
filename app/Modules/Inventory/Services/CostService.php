<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\CostChangeSource;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\IngredientCostChange;
use App\Modules\Inventory\Models\Purchase;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ingredient costs (Complete Edition E5.2).
 *
 * Weighted-average cost, as IAS 2 allows: after each delivery the cost per unit becomes
 *   (stock before × cost before + delivered × price paid) ÷ (stock before + delivered).
 * When there was no stock (or less than none, after sales ran it below zero), the price paid becomes the
 * cost. Every change is kept (the purchase price history), and a change by hand needs a reason.
 */
class CostService
{
    /** The new average cost per unit (pesewas) after receiving $quantity at $pricePaid. */
    public static function average(float $onHandBefore, Money $costBefore, float $quantity, Money $pricePaid): Money
    {
        $before = max(0.0, $onHandBefore);
        if ($before + $quantity <= 0) {
            return $pricePaid;
        }

        return Money::of((int) round(($before * $costBefore->amount + $quantity * $pricePaid->amount) / ($before + $quantity)));
    }

    /** Called by StockService inside the delivery's transaction, with the ingredient row locked. */
    public function applyPurchase(Ingredient $ingredient, float $onHandBefore, float $quantity, Money $pricePaid, ?User $by = null, ?Purchase $purchase = null): Money
    {
        $old = $ingredient->cost_per_unit;
        $new = self::average($onHandBefore, $old, $quantity, $pricePaid);
        $ingredient->cost_per_unit = $new;
        $this->record($ingredient, CostChangeSource::Purchase, $old, $new, $by, pricePaid: $pricePaid, quantity: $quantity, onHandBefore: $onHandBefore, purchase: $purchase);

        return $new;
    }

    /** A cost changed by hand: a reason is required, and the change is kept. */
    public function change(Ingredient $ingredient, Money $newCost, ?User $by, string $reason): IngredientCostChange
    {
        if (blank($reason)) {
            throw new InventoryException(__('inventory::inventory.costs.errors.reason'));
        }
        if ($newCost->isNegative()) {
            throw new InventoryException(__('inventory::inventory.costs.errors.negative'));
        }

        return DB::transaction(function () use ($ingredient, $newCost, $by, $reason) {
            $locked = Ingredient::query()->lockForUpdate()->findOrFail($ingredient->id);
            if ($locked->cost_per_unit->amount === $newCost->amount) {
                throw new InventoryException(__('inventory::inventory.costs.errors.same'));
            }
            $old = $locked->cost_per_unit;
            $locked->cost_per_unit = $newCost;
            $locked->save();

            return $this->record($locked, CostChangeSource::Manual, $old, $newCost, $by, reason: trim($reason), onHandBefore: (float) $locked->on_hand);
        });
    }

    /** The cost a new ingredient starts with (from the form, or a spreadsheet). */
    public function recordOpening(Ingredient $ingredient, ?User $by = null, CostChangeSource $source = CostChangeSource::Opening): ?IngredientCostChange
    {
        return $ingredient->cost_per_unit->amount > 0
            ? $this->record($ingredient, $source, Money::zero(), $ingredient->cost_per_unit, $by, onHandBefore: (float) $ingredient->on_hand)
            : null;
    }

    /** A spreadsheet import changed an existing ingredient's cost. */
    public function recordImport(Ingredient $ingredient, Money $oldCost, ?User $by = null): ?IngredientCostChange
    {
        return $oldCost->amount !== $ingredient->cost_per_unit->amount
            ? $this->record($ingredient, CostChangeSource::Import, $oldCost, $ingredient->cost_per_unit, $by, reason: __('inventory::inventory.costs.import_reason'), onHandBefore: (float) $ingredient->on_hand)
            : null;
    }

    /** What was paid per unit on the last delivery (the sensible default for a new purchase order), or null. */
    public function lastPricePaid(Ingredient $ingredient): ?Money
    {
        return IngredientCostChange::query()->where('ingredient_id', $ingredient->id)->where('source', CostChangeSource::Purchase->value)
            ->latest('happened_at')->latest('id')->first()?->price_paid;
    }

    /** @return Collection<int, IngredientCostChange> newest first */
    public function history(Ingredient $ingredient, int $limit = 50): Collection
    {
        return IngredientCostChange::query()->with(['purchase.supplier', 'user'])->where('ingredient_id', $ingredient->id)
            ->latest('happened_at')->latest('id')->limit($limit)->get();
    }

    private function record(Ingredient $ingredient, CostChangeSource $source, Money $old, Money $new, ?User $by, ?string $reason = null, ?Money $pricePaid = null, ?float $quantity = null, ?float $onHandBefore = null, ?Purchase $purchase = null): IngredientCostChange
    {
        $change = new IngredientCostChange([
            'ingredient_id' => $ingredient->id, 'source' => $source, 'old_cost' => $old, 'new_cost' => $new,
            'price_paid' => $pricePaid, 'quantity' => $quantity, 'on_hand_before' => $onHandBefore,
            'purchase_id' => $purchase?->id, 'reason' => $reason, 'user_id' => $by?->id, 'happened_at' => now(),
        ]);
        $change->branch_id = $ingredient->branch_id;
        $change->tenant_id = $ingredient->tenant_id;
        $change->save();
        activity('inventory')->performedOn($ingredient)->causedBy($by)
            ->withProperties(['source' => $source->value, 'old' => $old->amount, 'new' => $new->amount, 'reason' => $reason])->log('ingredient_cost_changed');

        return $change;
    }
}
