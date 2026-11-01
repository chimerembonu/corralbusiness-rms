<?php

namespace App\Modules\Orders\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A part of the restaurant on the floor plan: Indoor, Terrace, Bar… */
class FloorSection extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'name', 'color', 'sort_order', 'is_active'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'section_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
