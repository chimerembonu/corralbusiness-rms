<?php

namespace App\Modules\Payments\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/** Takes payments against an order. Cash now; MoMo/card manual recording in Phase 1.2. */
class PaymentService
{
    public function __construct(private readonly OrderService $orders) {}

    /**
     * Cash: the customer hands over $tendered; the order is credited with min(tendered, balance)
     * and the rest is change.
     */
    public function payCash(Order $order, Money $tendered, ?User $by = null): Payment
    {
        $this->assertPayable($order);

        if (! $tendered->isPositive()) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }

        $due = $order->balanceDue();
        $applied = $tendered->greaterThan($due) ? $due : $tendered;
        $change = $tendered->subtract($applied);

        return $this->record($order, PaymentMethod::Cash, $applied, $by, tendered: $tendered, change: $change);
    }

    /** Generic entry point (used for MoMo/card manual recording later). */
    public function record(Order $order, PaymentMethod $method, Money $amount, ?User $by = null, ?string $reference = null, ?Money $tendered = null, ?Money $change = null, ?string $notes = null): Payment
    {
        $this->assertPayable($order);

        if (! $amount->isPositive()) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }

        if ($amount->greaterThan($order->balanceDue())) {
            throw new PaymentException(__('payments::payments.errors.over_balance'));
        }

        return DB::transaction(function () use ($order, $method, $amount, $by, $reference, $tendered, $change, $notes) {
            $payment = new Payment([
                'order_id' => $order->id,
                'method' => $method,
                'amount' => $amount,
                'tendered' => $tendered,
                'change_given' => $change ?? Money::zero(),
                'reference' => $reference,
                'received_by_id' => $by?->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);
            $payment->branch_id = $order->branch_id;
            $payment->save();

            $paidTotal = $order->payments()->where('status', 'completed')->get()
                ->reduce(fn (Money $c, Payment $p) => $c->add($p->amount), Money::zero());

            $this->orders->markPaid($order, $paidTotal, $by);

            PaymentRecorded::dispatch($payment);

            return $payment;
        });
    }

    private function assertPayable(Order $order): void
    {
        if (! $order->isActive()) {
            throw new PaymentException(__('payments::payments.errors.order_not_payable'));
        }

        if ($order->total->isZero()) {
            throw new PaymentException(__('payments::payments.errors.nothing_to_pay'));
        }
    }
}
