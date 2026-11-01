<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends BusinessModel
{
    use Auditable;

    protected $fillable = [
        'branch_id', 'business_date', 'number', 'type', 'status', 'table_id', 'covers', 'customer_name', 'customer_phone',
        'opened_by_id', 'notes', 'discount_type', 'discount_value', 'discount_reason', 'prices_include_tax', 'opened_at',
    ];

    protected $attributes = [
        'status' => 'open', 'covers' => 1, 'subtotal' => 0, 'discount_value' => 0, 'discount_total' => 0,
        'service_charge' => 0, 'tax_total' => 0, 'total' => 0, 'paid_total' => 0, 'prices_include_tax' => false,
    ];

    protected array $auditExcept = ['tax_lines'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'number' => 'integer',
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'covers' => 'integer',
            'discount_type' => DiscountType::class,
            'discount_value' => 'integer',
            'subtotal' => MoneyCast::class,
            'discount_total' => MoneyCast::class,
            'service_charge' => MoneyCast::class,
            'tax_total' => MoneyCast::class,
            'tax_lines' => 'array',
            'total' => MoneyCast::class,
            'paid_total' => MoneyCast::class,
            'prices_include_tax' => 'boolean',
            'opened_at' => 'datetime',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    /** The order this one was merged into (status = merged). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('created_at');
    }

    /** Lines that count (not voided). */
    public function activeItems(): HasMany
    {
        return $this->items()->where('status', '!=', OrderItemStatus::Voided->value);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value]);
    }

    public function scopeForBusinessDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('business_date', $date);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }

    public function balanceDue(): Money
    {
        $due = $this->total->subtract($this->paid_total);

        return $due->isNegative() ? Money::zero() : $due;
    }

    /** "0012" style number for screens and receipts. */
    public function displayNumber(): string
    {
        return str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /** Net sales (what the restaurant keeps before tax). */
    public function netTotal(): Money
    {
        return $this->total->subtract($this->tax_total);
    }
}
