<?php

namespace App\Modules\Menu\Models;

use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line inside a combo: "1 × Jollof Rice (Regular)". */
class ComboItem extends BusinessModel
{
    protected $table = 'menu_combo_items';

    protected $fillable = ['branch_id', 'combo_id', 'item_id', 'variant_id', 'quantity', 'sort_order'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['quantity' => 1, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }

    public function combo(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'combo_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }
}
