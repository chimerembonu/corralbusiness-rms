<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Enums\AccountTransactionType;
use App\Modules\Customers\Events\AccountCharged;
use App\Modules\Customers\Events\AccountPaymentReceived;
use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Models\AccountTransaction;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Events\PaymentRecorded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Buying on account and paying the account off later. */
class AccountService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CashService $cash,
        private readonly TenantContext $context,
    ) {}

    /** Put (part of) an order on the customer's account. The order is marked paid by an "On account" payment. */
    public function charge(Order $order, ?Money $amount = null, ?User $by = null): Payment
    {
        $customer = $order->customer_id ? Customer::query()->withoutGlobalScopes()->lockForUpdate()->find($order->customer_id) : null;
        if (! $customer) {
            throw new CustomerException(__('customers::customers.errors.no_customer'));
        }
        if (! $customer->allow_account || ! $customer->is_active) {
            throw new CustomerException(__('customers::customers.errors.account_not_allowed', ['name' => $customer->name]));
        }
        if ($order->is_training) { // a practice order must not change what a real customer owes
            throw new CustomerException(__('customers::customers.errors.training'));
        }
        $amount ??= $order->balanceDue();
        if (! $amount->isPositive()) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }
        $available = $customer->creditAvailable();
        if ($available && $amount->greaterThan($available)) {
            throw new CustomerException(__('customers::customers.errors.over_limit', ['name' => $customer->name, 'available' => money($available), 'limit' => money($customer->credit_limit)]));
        }

        return DB::transaction(function () use ($order, $amount, $by, $customer) {
            $payment = $this->payments->record($order, PaymentMethod::Account, $amount, $by, notes: __('customers::customers.account.charge_note', ['name' => $customer->name]));
            $payment->forceFill(['customer_id' => $customer->id])->save();
            $transaction = $this->post($customer, AccountTransactionType::Charge, $amount->amount, $by, order: $order, payment: $payment, notes: __('customers::customers.account.order_note', ['number' => $order->displayNumber()]));

            AccountCharged::dispatch($transaction, $by?->id);

            return $payment;
        });
    }

    /**
     * Money received against the account (cash, MoMo or card). It is a Payment without an order,
     * so it lands in the cashier's drawer / session like any other takings.
     */
    public function receivePayment(Customer $customer, PaymentMethod $method, Money $amount, ?User $by = null, ?string $reference = null, ?MomoProvider $provider = null, ?string $notes = null): Payment
    {
        if (! $amount->isPositive()) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }
        if ($method === PaymentMethod::Account) {
            throw new CustomerException(__('customers::customers.errors.bad_method'));
        }
        if ($method === PaymentMethod::MobileMoney && ($provider === null || blank($reference))) {
            throw new PaymentException(__('payments::payments.errors.reference_required'));
        }

        return DB::transaction(function () use ($customer, $method, $amount, $by, $reference, $provider, $notes) {
            $session = $this->cash->current($by, $this->context->branchId());
            $payment = new Payment([
                'order_id' => null,
                'customer_id' => $customer->id,
                'cashier_session_id' => $session?->id,
                'method' => $method,
                'provider' => $method === PaymentMethod::MobileMoney ? $provider : null,
                'amount' => $amount,
                'tendered' => $method === PaymentMethod::Cash ? $amount : null,
                'reference' => filled($reference) ? trim($reference) : null,
                'received_by_id' => $by?->id,
                'received_at' => now(),
                'notes' => $notes ?? __('customers::customers.account.payment_note', ['name' => $customer->name]),
            ]);
            $payment->branch_id = $this->context->branchId();
            $payment->save();
            if ($session) {
                $this->cash->recalculate($session);
            }

            $transaction = $this->post($customer, AccountTransactionType::Payment, -$amount->amount, $by, payment: $payment, reference: $reference, notes: $notes);

            PaymentRecorded::dispatch($payment);
            AccountPaymentReceived::dispatch($transaction, $by?->id);

            return $payment;
        });
    }

    /** An on-account payment was refunded: the charge comes off the customer's account again. */
    public function reverseCharge(Payment $payment, ?User $by = null, ?string $reason = null): AccountTransaction
    {
        $customer = Customer::query()->withoutGlobalScopes()->findOrFail($payment->customer_id);
        $order = $payment->order()->withoutGlobalScopes()->first();

        return $this->post($customer, AccountTransactionType::Adjustment, -$payment->amount->amount, $by, order: $order, payment: $payment,
            notes: __('customers::customers.account.refund_note', ['number' => $order?->displayNumber() ?? '', 'reason' => $reason ?? '']));
    }

    /** Manager correction / write-off: positive raises what they owe, negative lowers it. */
    public function adjust(Customer $customer, Money $amount, User $by, string $reason): AccountTransaction
    {
        if (! $by->can(Permission::OrdersRefund->value)) {
            throw new ManagerPinRequiredException(Permission::OrdersRefund->value);
        }
        if ($amount->isZero()) {
            throw new PaymentException(__('payments::payments.errors.amount_required'));
        }
        if (blank($reason)) {
            throw new CustomerException(__('orders::orders.errors.reason_required'));
        }

        return $this->post($customer, AccountTransactionType::Adjustment, $amount->amount, $by, notes: trim($reason));
    }

    /** @return Collection<int, AccountTransaction> oldest first, for a statement */
    public function statement(Customer $customer, ?string $from = null, ?string $to = null): Collection
    {
        return $customer->accountTransactions()->reorder('happened_at')->with(['order', 'payment', 'user'])
            ->when($from, fn ($q) => $q->where('happened_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('happened_at', '<=', $to.' 23:59:59'))
            ->get();
    }

    /** @return Collection<int, Customer> everyone who owes money, biggest first */
    public function debtors(): Collection
    {
        return Customer::query()->where('account_balance', '>', 0)->orderByDesc('account_balance')->get();
    }

    private function post(Customer $customer, AccountTransactionType $type, int $amount, ?User $by, ?Order $order = null, ?Payment $payment = null, ?string $reference = null, ?string $notes = null): AccountTransaction
    {
        return DB::transaction(function () use ($customer, $type, $amount, $by, $order, $payment, $reference, $notes) {
            $customer = Customer::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($customer->id);
            $customer->account_balance += $amount;
            $customer->saveQuietly();

            $transaction = new AccountTransaction([
                'customer_id' => $customer->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $customer->account_balance,
                'order_id' => $order?->id,
                'payment_id' => $payment?->id,
                'reference' => filled($reference) ? trim($reference) : null,
                'notes' => $notes,
                'user_id' => $by?->id,
                'happened_at' => now(),
            ]);
            $transaction->branch_id = $order?->branch_id ?? $payment?->branch_id ?? $this->context->branchId();
            $transaction->save();

            return $transaction;
        });
    }
}
