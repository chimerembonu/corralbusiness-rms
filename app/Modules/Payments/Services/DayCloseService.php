<?php

namespace App\Modules\Payments\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DailySummaryService;
use App\Modules\Payments\Enums\DayCloseStatus;
use App\Modules\Payments\Events\DayClosed;
use App\Modules\Payments\Events\DayReopened;
use App\Modules\Payments\Exceptions\CashException;
use App\Modules\Payments\Exceptions\DayClosedException;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Models\DayClose;
use App\Modules\Settings\Services\CounterService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * X report (a look at the day so far) and Z report (closing the day). Once a day is closed nothing
 * more can be sold or paid on it until a manager reopens it.
 */
class DayCloseService
{
    public function __construct(
        private readonly DailySummaryService $summary,
        private readonly CashService $cash,
        private readonly CounterService $counters,
        private readonly TenantContext $context,
    ) {}

    public function closedDay(string $date, ?string $branchId = null): ?DayClose
    {
        return DayClose::query()->closed()->with(['closedBy', 'approvedBy', 'reopenedBy'])->where('branch_id', $branchId ?? $this->context->branchId())->whereDate('business_date', $date)->first();
    }

    public function isClosed(string $date, ?string $branchId = null): bool
    {
        return $this->closedDay($date, $branchId) !== null;
    }

    /** @throws DayClosedException */
    public function assertOpen(string $date, ?string $branchId = null): void
    {
        if ($this->isClosed($date, $branchId)) {
            throw new DayClosedException($date);
        }
    }

    /**
     * The X report: today's figures so far, plain values (pesewas as ints) so the same array can be
     * frozen as JSON in a Z report.
     *
     * @return array<string, mixed>
     */
    public function report(string $date, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        $summary = $this->summary->forDate($date, $branchId);
        $sessions = $this->cash->forDate($date, $branchId);

        $int = fn (Money|int|null $m) => $m instanceof Money ? $m->amount : (int) $m;
        $sumSessions = fn (string $field) => $sessions->sum(fn (CashierSession $s) => $int($s->{$field}));

        $closedSessions = $sessions->filter(fn (CashierSession $s) => ! $s->isOpen());

        return [
            'date' => $date,
            'generated_at' => now()->toDateTimeString(),
            'orders_paid' => $summary['orders_paid'],
            'orders_open' => $summary['orders_open'],
            'orders_void' => $summary['orders_void'],
            'orders_refunded' => $summary['orders_refunded'],
            'gross_sales' => $int($summary['gross_sales']),
            'net_sales' => $int($summary['net_sales']),
            'tax_total' => $int($summary['tax_total']),
            'discount_total' => $int($summary['discount_total']),
            'service_charge_total' => $int($summary['service_charge_total']),
            'delivery_fee_total' => $int($summary['delivery_fee_total']),
            'tip_total' => $int($summary['tip_total']),
            'refund_total' => $int($summary['refund_total']),
            'average_order' => $int($summary['average_order']),
            'tax_lines' => array_map(fn ($t) => ['code' => $t['code'], 'name' => $t['name'], 'amount' => $int($t['amount'])], $summary['tax_lines']),
            'by_type' => array_map(fn ($r) => ['type' => $r['type'], 'count' => $r['count'], 'total' => $int($r['total'])], $summary['by_type']),
            'by_payment_method' => array_map(fn ($r) => ['method' => $r['method'], 'count' => $r['count'], 'total' => $int($r['total'])], $summary['by_payment_method']),
            'top_items' => array_map(fn ($r) => ['name' => $r['name'], 'quantity' => $r['quantity'], 'total' => $int($r['total'])], $summary['top_items']),
            'cash' => [
                'opening_float' => $sumSessions('opening_float'),
                'cash_sales' => $sumSessions('cash_sales'),
                'cash_tips' => $sumSessions('cash_tips'),
                'cash_refunds' => $sumSessions('cash_refunds'),
                'pay_ins' => $sumSessions('pay_ins'),
                'pay_outs' => $sumSessions('pay_outs'),
                'expected' => $sessions->sum(fn (CashierSession $s) => $s->isOpen() ? $this->cash->expected($s)->amount : $int($s->expected_cash)),
                'counted' => $closedSessions->sum(fn (CashierSession $s) => $int($s->counted_cash)),
                'variance' => $closedSessions->sum(fn (CashierSession $s) => (int) $s->variance),
            ],
            'sessions' => $sessions->map(fn (CashierSession $s) => [
                'id' => $s->id,
                'number' => $s->displayNumber(),
                'till' => $s->till?->name,
                'cashier' => $s->user?->name,
                'status' => $s->status->value,
                'opened_at' => $s->opened_at->format('H:i'),
                'closed_at' => $s->closed_at?->format('H:i'),
                'opening_float' => $int($s->opening_float),
                'cash_sales' => $int($s->cash_sales),
                'pay_ins' => $int($s->pay_ins),
                'pay_outs' => $int($s->pay_outs),
                'expected' => $s->isOpen() ? $this->cash->expected($s)->amount : $int($s->expected_cash),
                'counted' => $s->isOpen() ? null : $int($s->counted_cash),
                'variance' => $s->isOpen() ? null : (int) $s->variance,
            ])->values()->all(),
            'open_sessions' => $sessions->filter(fn (CashierSession $s) => $s->isOpen())->count(),
        ];
    }

    /**
     * What stops the day from closing: open orders and open tills.
     *
     * @return array<int, string> plain-English reasons, empty when the day can close
     */
    public function blockers(string $date, ?string $branchId = null): array
    {
        $branchId ??= $this->context->branchId();
        $blockers = [];

        $openOrders = Order::query()->where('branch_id', $branchId)->forBusinessDate($date)->active()->count();
        if ($openOrders > 0) {
            $blockers[] = trans_choice('payments::payments.day.blockers.open_orders', $openOrders, ['count' => $openOrders]);
        }

        $openTills = $this->cash->openSessions($branchId);
        if ($openTills->isNotEmpty()) {
            $blockers[] = __('payments::payments.day.blockers.open_tills', ['tills' => $openTills->map->label()->join(', ')]);
        }

        return $blockers;
    }

    /** Close the day (Z report). $approvedBy needs the day-close permission (manager PIN). */
    public function close(string $date, User $by, ?User $approvedBy = null, ?string $notes = null, ?string $branchId = null): DayClose
    {
        $branchId ??= $this->context->branchId();
        $approvedBy ??= $by;

        if (! $approvedBy->can(Permission::DayClose->value)) {
            throw new ManagerPinRequiredException(Permission::DayClose->value);
        }
        if ($this->isClosed($date, $branchId)) {
            throw new DayClosedException($date);
        }
        if ($blockers = $this->blockers($date, $branchId)) {
            throw new CashException(implode(' ', $blockers));
        }

        return DB::transaction(function () use ($date, $by, $approvedBy, $notes, $branchId) {
            $close = new DayClose([
                'number' => $this->counters->next('day_closes', null, $branchId),
                'business_date' => $date,
                'closed_at' => now(),
                'closed_by_id' => $by->id,
                'approved_by_id' => $approvedBy->id,
                'report' => $this->report($date, $branchId),
                'notes' => filled($notes) ? trim($notes) : null,
            ]);
            $close->branch_id = $branchId;
            $close->save();

            DayClosed::dispatch($close, $by->id);

            return $close;
        });
    }

    /** Reopen a closed day so late orders can be taken; needs the reopen permission (manager PIN). */
    public function reopen(DayClose $close, User $by, string $reason): DayClose
    {
        if (! $by->can(Permission::DayReopen->value)) {
            throw new ManagerPinRequiredException(Permission::DayReopen->value);
        }
        if (! $close->isClosed()) {
            throw new CashException(__('payments::payments.day.errors.not_closed'));
        }
        if (blank($reason)) {
            throw new CashException(__('orders::orders.errors.reason_required'));
        }

        $close->forceFill([
            'status' => DayCloseStatus::Reopened,
            'reopened_at' => now(),
            'reopened_by_id' => $by->id,
            'reopen_reason' => trim($reason),
        ])->save();

        DayReopened::dispatch($close, $by->id);

        return $close;
    }

    public function latest(?string $branchId = null): ?DayClose
    {
        return DayClose::query()->where('branch_id', $branchId ?? $this->context->branchId())->orderByDesc('number')->first();
    }
}
