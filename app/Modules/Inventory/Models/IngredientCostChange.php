<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\CostChangeSource;
use App\Modules\Staff\Models\User;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: one row per change to an ingredient's cost (E5.2). */
class IngredientCostChange extends BusinessModel
{
    protected $fillable = ['branch_id', 'ingredient_id', 'source', 'old_cost', 'new_cost', 'price_paid', 'quantity', 'on_hand_before', 'purchase_id', 'reason', 'user_id', 'happened_at'];

    protected function casts(): array
    {
        return [
            'source' => CostChangeSource::class,
            'old_cost' => MoneyCast::class,
            'new_cost' => MoneyCast::class,
            'price_paid' => MoneyCast::class,
            'quantity' => 'decimal:3',
            'on_hand_before' => 'decimal:3',
            'happened_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
