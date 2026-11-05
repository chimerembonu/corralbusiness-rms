<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Models\Concerns\MeasuresIngredient;
use App\Modules\Menu\Models\Modifier;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient used by one of an extra (e.g. Chicken: 150 g chicken) at a branch. */
class ModifierRecipeLine extends BusinessModel
{
    use Auditable, MeasuresIngredient;

    protected $fillable = ['branch_id', 'modifier_id', 'ingredient_id', 'quantity', 'unit', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit' => Unit::class, 'sort_order' => 'integer'];
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
