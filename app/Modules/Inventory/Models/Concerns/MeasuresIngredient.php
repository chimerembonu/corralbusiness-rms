<?php

namespace App\Modules\Inventory\Models\Concerns;

use App\Modules\Inventory\Models\Ingredient;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A recipe line (for a dish or an extra): an ingredient, an amount and the unit it is written in. */
trait MeasuresIngredient
{
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
