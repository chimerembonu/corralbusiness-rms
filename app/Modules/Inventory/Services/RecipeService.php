<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\ModifierRecipeLine;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\Modifier;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recipes (Complete Edition E5.1): which ingredients a dish uses, per branch. Lines for "every size" always
 * apply; lines for one size are added on top when that size is sold. Extras (modifiers such as "Chicken" or
 * "Extra plantain") can have their own recipe, used once per extra chosen. Off until "Recipes & food cost"
 * is switched on in Settings → Features.
 */
class RecipeService
{
    public function __construct(private readonly TenantContext $context, private readonly FeatureService $features) {}

    public function enabled(): bool
    {
        return $this->features->isOn('recipes');
    }

    /** @return Collection<int, RecipeLine> the lines one portion of this dish (and size) uses at a branch */
    public function linesFor(string $itemId, ?string $variantId, ?string $branchId = null): Collection
    {
        return RecipeLine::query()->with('ingredient')
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->where('item_id', $itemId)
            ->where(fn ($q) => $q->whereNull('variant_id')->when($variantId, fn ($w) => $w->orWhere('variant_id', $variantId)))
            ->orderBy('sort_order')->get();
    }

    /**
     * Ingredient use for $portions of a dish: ingredient id => quantity in the ingredient's own unit. The same
     * ingredient on several lines (all sizes + this size) is added up.
     *
     * @return array<string, float>
     */
    public function usage(string $itemId, ?string $variantId, float $portions, ?string $branchId = null): array
    {
        $use = [];
        foreach ($this->linesFor($itemId, $variantId, $branchId) as $line) {
            if ($line->ingredient && $line->ingredient->is_active) {
                $use[$line->ingredient_id] = ($use[$line->ingredient_id] ?? 0) + $line->ingredientQuantity() * $portions;
            }
        }

        return array_filter(array_map(fn ($q) => round($q, 3), $use), fn ($q) => $q > 0);
    }

    /** @return Collection<int, ModifierRecipeLine> the lines one of this extra uses at a branch */
    public function modifierLinesFor(string $modifierId, ?string $branchId = null): Collection
    {
        return ModifierRecipeLine::query()->with('ingredient')
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->where('modifier_id', $modifierId)->orderBy('sort_order')->get();
    }

    /**
     * Everything one order line uses: the dish (and size) for each plate, plus each extra chosen on it
     * (an extra's quantity is per plate). Ingredient id => quantity in the ingredient's own unit.
     *
     * @return array<string, float>
     */
    public function usageForLine(OrderItem $line, ?string $branchId = null): array
    {
        $branchId ??= $line->branch_id;
        $plates = (float) $line->quantity;
        $use = $line->item_id ? $this->usage($line->item_id, $line->variant_id, $plates, $branchId) : [];
        foreach ($line->modifiers()->whereNotNull('modifier_id')->get() as $chosen) {
            foreach ($this->modifierLinesFor($chosen->modifier_id, $branchId) as $recipe) {
                if ($recipe->ingredient && $recipe->ingredient->is_active) {
                    $use[$recipe->ingredient_id] = ($use[$recipe->ingredient_id] ?? 0) + $recipe->ingredientQuantity() * $chosen->quantity * $plates;
                }
            }
        }

        return array_filter(array_map(fn ($q) => round($q, 3), $use), fn ($q) => $q > 0);
    }

    /** Ingredient cost of one of an extra, at current ingredient costs. */
    public function modifierCost(Modifier $modifier, ?string $branchId = null): Money
    {
        return $this->modifierLinesFor($modifier->id, $branchId)->reduce(fn (Money $sum, ModifierRecipeLine $l) => $sum->add($l->cost()), Money::zero());
    }

    /** Ingredient cost of one portion (this size), at current ingredient costs. */
    public function portionCost(Item $item, ?string $variantId = null, ?string $branchId = null): Money
    {
        return $this->linesFor($item->id, $variantId, $branchId)->reduce(fn (Money $sum, RecipeLine $l) => $sum->add($l->cost()), Money::zero());
    }
}
