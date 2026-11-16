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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * The same orders as salesOrders(), as a query (the SQL form of Order::countsAsSale()). Reports walk it in
     * pages instead of loading every order and line at once, so a year of trading fits in memory
     * (test report 9 Nov 2026, H10: a long date range ran out of memory).
     */
    public function salesQuery(string $from, string $to, ?string $branchId = null): Builder
    {
        return Order::query()->real()->where('branch_id', $branchId ?? $this->context->branchId())
            ->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->where(fn (Builder $q) => $q->where('status', OrderStatus::Paid->value)
                ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Refunded->value)->whereNotNull('refund_business_date')
                    ->whereRaw('date(refund_business_date) <> date(business_date)')));
    }

    /** Sold lines (not voided, not removed) of the range's sales, as a query. */
    public function soldLinesQuery(string $from, string $to, ?string $branchId = null): Builder
    {
        return OrderItem::query()->withoutGlobalScopes()
            ->whereIn('order_id', $this->salesQuery($from, $to, $branchId)->select('id'))
            ->where('status', '!=', OrderItemStatus::Voided->value)
            ->whereNull('deleted_at'); // removed lines were never sold; withoutGlobalScopes() also drops the soft-delete filter (test report H7)
    }

    /**
     * The range's sales as plain rows (money in pesewas as ints), a page at a time. Plain rows, not models:
     * a year of orders is added up in seconds and in a few MB.
     *
     * @return iterable<int, object>
     */
    private function eachSale(string $from, string $to, ?string $branchId): iterable
    {
        $money = ['total', 'tax_total', 'subtotal', 'discount_total', 'service_charge', 'delivery_fee', 'tip_total', 'covers'];
        foreach ($this->salesQuery($from, $to, $branchId)->toBase()->select(array_merge(['id', 'business_date', 'type', 'paid_at', 'opened_at'], $money))->lazyById(self::$page) as $row) {
            foreach ($money as $field) {
                $row->{$field} = (int) $row->{$field};
            }
            $row->business_date = substr((string) $row->business_date, 0, 10);

            yield $row;
        }
    }

    /**
     * Sold lines, a page of orders at a time (one query for the orders' ids, one for their lines): much faster than
     * paging the lines with the sales query as a sub-query, which the database re-runs for every page.
     *
     * @return iterable<int, object> sold lines as plain rows (see soldLinePages())
     */
    private function eachSoldLine(string $from, string $to, ?string $branchId): iterable
    {
        foreach ($this->soldLinePages($from, $to, $branchId) as $lines) {
            yield from $lines;
        }
    }

    /**
     * The sold lines of each page of up to 1,000 sales, as plain rows: id, item_id, name (with the size, as on
     * the receipt), quantity, line_total, tax_total (pesewas), taxable and tax_lines (decoded).
     *
     * @return iterable<int, BaseCollection<int, object>>
     */
    public function soldLinePages(string $from, string $to, ?string $branchId = null): iterable
    {
        foreach ($this->salesQuery($from, $to, $branchId)->select('id')->toBase()->lazyById(self::$page)->chunk(self::$page) as $page) {
            yield OrderItem::query()->withoutGlobalScopes()->toBase()->whereIn('order_id', $page->pluck('id')->all())
                ->where('status', '!=', OrderItemStatus::Voided->value)->whereNull('deleted_at')->orderBy('id')
                ->get(['id', 'item_id', 'name', 'variant_name', 'quantity', 'line_total', 'tax_total', 'taxable', 'tax_lines'])
                ->map(function (object $l) {
                    $l->name = $l->variant_name ? "{$l->name} ({$l->variant_name})" : $l->name; // OrderItem::displayName()
                    $l->quantity = (int) $l->quantity;
                    $l->line_total = (int) $l->line_total;
                    $l->tax_total = (int) $l->tax_total;
                    $l->taxable = (bool) $l->taxable;
                    $l->tax_lines = is_string($l->tax_lines) ? (json_decode($l->tax_lines, true) ?: []) : [];

                    return $l;
                });
        }
    }

    /** Rows read per page (tests make it tiny to cross many pages). */
    public static int $page = 1000;

    /** @param  array<string, int>  $totals  adds one order's money fields */
    private static function addOrder(array &$totals, object $o): void
    {
        $totals['orders'] = ($totals['orders'] ?? 0) + 1;
        $totals['gross'] = ($totals['gross'] ?? 0) + $o->total;
        $totals['tax'] = ($totals['tax'] ?? 0) + $o->tax_total;
        $totals['discounts'] = ($totals['discounts'] ?? 0) + $o->discount_total;
    }

    /** @return array<string, mixed> */
    public function summary(string $from, string $to, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        $t = ['orders' => 0, 'covers' => 0, 'total' => 0, 'tax_total' => 0, 'subtotal' => 0, 'discount_total' => 0, 'service_charge' => 0, 'delivery_fee' => 0, 'tip_total' => 0];
        foreach ($this->eachSale($from, $to, $branchId) as $o) {
            $t['orders']++;
            foreach (['covers', 'total', 'tax_total', 'subtotal', 'discount_total', 'service_charge', 'delivery_fee', 'tip_total'] as $field) {
                $t[$field] += $o->{$field};
            }
        }
        $refundedOrders = Order::query()->real()->where('branch_id', $branchId)->where('status', OrderStatus::Refunded->value)
            ->whereDate('refund_business_date', '>=', $from)->whereDate('refund_business_date', '<=', $to)->count();
        $voids = Order::query()->real()->where('branch_id', $branchId)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->where('status', OrderStatus::Void->value)->get();
        $refunds = Payment::query()->real()->where('branch_id', $branchId)->where('status', PaymentStatus::Refunded->value)
            ->whereDate('refund_business_date', '>=', $from)->whereDate('refund_business_date', '<=', $to)->get();
        $days = max(1, (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'orders' => $t['orders'],
            'orders_void' => $voids->count(),
            'orders_refunded' => $refundedOrders,
            'covers' => $t['covers'],
            'gross_sales' => $t['total'],
            'net_sales' => $t['total'] - $t['tax_total'],
            'subtotal' => $t['subtotal'],
            'tax_total' => $t['tax_total'],
            'discount_total' => $t['discount_total'],
            'service_charge_total' => $t['service_charge'],
            'delivery_fee_total' => $t['delivery_fee'],
            'tip_total' => $t['tip_total'],
            'refund_total' => (int) $refunds->sum(fn (Payment $p) => $p->amount->amount),
            'void_value' => (int) $voids->sum(fn (Order $o) => $o->total->amount),
            'average_order' => $t['orders'] ? intdiv($t['total'], $t['orders']) : 0,
            'average_per_day' => intdiv($t['total'], $days),
        ];
    }

    /** @return array<int, array{date: string, orders: int, gross: int, net: int, tax: int, discounts: int}> */
    public function byDay(string $from, string $to, ?string $branchId = null): array
    {
        $days = [];
        foreach ($this->eachSale($from, $to, $branchId) as $o) {
            $days[$o->business_date] ??= [];
            self::addOrder($days[$o->business_date], $o);
        }
        $rows = [];
        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $own = $days[$day->toDateString()] ?? [];
            $rows[] = [
                'date' => $day->toDateString(),
                'orders' => $own['orders'] ?? 0,
                'gross' => $own['gross'] ?? 0,
                'net' => ($own['gross'] ?? 0) - ($own['tax'] ?? 0),
                'tax' => $own['tax'] ?? 0,
                'discounts' => $own['discounts'] ?? 0,
            ];
        }

        return $rows;
    }

    /** @return array<int, array{hour: int, label: string, orders: int, gross: int}> every hour that had a sale */
    public function byHour(string $from, string $to, ?string $branchId = null): array
    {
        $hours = [];
        foreach ($this->eachSale($from, $to, $branchId) as $o) {
            $h = (int) substr((string) ($o->paid_at ?? $o->opened_at), 11, 2); // stored in the restaurant's time zone
            $hours[$h] ??= [];
            self::addOrder($hours[$h], $o);
        }
        $rows = [];
        for ($h = 0; $h < 24; $h++) {
            if (! isset($hours[$h])) {
                continue;
            }
            $rows[] = ['hour' => $h, 'label' => sprintf('%02d:00–%02d:00', $h, ($h + 1) % 24), 'orders' => $hours[$h]['orders'], 'gross' => $hours[$h]['gross']];
        }

        return $rows;
    }

    /**
     * Sold lines added up per dish (name and size): quantity, gross, net and the dish's first menu item.
     *
     * @return array<string, array{item_id: ?string, quantity: int, gross: int, net: int}>
     */
    private function linesByDish(string $from, string $to, ?string $branchId): array
    {
        $dishes = [];
        foreach ($this->eachSoldLine($from, $to, $branchId) as $l) {
            $name = $l->name;
            $dishes[$name] ??= ['item_id' => $l->item_id, 'quantity' => 0, 'gross' => 0, 'net' => 0, 'items' => []];
            $dishes[$name]['quantity'] += $l->quantity;
            $dishes[$name]['gross'] += $l->line_total;
            $dishes[$name]['net'] += $l->line_total - $l->tax_total;
            $dishes[$name]['items'][(string) $l->item_id] = true;
        }

        return $dishes;
    }

    /** @return array<int, array{name: string, category: string, quantity: int, gross: int, net: int, share: float}> best sellers first */
    public function byItem(string $from, string $to, ?string $branchId = null): array
    {
        $dishes = $this->linesByDish($from, $to, $branchId);
        $categories = $this->categoryNames($dishes);
        $total = max(1, array_sum(array_column($dishes, 'gross')));

        return collect($dishes)->map(fn (array $d, string $name) => [
            'name' => (string) $name,
            'category' => $categories[$d['item_id']] ?? __('reports::reports.other'),
            'quantity' => $d['quantity'],
            'gross' => $d['gross'],
            'net' => $d['net'],
            'share' => round($d['gross'] / $total * 100, 1),
        ])->sortByDesc('gross')->values()->all();
    }

    /** @return array<int, array{category: string, quantity: int, gross: int, share: float}> */
    public function byCategory(string $from, string $to, ?string $branchId = null): array
    {
        // Per menu item first (a dish name can span items), then per category.
        $perItem = [];
        foreach ($this->eachSoldLine($from, $to, $branchId) as $l) {
            $perItem[(string) $l->item_id] ??= ['item_id' => $l->item_id, 'quantity' => 0, 'gross' => 0, 'items' => [(string) $l->item_id => true]];
            $perItem[(string) $l->item_id]['quantity'] += $l->quantity;
            $perItem[(string) $l->item_id]['gross'] += $l->line_total;
        }
        $categories = $this->categoryNames($perItem);
        $total = max(1, array_sum(array_column($perItem, 'gross')));
        $rows = [];
        foreach ($perItem as $p) {
            $category = $categories[$p['item_id']] ?? __('reports::reports.other');
            $rows[$category] ??= ['category' => $category, 'quantity' => 0, 'gross' => 0];
            $rows[$category]['quantity'] += $p['quantity'];
            $rows[$category]['gross'] += $p['gross'];
        }

        return collect($rows)->map(fn (array $r) => $r + ['share' => round($r['gross'] / $total * 100, 1)])->sortByDesc('gross')->values()->all();
    }

    /** @return array<int, array{type: string, label: string, orders: int, gross: int, average: int}> */
    public function byType(string $from, string $to, ?string $branchId = null): array
    {
        $types = [];
        foreach ($this->eachSale($from, $to, $branchId) as $o) {
            $types[$o->type] ??= [];
            self::addOrder($types[$o->type], $o);
        }

        return collect($types)->map(fn (array $own, string $type) => [
            'type' => $type,
            'label' => OrderType::from($type)->label(),
            'orders' => $own['orders'],
            'gross' => $own['gross'],
            'average' => intdiv($own['gross'], max(1, $own['orders'])),
        ])->sortByDesc('gross')->values()->all();
    }

    /** @return array<int, array{method: string, label: string, count: int, total: int, tips: int, refunded: int}> */
    public function byPaymentMethod(string $from, string $to, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        // Money taken in the range counts here unless it was refunded inside the same range; refunds count on
        // the day they were given (refund_business_date), so a closed day's figures never move (security review L3).
        $inRange = fn (?string $d) => $d !== null && substr($d, 0, 10) >= $from && substr($d, 0, 10) <= $to;
        $columns = ['id', 'method', 'provider', 'status', 'amount', 'tip', 'refund_business_date'];
        // Plain rows a page at a time: a year of payments no longer has to fit in memory (test report H10).
        $received = Payment::query()->real()->where('branch_id', $branchId)->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])->toBase()->select($columns);
        $refunds = Payment::query()->real()->where('branch_id', $branchId)->where('status', PaymentStatus::Refunded->value)
            ->whereDate('refund_business_date', '>=', $from)->whereDate('refund_business_date', '<=', $to)->toBase()->select($columns);

        $groups = [];
        $group = function (object $p) use (&$groups): string {
            $key = $p->method.($p->provider ? ':'.$p->provider : '');
            $groups[$key] ??= ['method' => $key, 'label' => (new Payment)->forceFill(['method' => $p->method, 'provider' => $p->provider])->methodLabel(), 'count' => 0, 'total' => 0, 'tips' => 0, 'refunded' => 0];

            return $key;
        };
        foreach ($received->lazyById(self::$page) as $p) {
            $key = $group($p);
            // Money taken in the range counts here unless it was refunded inside the same range.
            if ($p->status === PaymentStatus::Completed->value || ! $inRange($p->refund_business_date)) {
                $groups[$key]['count']++;
                $groups[$key]['total'] += (int) $p->amount;
                $groups[$key]['tips'] += (int) $p->tip;
            }
        }
        // Refunds count on the day they were given (refund_business_date), so a closed day's figures never move (security review L3).
        foreach ($refunds->lazyById(self::$page) as $p) {
            $groups[$group($p)]['refunded'] += (int) $p->amount;
        }

        return collect($groups)->sortByDesc('total')->values()->all();
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
        $components = [];
        $taxableNet = 0;
        $exemptNet = 0;

        foreach ($this->eachSoldLine($from, $to, $branchId) as $line) {
            $net = $line->line_total - $line->tax_total;
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

        $orders = [];
        foreach ($this->eachSale($from, $to, $branchId) as $o) {
            self::addOrder($orders, $o);
        }

        return [
            'components' => array_values($components),
            'taxable_net' => $taxableNet,
            'exempt_net' => $exemptNet,
            'tax_total' => $orders['tax'] ?? 0,
            'gross' => $orders['gross'] ?? 0,
        ];
    }

    /** @param  array<array-key, array{items: array<string, true>}>  $groups
     * @return array<string, string> menu item id → category name */
    private function categoryNames(array $groups): array
    {
        $ids = collect($groups)->flatMap(fn (array $g) => array_keys($g['items']))->filter()->unique()->values();

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
