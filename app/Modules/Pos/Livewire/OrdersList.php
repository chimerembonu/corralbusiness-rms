<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Concerns\AsksForManagerPin;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/** Order history for the till: find an order by number or name, reprint its receipt, refund a payment. */
#[Layout('pos::layouts.app', ['title' => 'Orders'])]
class OrdersList extends Component
{
    use AsksForManagerPin;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    public ?string $openId = null;

    public ?string $notice = null;

    public ?string $error = null;

    /** Refund modal: ['payment_id' => …, 'reason' => …] */
    public ?array $refund = null;

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
            __('pos::pos.approval.refund', ['amount' => money($payment->amount), 'method' => $payment->methodLabel()]));
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function refundApproved(User $manager, string $paymentId, string $reason): void
    {
        // A cash refund must leave a drawer: the till has to be open (same rule as taking a payment).
        $payment = Payment::query()->forBranch()->findOrFail($paymentId);
        if (app(TillSettings::class)->requireSession($payment->branch_id) && ! app(CashService::class)->current(auth()->user(), $payment->branch_id)) {
            $this->error = __('payments::payments.cash.errors.no_session');

            return;
        }

        try {
            app(PaymentService::class)->refund(Payment::query()->forBranch()->findOrFail($paymentId), $reason, $manager, auth()->user());
            $this->notice = __('pos::pos.orders.refunded');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
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

        return view('pos::livewire.orders-list', [
            'orders' => $query->limit(100)->get(),
            'openOrder' => $this->openId ? Order::query()->forBranch()->with(['items.modifiers', 'payments.receivedBy', 'table'])->find($this->openId) : null,
            'canRefund' => auth()->user()->can(Permission::PaymentsTake->value),
        ]);
    }
}
