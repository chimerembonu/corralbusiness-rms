<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Gateways\GatewayRefundService;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Concerns\AsksForManagerPin;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/** Order history for the till: find an order by number or name, reprint its receipt, refund a payment. */
#[Layout('pos::layouts.app', ['title' => 'Orders'])]
class OrdersList extends Component
{
    use AsksForManagerPin, ShowsFriendlyErrors;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?string $openId = null;

    public ?string $notice = null;

    public ?string $error = null;

    /** Refund modal: ['payment_id' => …, 'reason' => …] */
    public ?array $refund = null;

    /** "How was it given back?" notes for refunds done by hand, by refund id (E2.4). */
    public array $handNotes = [];

    /** Every order of the branch with customer details: for order-takers and cashiers, not riders or kitchen staff (re-review N2). */
    public function mount(): void
    {
        abort_unless(auth()->user()->canAny([Permission::OrdersCreate->value, Permission::PaymentsTake->value]), 403);
    }

    public function open(?string $orderId): void
    {
        $this->openId = $orderId;
        $this->notice = null;
        $this->error = null;
        $this->refund = null;
    }

    public function reprint(string $orderId, PrintService $printing): void
    {
        $order = Order::query()->forBranch()->findOrFail($orderId);
        $jobs = $printing->printReceipt($order, reprint: true);
        $this->notice = $jobs->isEmpty() ? __('pos::pos.orders.no_printer') : __('pos::pos.orders.reprinted', ['count' => $jobs->count()]);
    }

    public function startRefund(string $paymentId): void
    {
        $this->refund = ['payment_id' => $paymentId, 'reason' => ''];
        $this->error = null;
    }

    public function cancelRefund(): void
    {
        $this->refund = null;
    }

    public function confirmRefund(): void
    {
        if (blank($this->refund['reason'] ?? '')) {
            $this->error = __('orders::orders.errors.reason_required');

            return;
        }

        $payment = Payment::query()->forBranch()->findOrFail($this->refund['payment_id']);
        $refund = $this->refund;
        $this->refund = null;
        $this->askManager(Permission::OrdersRefund, 'refundApproved', [$refund['payment_id'], $refund['reason']],
            __('pos::pos.approval.refund', ['amount' => money($payment->amount), 'method' => $payment->methodLabel()]),
            second: app(PaymentService::class)->needsSecondApproval($payment));
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function refundApproved(User $manager, string $paymentId, string $reason, ?User $second = null): void
    {
        // A cash refund must leave a drawer: the till has to be open (same rule as taking a payment).
        $payment = Payment::query()->forBranch()->findOrFail($paymentId);
        if (app(TillSettings::class)->requireSession($payment->branch_id) && ! app(CashService::class)->current(auth()->user(), $payment->branch_id)) {
            $this->error = __('payments::payments.cash.errors.no_session');

            return;
        }

        try {
            app(PaymentService::class)->refund(Payment::query()->forBranch()->findOrFail($paymentId), $reason, $manager, auth()->user(), $second);
            $this->notice = $this->refundNotice($paymentId);
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    /** A refund the provider could not send back: a manager confirms the customer got the money another way. */
    public function confirmDoneByHand(string $refundId, string $method = 'momo'): void
    {
        $refund = GatewayRefund::query()->forBranch()->findOrFail($refundId);
        $method = $method === 'cash' ? 'cash' : 'momo';
        $this->askManager(Permission::OrdersRefund, 'doneByHandApproved', [$refundId, (string) ($this->handNotes[$refundId] ?? ''), $method],
            __('payments::payments.refunds.till.approval', ['amount' => money($refund->amount)]));
    }

    /** Only reachable through approve() with a manager PIN. Cash comes out of this till's drawer (E9.6 review F5). */
    protected function doneByHandApproved(User $manager, string $refundId, string $note, string $method = 'momo'): void
    {
        try {
            app(GatewayRefundService::class)->markDoneByHand(GatewayRefund::query()->forBranch()->findOrFail($refundId), $manager, $note, $method, auth()->user());
            unset($this->handNotes[$refundId]);
            $this->notice = __('payments::payments.refunds.till.done');
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    /** What the cashier sees after a refund: plain, or where the money is going for a provider payment. */
    private function refundNotice(string $paymentId): string
    {
        $refund = GatewayRefund::query()->forBranch()->with('transaction')->where('payment_id', $paymentId)->first();
        if (! $refund) {
            return __('pos::pos.orders.refunded');
        }
        $replace = ['amount' => money($refund->amount), 'phone' => $refund->transaction?->phone_masked ?? '—', 'provider' => __('payments::payments.gateway.names.'.$refund->gateway)];

        return $refund->status->needsHand() ? __('payments::payments.refunds.till.by_hand', $replace) : __('payments::payments.refunds.till.sent', $replace);
    }

    public function render()
    {
        $query = Order::query()->forBranch()->with(['table', 'payments', 'items'])->orderByDesc('opened_at');

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }
        if (trim($this->search) !== '') {
            $term = trim($this->search);
            $query->where(function ($q) use ($term) {
                $q->where('customer_name', 'like', "%{$term}%")->orWhere('customer_phone', 'like', "%{$term}%");
                if (ctype_digit($term)) {
                    $q->orWhere('number', (int) $term);
                }
            });
        } else {
            $query->forBusinessDate(now()->toDateString());
        }

        $openOrder = $this->openId ? Order::query()->forBranch()->with(['items.modifiers', 'payments.receivedBy', 'table'])->find($this->openId) : null;

        return view('pos::livewire.orders-list', [
            'orders' => $query->limit(100)->get(),
            'openOrder' => $openOrder,
            'providerRefunds' => $openOrder ? GatewayRefund::query()->forBranch()->whereIn('payment_id', $openOrder->payments->pluck('id'))->get()->keyBy('payment_id') : collect(),
            'canRefund' => auth()->user()->can(Permission::PaymentsTake->value),
        ]);
    }
}
