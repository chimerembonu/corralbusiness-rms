<?php

namespace App\Modules\Orders\Models;

use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemModifier extends BusinessModel
{
    protected $fillable = ['branch_id', 'order_item_id', 'modifier_id', 'group_name', 'name', 'unit_price', 'quantity'];

    protected $attributes = ['unit_price' => 0, 'quantity' => 1];

    protected function casts(): array
    {
        return ['unit_price' => MoneyCast::class, 'quantity' => 'integer'];
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function displayName(): string
    {
        return $this->quantity > 1 ? "{$this->quantity} × {$this->name}" : $this->name;
    }
}
