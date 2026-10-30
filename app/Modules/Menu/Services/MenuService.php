<?php

namespace App\Modules\Menu\Services;

use App\Modules\Menu\Events\ItemSoldOutChanged;
use App\Modules\Menu\Models\BranchPrice;
use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Settings\Models\Branch;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/** Menu reads used by the POS and online ordering, plus 86-ing. */
class MenuService
{
    public function __construct(private readonly TenantContext $context) {}

    /** The price to charge at a branch, honouring per-branch overrides. */
    public function priceFor(Item $item, ?ItemVariant $variant = null, Branch|string|null $branch = null): Money
    {
        $branchId = $this->branchId($branch);

        if ($branchId !== null) {
            $override = BranchPrice::query()
                ->where('branch_id', $branchId)
                ->where('item_id', $item->id)
                ->where('variant_id', $variant?->id)
                ->first();

            if ($override?->price !== null) {
                return $override->price;
            }
        }

        return $variant ? $variant->price : $item->basePrice();
    }

    /** Can this item be ordered right now at this branch? (active, not 86'd, in its time window, sold at the branch) */
    public function isOrderable(Item $item, ?CarbonInterface $at = null, Branch|string|null $branch = null): bool
    {
        $at ??= now();

        if (! $item->is_active || $item->is_sold_out) {
            return false;
        }

        $category = $item->relationLoaded('category') ? $item->category : $item->category()->first();
        if ($category && (! $category->is_active || ! $category->availabilityRule()->isAvailableAt($at))) {
            return false;
        }

        if (! $item->availabilityRule()->isAvailableAt($at)) {
            return false;
        }

        $branchId = $this->branchId($branch);
        if ($branchId !== null) {
            $hidden = BranchPrice::query()
                ->where('branch_id', $branchId)
                ->where('item_id', $item->id)
                ->whereNull('variant_id')
                ->where('is_available', false)
                ->exists();

            if ($hidden) {
                return false;
            }
        }

        return true;
    }

    /** Categories with their orderable items for the POS grid. */
    public function catalogFor(Branch|string|null $branch = null, ?CarbonInterface $at = null): Collection
    {
        $at ??= now();

        return Category::query()
            ->active()
            ->ordered()
            ->with(['items' => fn ($q) => $q->active()->with(['variants', 'modifierGroups.modifiers'])])
            ->get()
            ->filter(fn (Category $c) => $c->availabilityRule()->isAvailableAt($at))
            ->each(function (Category $category) use ($at, $branch) {
                $category->setRelation('items', $category->items->filter(fn (Item $i) => $this->isOrderable($i, $at, $branch))->values());
            })
            ->values();
    }

    /** "86" an item (sold out) or put it back on sale. */
    public function setSoldOut(Item $item, bool $soldOut, ?string $byUserId = null): Item
    {
        if ($item->is_sold_out === $soldOut) {
            return $item;
        }

        $item->forceFill(['is_sold_out' => $soldOut, 'sold_out_at' => $soldOut ? now() : null])->save();

        ItemSoldOutChanged::dispatch($item, $soldOut, $byUserId);

        return $item;
    }

    /** Put every 86'd item back on sale (run at the start of each day). */
    public function resetSoldOut(): int
    {
        $items = Item::query()->where('is_sold_out', true)->get();
        $items->each(fn (Item $item) => $this->setSoldOut($item, false));

        return $items->count();
    }

    private function branchId(Branch|string|null $branch): ?string
    {
        return $branch instanceof Branch ? $branch->getKey() : ($branch ?? $this->context->branchId());
    }
}
