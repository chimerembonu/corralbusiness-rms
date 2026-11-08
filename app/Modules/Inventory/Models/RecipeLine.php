<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Models\Concerns\MeasuresIngredient;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient of a dish's recipe at a branch (E5.1). */
class RecipeLine extends BusinessModel
{
    use Auditable, MeasuresIngredient;

    protected $fillable = ['branch_id', 'item_id', 'variant_id', 'ingredient_id', 'quantity', 'unit', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit' => Unit::class, 'sort_order' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }
}
