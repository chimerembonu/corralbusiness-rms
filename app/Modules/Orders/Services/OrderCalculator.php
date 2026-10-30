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
 *  discount_amount = the order discount spread across lines in proportion, never losing a pesewa
 *  tax             = tax engine on (line_subtotal − discount), per line, rounded to the pesewa
 *  line_total      = what the customer pays for the line (net + tax, or the inclusive price)
 *  order total     = Σ line_total (+ service charge, Phase 1)
 */
class OrderCalculator
{
    public function __construct(private readonly TaxCalculator $taxes) {}

    /** @param  iterable<int, OrderItem>  $items  active (non-voided) lines */
    public function recalculate(Order $order, iterable $items, TaxProfile $profile): void
    {
        $items = collect($items);

        foreach ($items as $item) {
            $item->line_subtotal = $item->unitTotal()->multiply($item->quantity);
        }

        $subtotal = $items->reduce(fn (Money $carry, OrderItem $i) => $carry->add($i->line_subtotal), Money::zero());
        $discount = $this->discountFor($order, $subtotal);
        $shares = $items->isEmpty() || $discount->isZero()
            ? []
            : $discount->allocate($items->map(fn (OrderItem $i) => max($i->line_subtotal->amount, 0))->all());

        $results = [];
        foreach ($items as $index => $item) {
            $item->discount_amount = $shares[$index] ?? Money::zero();
            $taxable = $item->line_subtotal->subtract($item->discount_amount);

            $result = $this->taxes->calculate($taxable, $profile, $item->taxable, $item->tax_exempt_codes ?? []);
            $item->tax_lines = array_map(fn ($l) => $l->toArray(), $result->lines);
            $item->tax_total = $result->tax();
            $item->line_total = $result->gross;
            $results[] = $result;
        }

        $summary = TaxResult::sum(...$results);

        $order->subtotal = $subtotal;
        $order->discount_total = $discount;
        $order->tax_lines = array_map(fn ($l) => $l->toArray(), $summary->lines);
        $order->tax_total = $summary->tax();
        $order->total = $summary->gross->add($order->service_charge);
        $order->prices_include_tax = $profile->pricesIncludeTax;
    }

    private function discountFor(Order $order, Money $subtotal): Money
    {
        if ($order->discount_type === null || $order->discount_value <= 0 || $subtotal->isZero()) {
            return Money::zero();
        }

        $discount = $order->discount_type === DiscountType::Percent
            ? $subtotal->basisPoints($order->discount_value)
            : Money::of($order->discount_value);

        return $discount->greaterThan($subtotal) ? $subtotal : $discount;
    }
}
