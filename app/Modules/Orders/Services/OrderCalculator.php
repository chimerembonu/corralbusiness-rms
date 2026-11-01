<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Money\Money;
use App\Support\Tax\TaxCalculator;
use App\Support\Tax\TaxProfile;
use App\Support\Tax\TaxResult;

/**
 * Recomputes every money figure on an order from its lines. Pure maths; the caller saves.
 *
 *  line_subtotal   = quantity × (unit price + modifiers)   – the price as entered
 *  item discount   = the line's own discount (percent/fixed), if any
 *  order discount  = manual or promo discount on what is left, spread across lines in proportion, never losing a pesewa
 *  tax             = tax engine on (line_subtotal − discounts), per line, rounded to the pesewa
 *  line_total      = what the customer pays for the line (net + tax, or the inclusive price)
 *  service charge  = rate × (subtotal − discounts), not taxed, unless waived
 *  delivery fee    = flat amount on delivery orders, not taxed
 *  order total     = Σ line_total + service charge + delivery fee   (tips are recorded on payments, outside the total)
 */
class OrderCalculator
{
    public function __construct(private readonly TaxCalculator $taxes) {}

    /** @param  iterable<int, OrderItem>  $items  active (non-voided) lines */
    public function recalculate(Order $order, iterable $items, TaxProfile $profile): void
    {
        $items = collect($items);

        $itemDiscounts = [];
        foreach ($items as $index => $item) {
            $item->line_subtotal = $item->unitTotal()->multiply($item->quantity);
            $itemDiscounts[$index] = $item->itemDiscount();
        }

        $subtotal = $items->reduce(fn (Money $carry, OrderItem $i) => $carry->add($i->line_subtotal), Money::zero());
        $itemDiscountTotal = array_reduce($itemDiscounts, fn (Money $c, Money $d) => $c->add($d), Money::zero());
        $afterItemDiscounts = $subtotal->subtract($itemDiscountTotal);

        $orderDiscount = $this->orderDiscountFor($order, $afterItemDiscounts);
        $shares = $items->isEmpty() || $orderDiscount->isZero()
            ? []
            : $orderDiscount->allocate($items->map(fn (OrderItem $i, $index) => max($i->line_subtotal->subtract($itemDiscounts[$index])->amount, 0))->values()->all());

        $results = [];
        foreach ($items->values() as $index => $item) {
            $item->discount_amount = $itemDiscounts[$index]->add($shares[$index] ?? Money::zero());
            $taxable = $item->line_subtotal->subtract($item->discount_amount);

            $result = $this->taxes->calculate($taxable, $profile, $item->taxable, $item->tax_exempt_codes ?? []);
            $item->tax_lines = array_map(fn ($l) => $l->toArray(), $result->lines);
            $item->tax_total = $result->tax();
            $item->line_total = $result->gross;
            $results[] = $result;
        }

        $summary = TaxResult::sum(...$results);
        $discountTotal = $itemDiscountTotal->add($orderDiscount);

        $order->subtotal = $subtotal;
        $order->discount_total = $discountTotal;
        $order->tax_lines = array_map(fn ($l) => $l->toArray(), $summary->lines);
        $order->tax_total = $summary->tax();
        $order->service_charge = $order->service_charge_waived || $order->service_charge_bp <= 0
            ? Money::zero()
            : $subtotal->subtract($discountTotal)->basisPoints($order->service_charge_bp);
        $order->total = $summary->gross->add($order->service_charge)->add($order->delivery_fee);
        $order->prices_include_tax = $profile->pricesIncludeTax;
    }

    /** The manual or promo-code discount on the order, applied to what is left after item discounts. */
    private function orderDiscountFor(Order $order, Money $base): Money
    {
        if ($order->discount_type === null || $order->discount_value <= 0 || $base->isZero()) {
            return Money::zero();
        }

        $discount = $order->discount_type === DiscountType::Percent
            ? $base->basisPoints($order->discount_value)
            : Money::of($order->discount_value);

        if ($order->promo_code_id && $order->relationLoaded('promoCode') && $order->promoCode?->max_discount !== null) {
            $cap = Money::of($order->promoCode->max_discount);
            $discount = $discount->greaterThan($cap) ? $cap : $discount;
        }

        return $discount->greaterThan($base) ? $base : $discount;
    }
}
