<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\Payment;
use App\Modules\Settings\Models\Branch;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/** The numbers behind "Today's sales" (Z-report-lite). */
class DailySummaryService
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function forDate(string $date, Branch|string|null $branch = null): array
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : ($branch ?? $this->context->branchId());

        $paid = Order::query()->forBranch($branchId)->forBusinessDate($date)->where('status', OrderStatus::Paid->value)->get();
        $open = Order::query()->forBranch($branchId)->forBusinessDate($date)->active()->get();
        $void = Order::query()->forBranch($branchId)->forBusinessDate($date)->where('status', OrderStatus::Void->value)->get();

        $sum = fn (Collection $orders, string $field) => $orders->reduce(fn (Money $c, Order $o) => $c->add($o->{$field}), Money::zero());

        $taxLines = [];
        foreach ($paid as $order) {
            foreach ($order->tax_lines ?? [] as $line) {
                $taxLines[$line['code']] ??= ['code' => $line['code'], 'name' => $line['name'], 'amount' => Money::zero()];
                $taxLines[$line['code']]['amount'] = $taxLines[$line['code']]['amount']->add(Money::of($line['amount']));
            }
        }

        $byType = $paid->groupBy(fn (Order $o) => $o->type->value)->map(fn (Collection $orders, string $type) => [
            'type' => $type, 'count' => $orders->count(), 'total' => $sum($orders, 'total'),
        ])->values()->all();

        $payments = Payment::query()->forBranch($branchId)
            ->whereIn('order_id', $paid->pluck('id'))
            ->where('status', 'completed')
            ->get()
            ->groupBy(fn (Payment $p) => $p->method->value)
            ->map(fn (Collection $payments, string $method) => [
                'method' => $method, 'count' => $payments->count(),
                'total' => $payments->reduce(fn (Money $c, Payment $p) => $c->add($p->amount), Money::zero()),
            ])->values()->all();

        $topItems = OrderItem::query()->forBranch($branchId)
            ->whereIn('order_id', $paid->pluck('id'))
            ->where('status', '!=', 'voided')
            ->get()
            ->groupBy('name')
            ->map(fn (Collection $lines, string $name) => [
                'name' => $name,
                'quantity' => $lines->sum('quantity'),
                'total' => $lines->reduce(fn (Money $c, OrderItem $l) => $c->add($l->line_total), Money::zero()),
            ])
            ->sortByDesc('quantity')->take(10)->values()->all();

        return [
            'date' => $date,
            'orders_paid' => $paid->count(),
            'orders_open' => $open->count(),
            'orders_void' => $void->count(),
            'gross_sales' => $sum($paid, 'total'),
            'net_sales' => $sum($paid, 'total')->subtract($sum($paid, 'tax_total')),
            'tax_total' => $sum($paid, 'tax_total'),
            'discount_total' => $sum($paid, 'discount_total'),
            'tax_lines' => array_values($taxLines),
            'by_type' => $byType,
            'by_payment_method' => $payments,
            'top_items' => $topItems,
            'average_order' => $paid->count() ? Money::of(intdiv($sum($paid, 'total')->amount, $paid->count())) : Money::zero(),
            'orders' => $paid->merge($open)->merge($void)->sortByDesc('opened_at')->values(),
        ];
    }
}
