<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Menu\Models\Item;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recipes (Complete Edition E5.1): which ingredients a dish uses, per branch. Lines for "every size" always
 * apply; lines for one size are added on top when that size is sold. Off until "Recipes & food cost" is
 * switched on in Settings → Features.
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

    /** Ingredient cost of one portion (this size), at current ingredient costs. */
    public function portionCost(Item $item, ?string $variantId = null, ?string $branchId = null): Money
    {
        return $this->linesFor($item->id, $variantId, $branchId)->reduce(fn (Money $sum, RecipeLine $l) => $sum->add($l->cost()), Money::zero());
    }
}
