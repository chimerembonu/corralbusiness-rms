<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DiningTable extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'section', 'seats', 'pos_x', 'pos_y', 'sort_order', 'is_active'];

    protected $attributes = ['seats' => 4, 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    /** The order currently open on this table, if any. */
    public function currentOrder(): HasOne
    {
        return $this->hasOne(Order::class, 'table_id')
            ->whereIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value])
            ->latestOfMany('opened_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('section')->orderBy('sort_order')->orderBy('name');
    }

    public function isOccupied(): bool
    {
        return $this->relationLoaded('currentOrder') ? $this->currentOrder !== null : $this->currentOrder()->exists();
    }
}
