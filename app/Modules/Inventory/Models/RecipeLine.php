<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient of a dish's recipe at a branch (E5.1). */
class RecipeLine extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'item_id', 'variant_id', 'ingredient_id', 'quantity', 'unit', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit' => Unit::class, 'sort_order' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** How much of the ingredient one portion uses, in the ingredient's own unit (200 g of rice counted in kg = 0.2). */
    public function ingredientQuantity(): float
    {
        $ingredient = $this->ingredient;

        return $ingredient ? ($this->unit->convert((float) $this->quantity, $ingredient->unit) ?? 0.0) : 0.0;
    }

    /** Cost of this line for one portion, at the ingredient's current cost. */
    public function cost(): Money
    {
        return Money::of((int) round($this->ingredientQuantity() * ($this->ingredient?->cost_per_unit->amount ?? 0)));
    }
}
