<?php

namespace App\Modules\Inventory\Models;

use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends BusinessModel
{
    protected $fillable = ['branch_id', 'purchase_id', 'ingredient_id', 'quantity_ordered', 'quantity_received', 'unit_cost', 'line_total', 'sort_order'];

    protected $attributes = ['quantity_ordered' => 0, 'unit_cost' => 0, 'line_total' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'unit_cost' => MoneyCast::class,
            'line_total' => MoneyCast::class,
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
