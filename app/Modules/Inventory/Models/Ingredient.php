<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\Unit;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;

class Ingredient extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'code', 'unit', 'cost_per_unit', 'on_hand', 'reorder_level', 'is_active'];

    protected $attributes = ['unit' => 'kg', 'cost_per_unit' => 0, 'on_hand' => 0, 'reorder_level' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'cost_per_unit' => MoneyCast::class,
            'on_hand' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
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
