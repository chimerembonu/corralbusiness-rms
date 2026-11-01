<?php

namespace App\Modules\Orders\Models;

use App\Modules\Orders\Enums\DiscountType;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;

/** A discount code typed at the till. Tenant-wide unless pinned to one branch. */
class PromoCode extends BusinessModel
{
    use Auditable;

    protected bool $hasBranch = false;

    protected $fillable = ['branch_id', 'code', 'name', 'type', 'value', 'min_order', 'max_discount', 'starts_at', 'ends_at', 'max_uses', 'is_active'];

    protected $attributes = ['min_order' => 0, 'uses_count' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class, 'value' => 'integer', 'min_order' => 'integer', 'max_discount' => 'integer',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'max_uses' => 'integer', 'uses_count' => 'integer', 'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $code) => $code->code = strtoupper(trim($code->code)));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Why this code cannot be used right now, or null when it can. */
    public function rejectionReason(Money $subtotal): ?string
    {
        return match (true) {
            ! $this->is_active => __('orders::orders.promo.inactive'),
            $this->starts_at && $this->starts_at->isFuture() => __('orders::orders.promo.not_started'),
            $this->ends_at && $this->ends_at->isPast() => __('orders::orders.promo.expired'),
            $this->max_uses !== null && $this->uses_count >= $this->max_uses => __('orders::orders.promo.used_up'),
            $subtotal->lessThan(Money::of($this->min_order)) => __('orders::orders.promo.min_order', ['amount' => money($this->min_order)]),
            default => null,
        };
    }

    /** The discount this code gives on a subtotal (capped). */
    public function discountFor(Money $subtotal): Money
    {
        $discount = $this->type === DiscountType::Percent ? $subtotal->basisPoints($this->value) : Money::of($this->value);
        if ($this->max_discount !== null && $discount->greaterThan(Money::of($this->max_discount))) {
            $discount = Money::of($this->max_discount);
        }

        return $discount->greaterThan($subtotal) ? $subtotal : $discount;
    }

    public function describe(): string
    {
        return $this->type === DiscountType::Percent
            ? rtrim(rtrim(number_format($this->value / 100, 2), '0'), '.').'%'
            : money($this->value);
    }
}
