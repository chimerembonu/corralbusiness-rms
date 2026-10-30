<?php

namespace App\Modules\Orders\Models;

use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends BusinessModel
{
    protected $fillable = [
        'branch_id', 'order_id', 'item_id', 'variant_id', 'name', 'variant_name', 'quantity', 'unit_price', 'modifiers_total',
        'taxable', 'tax_exempt_codes', 'status', 'seat', 'course', 'notes', 'sort_order',
    ];

    protected $attributes = [
        'quantity' => 1, 'modifiers_total' => 0, 'line_subtotal' => 0, 'discount_amount' => 0, 'taxable' => true,
        'tax_total' => 0, 'line_total' => 0, 'status' => 'pending', 'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => MoneyCast::class,
            'modifiers_total' => MoneyCast::class,
            'line_subtotal' => MoneyCast::class,
            'discount_amount' => MoneyCast::class,
            'taxable' => 'boolean',
            'tax_exempt_codes' => 'array',
            'tax_lines' => 'array',
            'tax_total' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'status' => OrderItemStatus::class,
            'seat' => 'integer',
            'course' => 'integer',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderItemModifier::class);
    }

    public function isPending(): bool
    {
        return $this->status === OrderItemStatus::Pending;
    }

    public function isVoided(): bool
    {
        return $this->status === OrderItemStatus::Voided;
    }

    /** Price of one unit including its modifiers. */
    public function unitTotal(): Money
    {
        return $this->unit_price->add($this->modifiers_total);
    }

    public function displayName(): string
    {
        return $this->variant_name ? "{$this->name} ({$this->variant_name})" : $this->name;
    }
}
