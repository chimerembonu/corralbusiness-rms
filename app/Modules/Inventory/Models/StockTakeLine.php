<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Menu\Models\Item;
use App\Modules\Staff\Models\User;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTakeLine extends BusinessModel
{
    protected $fillable = ['branch_id', 'stock_take_id', 'ingredient_id', 'item_id', 'name', 'unit', 'expected', 'counted', 'unit_cost', 'counted_by_id', 'counted_at', 'sort_order'];

    protected $attributes = ['expected' => 0, 'unit_cost' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['expected' => 'decimal:3', 'counted' => 'decimal:3', 'unit_cost' => MoneyCast::class, 'counted_at' => 'datetime'];
    }

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(StockTake::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by_id');
    }

    public function isCounted(): bool
    {
        return $this->counted !== null;
    }

    public function difference(): float
    {
        return $this->isCounted() ? round((float) $this->counted - (float) $this->expected, 3) : 0.0;
    }

    /** Value of the difference at cost, in pesewas (negative = missing). */
    public function differenceValue(): int
    {
        return (int) round($this->difference() * $this->unit_cost->amount);
    }
}
