<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Menu\Models\Item;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only: one row per stock change. */
class StockMovement extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'ingredient_id', 'item_id', 'type', 'quantity', 'on_hand_after', 'unit_cost', 'reason', 'reference_type', 'reference_id', 'user_id', 'happened_at'];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'decimal:3',
            'on_hand_after' => 'decimal:3',
            'unit_cost' => MoneyCast::class,
            'happened_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjectName(): string
    {
        return $this->ingredient?->name ?? $this->item?->name ?? '—';
    }

    public function unitShort(): string
    {
        return $this->ingredient?->unit->short() ?? __('inventory::inventory.unit_short.pcs');
    }
}
