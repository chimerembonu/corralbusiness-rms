<?php

namespace App\Modules\Menu\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A size/portion of an item, e.g. "Large" or "2 tilapia". */
class ItemVariant extends BusinessModel
{
    use Auditable;

    protected $table = 'menu_item_variants';

    protected $fillable = ['branch_id', 'item_id', 'name', 'code', 'price', 'is_default', 'sort_order', 'is_active'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['is_default' => false, 'sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
