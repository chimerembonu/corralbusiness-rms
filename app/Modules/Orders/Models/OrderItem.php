<?php

namespace App\Modules\Orders\Models;

use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends BusinessModel
{
    use Auditable;

    protected $fillable = [
        'branch_id', 'order_id', 'item_id', 'variant_id', 'name', 'variant_name', 'quantity', 'unit_price', 'modifiers_total',
        'taxable', 'tax_exempt_codes', 'status', 'seat', 'course', 'notes', 'sort_order',
        'item_discount_type', 'item_discount_value', 'item_discount_reason',
    ];

    protected $attributes = [
        'quantity' => 1, 'modifiers_total' => 0, 'line_subtotal' => 0, 'discount_amount' => 0, 'taxable' => true,
        'tax_total' => 0, 'line_total' => 0, 'status' => 'pending', 'sort_order' => 0, 'item_discount_value' => 0,
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
            'fired_at' => 'datetime',
            'voided_at' => 'datetime',
            'item_discount_type' => DiscountType::class,
            'item_discount_value' => 'integer',
        ];
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_id');
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

    public function isSent(): bool
    {
        return $this->status === OrderItemStatus::Sent;
    }

    public function hasItemDiscount(): bool
    {
        return $this->item_discount_type !== null && $this->item_discount_value > 0;
    }

    /** The line's own discount (before any share of the order discount). */
    public function itemDiscount(): Money
    {
        if (! $this->hasItemDiscount()) {
            return Money::zero();
        }
        $subtotal = $this->unitTotal()->multiply($this->quantity);
        $discount = $this->item_discount_type === DiscountType::Percent ? $subtotal->basisPoints($this->item_discount_value) : Money::of($this->item_discount_value);

        return $discount->greaterThan($subtotal) ? $subtotal : $discount;
    }

    /** True once a held course has been called away (or the line never needed firing). */
    public function isFired(): bool
    {
        return $this->fired_at !== null || ($this->course ?? 1) <= 1;
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
