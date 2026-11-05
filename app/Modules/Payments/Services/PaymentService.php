<?php

namespace App\Modules\Payments\Services;

use App\Modules\Customers\Services\AccountService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Payments\Events\PaymentRefunded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\SecondApproval;
use App\Support\Money\Money;
use App\Support\Security\CardNumberGuard;
use Illuminate\Support\Facades\DB;

/**
 * Takes payments against an order: cash, Mobile Money and card (recorded by hand with a reference),
 * several payments per order, tips, and refunds with a manager PIN.
 */
class PaymentService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CashService $cash,
        private readonly DayCloseService $days,
    ) {}

    /**
     * Cash: the customer hands over $tendered; the order is credited with min(tendered − tip, balance)
     * and the rest is change.
     */
    public function payCash(Order $order, Money $tendered, ?User $by = null, ?Money $tip = null): Payment
    {
        $this->assertPayable($order);
        $tip ??= Money::zero();

        if ($tip->isNegative() || ! $tendered->isPositive() || $tendered->lessThan($tip)) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }

        $due = $order->balanceDue();
        $available = $tendered->subtract($tip);
        $applied = $available->greaterThan($due) ? $due : $available;
        $change = $available->subtract($applied);

        return $this->record($order, PaymentMethod::Cash, $applied, $by, tendered: $tendered, change: $change, tip: $tip);
    }

    /** Mobile Money recorded by hand: network + the transaction id from the customer's phone. */
    public function payMobileMoney(Order $order, MomoProvider $provider, Money $amount, string $reference, ?User $by = null, ?Money $tip = null): Payment
    {
        if (blank($reference)) {
            throw new PaymentException(__('payments::payments.errors.reference_required'));
        }

        return $this->record($order, PaymentMethod::MobileMoney, $amount, $by, reference: trim($reference), provider: $provider, tip: $tip);
    }

    /** Never store a full card number: card references hold the slip number or last 4 digits only; notes may not contain a card number (PCI DSS, compliance assessment). */
    public static function assertNoCardNumber(PaymentMethod $method, ?string $reference, ?string $notes = null): void
    {
        // Mobile Money transaction ids can be long numbers, so only card and other references are checked.
        $referenceUnsafe = match ($method) {
            PaymentMethod::Card => ! CardNumberGuard::isSafeCardReference($reference),
            PaymentMethod::MobileMoney => false,
            default => CardNumberGuard::containsCardNumber($reference),
        };
        if ($referenceUnsafe || CardNumberGuard::containsCardNumber($notes)) {
            throw new PaymentException(__('payments::payments.errors.card_number'));
        }
    }

    /** Card paid on a standalone terminal: optional slip / last-4 reference. */
    public function payCard(Order $order, Money $amount, ?string $reference = null, ?User $by = null, ?Money $tip = null): Payment
    {
        return $this->record($order, PaymentMethod::Card, $amount, $by, reference: filled($reference) ? trim($reference) : null, tip: $tip);
    }

    /** Generic entry point. $amount is what is applied to the order; the tip is on top. */
    public function record(Order $order, PaymentMethod $method, Money $amount, ?User $by = null, ?string $reference = null, ?Money $tendered = null, ?Money $change = null, ?string $notes = null, ?MomoProvider $provider = null, ?Money $tip = null): Payment
    {
        $this->assertPayable($order);
        $tip ??= Money::zero();

        if ($amount->isNegative() || $tip->isNegative() || (! $amount->isPositive() && ! $tip->isPositive())) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }
        if ($amount->greaterThan($order->balanceDue())) {
            throw new PaymentException(__('payments::payments.errors.over_balance'));
        }
        if ($method === PaymentMethod::MobileMoney && $provider === null) {
            throw new PaymentException(__('payments::payments.errors.provider_required'));
        }
        self::assertNoCardNumber($method, $reference, $notes);

        $this->days->assertOpen($order->business_date->toDateString(), $order->branch_id);
        $session = $this->cash->current($by, $order->branch_id);

        return DB::transaction(function () use ($order, $method, $amount, $by, $reference, $tendered, $change, $notes, $provider, $tip, $session) {
            // Lock the order row and read it again, so a double tap on Pay (or a second till on the same
            // order) sees the first payment and is refused instead of paying twice (security review M2).
            Order::query()->whereKey($order->id)->lockForUpdate()->first();
            $order->refresh();
            $this->assertPayable($order);
            if ($amount->greaterThan($order->balanceDue())) {
                throw new PaymentException(__('payments::payments.errors.over_balance'));
            }

            $payment = new Payment([
                'order_id' => $order->id,
                'cashier_session_id' => $session?->id,
                'is_training' => (bool) $order->is_training,
                'method' => $method,
                'provider' => $method === PaymentMethod::MobileMoney ? $provider : null,
                'amount' => $amount,
                'tendered' => $tendered,
                'change_given' => $change ?? Money::zero(),
                'tip' => $tip,
                'reference' => $reference,
                'received_by_id' => $by?->id,
                'received_at' => now(),
                'notes' => $notes,
            ]);
            $payment->branch_id = $order->branch_id;
            $payment->save();

            $this->syncTotals($order);
            $this->orders->markPaid($order, $order->paid_total, $by);
            if ($session) {
                $this->cash->recalculate($session);
            }

            PaymentRecorded::dispatch($payment);

            return $payment;
        });
    }

    /** Do Till settings ask a second manager to confirm this refund (the order's refunds would pass the limit)? (E9.4) */
    public function needsSecondApproval(Payment $payment): bool
    {
        $limit = app(TillSettings::class)->secondApprovalRefundAbove($payment->branch_id);
        if ($limit === 0) {
            return false;
        }
        $order = $payment->order()->first();

        return (int) $order?->refunded_total?->amount + $payment->amount->amount > $limit;
    }

    /**
     * Refund a whole payment. $approvedBy must hold the refund permission (manager PIN); a big refund also
     * needs $secondApprover, a different manager, when Till settings ask for one (E9.4).
     */
    public function refund(Payment $payment, string $reason, User $approvedBy, ?User $by = null, ?User $secondApprover = null): Payment
    {
        if ($payment->isRefunded()) {
            throw new PaymentException(__('payments::payments.errors.already_refunded'));
        }
        if (! $approvedBy->can(Permission::OrdersRefund->value)) {
            throw new ManagerPinRequiredException(Permission::OrdersRefund->value);
        }
        if (blank($reason)) {
            throw new PaymentException(__('orders::orders.errors.reason_required'));
        }
        $second = $this->needsSecondApproval($payment);
        if ($second) {
            SecondApproval::assert(Permission::OrdersRefund, $approvedBy, $secondApprover);
        }

        // The refund is posted to today's business day, which must still be open: a closed day's Z report
        // never changes afterwards (security review L3).
        $businessDate = $this->orders->businessDate();
        $this->days->assertOpen($businessDate, $payment->branch_id);
        $session = $this->cash->current($by ?? $approvedBy, $payment->branch_id);

        return DB::transaction(function () use ($payment, $reason, $approvedBy, $session, $businessDate, $second, $secondApprover) {
            // Lock the payment row and read it again: a double tap on Refund (or a stale copy on a second
            // till) must not refund twice (re-review N5).
            Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            $payment->refresh();
            if ($payment->isRefunded()) {
                throw new PaymentException(__('payments::payments.errors.already_refunded'));
            }

            $payment->forceFill([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => now(),
                'refund_business_date' => $businessDate,
                'refund_reason' => trim($reason),
                'refunded_by_id' => $approvedBy->id,
                'refund_session_id' => $session?->id,
                'refund_second_approved_by_id' => $second ? $secondApprover->id : null,
            ])->save();
            if ($second) {
                activity('payments')->performedOn($payment)->causedBy($secondApprover)
                    ->withProperties(['first_approver_id' => $approvedBy->id, 'amount' => $payment->amount->amount])->log('refund_second_approval');
            }
            if ($session) {
                $this->cash->recalculate($session);
            }

            $order = $payment->order()->first();
            $this->syncTotals($order);
            $order->refunded_total = $order->refunded_total->add($payment->amount);

            if ($order->status === OrderStatus::Paid && $order->paid_total->isZero()) {
                $order->status = OrderStatus::Refunded;
                $order->refund_business_date = $businessDate;
            }
            $order->save();

            if ($payment->method === PaymentMethod::Account && $payment->customer_id) {
                app(AccountService::class)->reverseCharge($payment, $approvedBy, $reason); // the customer no longer owes it
            }

            PaymentRefunded::dispatch($payment);
            if ($order->status === OrderStatus::Refunded) {
                OrderRefunded::dispatch($order, $approvedBy->id);
            }

            return $payment;
        });
    }

    /** Recompute paid and tip totals from the completed payments. */
    private function syncTotals(Order $order): void
    {
        $completed = $order->payments()->where('status', PaymentStatus::Completed->value)->get();
        $order->paid_total = $completed->reduce(fn (Money $c, Payment $p) => $c->add($p->amount), Money::zero());
        $order->tip_total = $completed->reduce(fn (Money $c, Payment $p) => $c->add($p->tip), Money::zero());
        $order->save();
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
