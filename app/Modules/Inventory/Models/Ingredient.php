<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\Unit;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'code', 'category', 'unit', 'pack_name', 'pack_quantity', 'cost_per_unit', 'on_hand', 'reorder_level', 'is_active', 'supplier_id'];

    protected $attributes = ['unit' => 'kg', 'cost_per_unit' => 0, 'on_hand' => 0, 'reorder_level' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'cost_per_unit' => MoneyCast::class,
            'on_hand' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'pack_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('happened_at');
    }

    /** Value of what is on the shelf at its cost (last price paid, or the average: E5.2), in pesewas. */
    public function stockValue(): int
    {
        return (int) round((float) $this->on_hand * $this->cost_per_unit->amount);
    }

    /** "Bag of 25 kg" or null. */
    public function packLabel(): ?string
    {
        return $this->pack_name && $this->pack_quantity ? $this->pack_name.' '.__('inventory::inventory.of').' '.rtrim(rtrim(number_format((float) $this->pack_quantity, 3, '.', ''), '0'), '.').' '.$this->unit->short() : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('on_hand', '<=', 'reorder_level')->where('reorder_level', '>', 0);
    }

    public function isLow(): bool
    {
        return (float) $this->reorder_level > 0 && (float) $this->on_hand <= (float) $this->reorder_level;
    }
}
