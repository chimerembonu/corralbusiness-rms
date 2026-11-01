<?php

namespace App\Modules\Staff\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/** Sales per staff member: orders they opened and payments they took, over a date range. */
class StaffSalesService
{
    public function __construct(private readonly TenantContext $context, private readonly RosterService $roster) {}

    /**
     * @return Collection<int, array{user: User, orders: int, sales: Money, average: Money, voids: int, payments: int, taken: Money, tips: Money}>
     */
    public function between(string $from, string $to, ?string $branchId = null): Collection
    {
        $branchId ??= $this->context->branchId();

        $orders = Order::query()->where('branch_id', $branchId)->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Void->value, OrderStatus::Refunded->value])->get()
            ->groupBy('opened_by_id');

        $payments = Payment::query()->where('branch_id', $branchId)
            ->whereBetween('received_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('status', PaymentStatus::Completed->value)->get()
            ->groupBy('received_by_id');

        $sum = fn (Collection $rows, string $field) => $rows->reduce(fn (Money $c, $row) => $c->add($row->{$field}), Money::zero());

        return $this->roster->staff($branchId)->map(function (User $user) use ($orders, $payments, $sum) {
            $own = $orders->get($user->id, collect());
            $paid = $own->where('status', OrderStatus::Paid);
            $taken = $payments->get($user->id, collect());

            return [
                'user' => $user,
                'orders' => $paid->count(),
                'sales' => $sum($paid, 'total'),
                'average' => $paid->count() ? Money::of(intdiv($sum($paid, 'total')->amount, $paid->count())) : Money::zero(),
                'voids' => $own->where('status', OrderStatus::Void)->count(),
                'payments' => $taken->count(),
                'taken' => $sum($taken, 'amount'),
                'tips' => $sum($taken, 'tip'),
            ];
        })->filter(fn (array $row) => $row['orders'] > 0 || $row['voids'] > 0 || $row['payments'] > 0)
            ->sortByDesc(fn (array $row) => $row['sales']->amount)->values();
    }
}
