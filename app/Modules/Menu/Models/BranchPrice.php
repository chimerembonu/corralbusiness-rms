<?php

namespace App\Modules\Menu\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A branch's own price for an item/variant, or "not sold at this branch". */
class BranchPrice extends BusinessModel
{
    use Auditable;

    protected $table = 'menu_branch_prices';

    protected $fillable = ['branch_id', 'item_id', 'variant_id', 'price', 'is_available'];

    /** Defaults so a freshly created model behaves like one loaded from the database. */
    protected $attributes = ['is_available' => true];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'is_available' => 'boolean',
        ];
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
