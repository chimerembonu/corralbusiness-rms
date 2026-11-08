<?php

namespace App\Modules\Reports\Services;

use App\Modules\Menu\Models\Item;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Services\StaffSalesService;
use App\Support\Audit\Activity;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Every sales report for a date range at a branch. Rows are plain arrays with pesewas as ints,
 * so the same data feeds the screen, Excel and PDF.
 */
class ReportService
{
    public function __construct(private readonly TenantContext $context, private readonly StaffSalesService $staffSales) {}

    /** @return Collection<int, Order> paid orders (and refunded ones, which were paid first) in the range */
    public function paidOrders(string $from, string $to, ?string $branchId = null): Collection
    {
        return Order::query()->real()->where('branch_id', $branchId ?? $this->context->branchId())
            ->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Refunded->value])
            ->with('table')->get();
    }

    /** Sales of the range: paid orders, plus fully refunded ones whose refund was posted on a later day (security review L3). */
    public function salesOrders(string $from, string $to, ?string $branchId = null): Collection
    {
        return $this->paidOrders($from, $to, $branchId)->filter(fn (Order $o) => $o->countsAsSale())->values();
    }

    /** @return array<string, mixed> */
    public function summary(string $from, string $to, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        $paid = $this->salesOrders($from, $to, $branchId);
        $refundedOrders = Order::query()->real()->where('branch_id', $branchId)->where('status', OrderStatus::Refunded->value)
            ->whereDate('refund_business_date', '>=', $from)->whereDate('refund_business_date', '<=', $to)->count();
        $sum = fn (BaseCollection $rows, string $field) => (int) $rows->sum(fn (Order $o) => $o->{$field}->amount);

        $voids = Order::query()->real()->where('branch_id', $branchId)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('status', OrderStatus::Void->value)->get();
        $refunds = Payment::query()->real()->where('branch_id', $branchId)->where('status', PaymentStatus::Refunded->value)
            ->whereDate('refund_business_date', '>=', $from)->whereDate('refund_business_date', '<=', $to)->get();
        $days = max(1, (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'orders' => $paid->count(),
            'orders_void' => $voids->count(),
            'orders_refunded' => $refundedOrders,
            'covers' => (int) $paid->sum('covers'),
            'gross_sales' => $sum($paid, 'total'),
            'net_sales' => $sum($paid, 'total') - $sum($paid, 'tax_total'),
            'subtotal' => $sum($paid, 'subtotal'),
            'tax_total' => $sum($paid, 'tax_total'),
            'discount_total' => $sum($paid, 'discount_total'),
            'service_charge_total' => $sum($paid, 'service_charge'),
            'delivery_fee_total' => $sum($paid, 'delivery_fee'),
            'tip_total' => $sum($paid, 'tip_total'),
            'refund_total' => (int) $refunds->sum(fn (Payment $p) => $p->amount->amount),
            'void_value' => (int) $voids->sum(fn (Order $o) => $o->total->amount),
            'average_order' => $paid->count() ? intdiv($sum($paid, 'total'), $paid->count()) : 0,
            'average_per_day' => intdiv($sum($paid, 'total'), $days),
        ];
    }

    /** @return array<int, array{date: string, orders: int, gross: int, net: int, tax: int, discounts: int}> */
    public function byDay(string $from, string $to, ?string $branchId = null): array
    {
        $orders = $this->salesOrders($from, $to, $branchId)->groupBy(fn (Order $o) => $o->business_date->toDateString());
        $rows = [];
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $own = $orders->get($day->toDateString(), collect());
            $rows[] = [
                'date' => $day->toDateString(),
                'orders' => $own->count(),
                'gross' => (int) $own->sum(fn (Order $o) => $o->total->amount),
                'net' => (int) $own->sum(fn (Order $o) => $o->total->amount - $o->tax_total->amount),
                'tax' => (int) $own->sum(fn (Order $o) => $o->tax_total->amount),
                'discounts' => (int) $own->sum(fn (Order $o) => $o->discount_total->amount),
            ];
        }

        return $rows;
    }

    /** @return array<int, array{hour: int, label: string, orders: int, gross: int}> every hour that had a sale */
    public function byHour(string $from, string $to, ?string $branchId = null): array
    {
        $orders = $this->salesOrders($from, $to, $branchId)->groupBy(fn (Order $o) => (int) ($o->paid_at ?? $o->opened_at)->format('G'));
        $rows = [];
        for ($h = 0; $h < 24; $h++) {
            $own = $orders->get($h, collect());
            if ($own->isEmpty()) {
                continue;
            }
            $rows[] = ['hour' => $h, 'label' => sprintf('%02d:00–%02d:00', $h, ($h + 1) % 24), 'orders' => $own->count(), 'gross' => (int) $own->sum(fn (Order $o) => $o->total->amount)];
        }

        return $rows;
    }

    /** @return Collection<int, OrderItem> sold lines (not voided) of paid orders */
    private function soldLines(string $from, string $to, ?string $branchId = null): Collection
    {
        $orderIds = $this->salesOrders($from, $to, $branchId)->pluck('id');

        return OrderItem::query()->withoutGlobalScopes()->whereIn('order_id', $orderIds)->where('status', '!=', OrderItemStatus::Voided->value)->get();
    }

    /** @return array<int, array{name: string, category: string, quantity: int, gross: int, net: int, share: float}> best sellers first */
    public function byItem(string $from, string $to, ?string $branchId = null): array
    {
        $lines = $this->soldLines($from, $to, $branchId);
        $categories = $this->categoryNames($lines);
        $total = max(1, (int) $lines->sum(fn (OrderItem $l) => $l->line_total->amount));

        return $lines->groupBy(fn (OrderItem $l) => $l->displayName())->map(fn (BaseCollection $group, string $name) => [
            'name' => $name,
            'category' => $categories[$group->first()->item_id] ?? __('reports::reports.other'),
            'quantity' => (int) $group->sum('quantity'),
            'gross' => (int) $group->sum(fn (OrderItem $l) => $l->line_total->amount),
            'net' => (int) $group->sum(fn (OrderItem $l) => $l->line_total->amount - $l->tax_total->amount),
            'share' => round($group->sum(fn (OrderItem $l) => $l->line_total->amount) / $total * 100, 1),
        ])->sortByDesc('gross')->values()->all();
    }

    /** @return array<int, array{category: string, quantity: int, gross: int, share: float}> */
    public function byCategory(string $from, string $to, ?string $branchId = null): array
    {
        $lines = $this->soldLines($from, $to, $branchId);
        $categories = $this->categoryNames($lines);
        $total = max(1, (int) $lines->sum(fn (OrderItem $l) => $l->line_total->amount));

        return $lines->groupBy(fn (OrderItem $l) => $categories[$l->item_id] ?? __('reports::reports.other'))->map(fn (BaseCollection $group, string $category) => [
            'category' => $category,
            'quantity' => (int) $group->sum('quantity'),
            'gross' => (int) $group->sum(fn (OrderItem $l) => $l->line_total->amount),
            'share' => round($group->sum(fn (OrderItem $l) => $l->line_total->amount) / $total * 100, 1),
        ])->sortByDesc('gross')->values()->all();
    }

    /** @return array<int, array{type: string, label: string, orders: int, gross: int, average: int}> */
    public function byType(string $from, string $to, ?string $branchId = null): array
    {
        return $this->salesOrders($from, $to, $branchId)->groupBy(fn (Order $o) => $o->type->value)->map(fn (BaseCollection $own, string $type) => [
            'type' => $type,
            'label' => OrderType::from($type)->label(),
            'orders' => $own->count(),
            'gross' => (int) $own->sum(fn (Order $o) => $o->total->amount),
            'average' => intdiv((int) $own->sum(fn (Order $o) => $o->total->amount), max(1, $own->count())),
        ])->sortByDesc('gross')->values()->all();
    }

    /** @return array<int, array{method: string, label: string, count: int, total: int, tips: int, refunded: int}> */
    public function byPaymentMethod(string $from, string $to, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        // Money taken in the range counts here unless it was refunded inside the same range; refunds count on
        // the day they were given (refund_business_date), so a closed day's figures never move (security review L3).
        $inRange = fn (?Carbon $d) => $d !== null && $d->toDateString() >= $from && $d->toDateString() <= $to;
        $received = Payment::query()->real()->where('branch_id', $branchId)->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
        $refunds = Payment::query()->real()->where('branch_id', $branchId)->where('status', PaymentStatus::Refunded->value)
            ->whereDate('refund_business_date', '>=', $from)->whereDate('refund_business_date', '<=', $to)->get();
        $key = fn (Payment $p) => $p->method->value.($p->provider ? ':'.$p->provider->value : '');
        $taken = $received->filter(fn (Payment $p) => $p->status === PaymentStatus::Completed || ! $inRange($p->refund_business_date));

        return $received->merge($refunds)->unique('id')->groupBy($key)->map(function (BaseCollection $all, string $method) use ($taken, $refunds, $key) {
            $own = $taken->filter(fn (Payment $p) => $key($p) === $method);
            $back = $refunds->filter(fn (Payment $p) => $key($p) === $method);

            return [
                'method' => $method,
                'label' => $all->first()->methodLabel(),
                'count' => $own->count(),
                'total' => (int) $own->sum(fn (Payment $p) => $p->amount->amount),
                'tips' => (int) $own->sum(fn (Payment $p) => $p->tip->amount),
                'refunded' => (int) $back->sum(fn (Payment $p) => $p->amount->amount),
            ];
        })->sortByDesc('total')->values()->all();
    }

    /** @return array<int, array{name: string, role: string, orders: int, sales: int, average: int, voids: int, payments: int, taken: int, tips: int}> */
    public function byStaff(string $from, string $to, ?string $branchId = null): array
    {
        return $this->staffSales->between($from, $to, $branchId)->map(fn (array $r) => [
            'name' => $r['user']->name, 'role' => $r['user']->rolesLabel(), 'orders' => $r['orders'], 'sales' => $r['sales']->amount, 'average' => $r['average']->amount,
            'voids' => $r['voids'], 'payments' => $r['payments'], 'taken' => $r['taken']->amount, 'tips' => $r['tips']->amount,
        ])->all();
    }

    /**
     * Cancelled orders, voided lines, discounts and refunds, with who did them.
     *
     * @return array{voided_orders: array, voided_lines: array, discounts: array, refunds: array, totals: array<string, int>}
     */
    public function voidsAndDiscounts(string $from, string $to, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        $inRange = fn ($q) => $q->real()->where('branch_id', $branchId)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to);

        $voidedOrders = Order::query()->tap($inRange)->where('status', OrderStatus::Void->value)->with(['voidedBy', 'openedBy'])->orderBy('voided_at')->get();
        $orderIds = Order::query()->tap($inRange)->pluck('id');
        $voidedLines = OrderItem::query()->withoutGlobalScopes()->whereIn('order_id', $orderIds)->where('status', OrderItemStatus::Voided->value)->with(['order', 'voidedBy'])->orderBy('voided_at')->get();
        // Lines taken off before the order was sent (soft-deleted), so quiet removals are visible too (security review H5).
        $removedLines = OrderItem::query()->withoutGlobalScopes()->whereIn('order_id', $orderIds)->whereNotNull('deleted_at')->with(['order' => fn ($q) => $q->withoutGlobalScopes(), 'removedBy'])->orderBy('deleted_at')->get();
        $discounted = Order::query()->tap($inRange)->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Refunded->value])->where('discount_total', '>', 0)->with(['discountApprovedBy', 'openedBy', 'items' => fn ($q) => $q->whereNotNull('item_discount_approved_by_id')->with('itemDiscountApprovedBy')])->orderBy('paid_at')->get();
        $refunds = Payment::query()->real()->where('branch_id', $branchId)->where('status', PaymentStatus::Refunded->value)
            ->whereBetween('refunded_at', [$from.' 00:00:00', $to.' 23:59:59'])->with(['order', 'refundedBy'])->orderBy('refunded_at')->get();
        // Lines made smaller, before or after the bill was printed (N4), from the activity log.
        $reduced = Activity::query()->where('branch_id', $branchId)->whereIn('description', ['quantity_reduced', 'quantity_reduced_after_bill'])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->with('causer')->orderBy('created_at')->get();

        return [
            'voided_orders' => $voidedOrders->map(fn (Order $o) => ['when' => $o->voided_at?->format('d/m H:i') ?? '', 'order' => '#'.$o->displayNumber(), 'detail' => $o->void_reason ?? '', 'value' => $o->total->amount, 'by' => $o->voidedBy?->name ?? '', 'opened_by' => $o->openedBy?->name ?? ''])->all(),
            'removed_lines' => $removedLines->map(fn (OrderItem $l) => ['when' => $l->deleted_at?->format('d/m H:i') ?? '', 'order' => '#'.$l->order->displayNumber(), 'detail' => $l->quantity.' × '.$l->displayName(), 'value' => $l->line_total->amount, 'by' => $l->removedBy?->name ?? ''])->all(),
            'reduced_lines' => $reduced->map(fn (Activity $a) => ['when' => $a->created_at->format('d/m H:i'), 'order' => '#'.($a->properties['number'] ?? ''), 'detail' => ($a->properties['line'] ?? '').' '.($a->properties['from'] ?? '').' → '.($a->properties['to'] ?? '').($a->description === 'quantity_reduced_after_bill' ? ' · '.__('reports::reports.voids.after_bill') : ''), 'value' => (int) ($a->properties['value'] ?? 0), 'by' => ($a->causer?->name ?? '').(($a->properties['approved_by_name'] ?? null) && $a->properties['approved_by_name'] !== $a->causer?->name ? ' · '.__('reports::reports.voids.approved_by', ['name' => $a->properties['approved_by_name']]) : '')])->all(),
            'voided_lines' => $voidedLines->map(fn (OrderItem $l) => ['when' => $l->voided_at?->format('d/m H:i') ?? '', 'order' => '#'.$l->order->displayNumber(), 'detail' => $l->quantity.' × '.$l->displayName().($l->void_reason ? ' · '.$l->void_reason : ''), 'value' => $l->line_total->amount, 'by' => $l->voidedBy?->name ?? ''])->all(),
            'discounts' => $discounted->map(fn (Order $o) => ['when' => $o->paid_at?->format('d/m H:i') ?? '', 'order' => '#'.$o->displayNumber(), 'detail' => trim(($o->discountLabel() ?? '').' '.($o->discount_reason ?? '').($o->promo_code ? ' · '.$o->promo_code : '')), 'value' => $o->discount_total->amount, 'by' => $o->discountApprovedBy?->name ?? $o->items->first()?->itemDiscountApprovedBy?->name ?? $o->openedBy?->name ?? ''])->all(),
            'refunds' => $refunds->map(fn (Payment $p) => ['when' => $p->refunded_at?->format('d/m H:i') ?? '', 'order' => $p->order ? '#'.$p->order->displayNumber() : '', 'detail' => $p->methodLabel().($p->refund_reason ? ' · '.$p->refund_reason : ''), 'value' => $p->amount->amount, 'by' => $p->refundedBy?->name ?? ''])->all(),
            'totals' => [
                'voided_orders' => (int) $voidedOrders->sum(fn (Order $o) => $o->total->amount),
                'voided_lines' => (int) $voidedLines->sum(fn (OrderItem $l) => $l->line_total->amount),
                'removed_lines' => (int) $removedLines->sum(fn (OrderItem $l) => $l->line_total->amount),
                'reduced_lines' => (int) $reduced->sum(fn (Activity $a) => (int) ($a->properties['value'] ?? 0)),
                'discounts' => (int) $discounted->sum(fn (Order $o) => $o->discount_total->amount),
                'refunds' => (int) $refunds->sum(fn (Payment $p) => $p->amount->amount),
            ],
        ];
    }

    /**
     * Tax collected per component, with the taxable net it was charged on, plus exempt sales.
     *
     * @return array{components: array<int, array{code: string, name: string, rate: string, taxable_net: int, tax: int}>, taxable_net: int, exempt_net: int, tax_total: int, gross: int}
     */
    public function tax(string $from, string $to, ?string $branchId = null): array
    {
        $lines = $this->soldLines($from, $to, $branchId);
        $components = [];
        $taxableNet = 0;
        $exemptNet = 0;

        foreach ($lines as $line) {
            $net = $line->line_total->amount - $line->tax_total->amount;
            if (! $line->taxable || empty($line->tax_lines)) {
                $exemptNet += $net;

                continue;
            }
            $taxableNet += $net;
            foreach ($line->tax_lines as $t) {
                $components[$t['code']] ??= ['code' => $t['code'], 'name' => $t['name'], 'rate' => rtrim(rtrim(number_format($t['rate_basis_points'] / 100, 2), '0'), '.').'%', 'taxable_net' => 0, 'tax' => 0];
                $components[$t['code']]['taxable_net'] += $net;
                $components[$t['code']]['tax'] += (int) $t['amount'];
            }
        }

        $orders = $this->salesOrders($from, $to, $branchId);

        return [
            'components' => array_values($components),
            'taxable_net' => $taxableNet,
            'exempt_net' => $exemptNet,
            'tax_total' => (int) $orders->sum(fn (Order $o) => $o->tax_total->amount),
            'gross' => (int) $orders->sum(fn (Order $o) => $o->total->amount),
        ];
    }

    /** @param  Collection<int, OrderItem>  $lines
     * @return array<string, string> menu item id → category name */
    private function categoryNames(Collection $lines): array
    {
        $ids = $lines->pluck('item_id')->filter()->unique();

        return Item::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->with('category')->get()
            ->mapWithKeys(fn (Item $i) => [$i->id => $i->category?->name ?? __('reports::reports.other')])->all();
    }

    public static function money(int $pesewas): string
    {
        return money($pesewas);
    }

    public static function decimal(int $pesewas): string
    {
        return Money::of($pesewas)->toDecimal();
    }
}
