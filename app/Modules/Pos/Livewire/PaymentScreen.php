<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Customers\Services\AccountService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Enums\Permission;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Payment: cash (keypad + change), Mobile Money (network + reference) and card (reference),
 * several payments per order, an optional tip, and "pay in N equal shares".
 */
#[Layout('pos::layouts.app', ['title' => 'Payment'])]
class PaymentScreen extends Component
{
    #[Locked]
    public string $orderId;

    public string $method = 'cash';

    /** Amount typed on the keypad, in cedis e.g. "150.00". For cash it is what was handed over. */
    public string $amount = '';

    public string $tip = '';

    public ?string $provider = null;

    public string $reference = '';

    /** Pay in N equal shares (from the split-bill sheet). 0 = off. */
    #[Url]
    public int $ways = 0;

    public ?string $error = null;

    public function mount(Order $order, CashService $cash, TillSettings $settings): void
    {
        abort_unless(auth()->user()->can(Permission::PaymentsTake->value), 403);
        Tenancy::assertOwns($order);
        $this->orderId = $order->id;

        if (! $order->isActive()) {
            $this->redirectRoute('pos.receipt', $order);

            return;
        }

        // The till must be opened (float counted) so every payment lands in a cashier session.
        if ($settings->requireSession($order->branch_id) && ! $cash->current(auth()->user(), $order->branch_id)) {
            session()->flash('error', __('payments::payments.cash.errors.no_session'));
            $this->redirectRoute('pos.cash', ['then' => $order->id]);
        }
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['items.modifiers', 'payments', 'table', 'customer'])->findOrFail($this->orderId);
    }

    #[Computed]
    public function tipsEnabled(): bool
    {
        return app(TillSettings::class)->tipsEnabled($this->order->branch_id);
    }

    /** Amount suggested for this payment: one share when splitting evenly, else the whole balance. */
    #[Computed]
    public function suggested(): Money
    {
        $due = $this->order->balanceDue();
        if ($this->ways > 1) {
            $shares = $due->split($this->ways);
            $paidCount = $this->order->payments->where('status', 'completed')->count();

            return $shares[min($paidCount, $this->ways - 1)];
        }

        return $due;
    }

    /** @return array<int, int> quick-pick amounts in pesewas: the suggested amount, then round notes above it */
    #[Computed]
    public function quickAmounts(): array
    {
        $due = $this->suggested->amount;
        $amounts = [$due];
        foreach ([1000, 2000, 5000, 10000, 20000, 50000] as $note) {
            $rounded = (int) (ceil($due / $note) * $note);
            if ($rounded > $due && ! in_array($rounded, $amounts, true)) {
                $amounts[] = $rounded;
            }
        }

        return array_slice($amounts, 0, 5);
    }

    public function setMethod(string $method): void
    {
        $this->method = $method;
        $this->error = null;
        $this->amount = $method === 'cash' ? '' : $this->suggested->toDecimal();
    }

    public function setAmount(int $pesewas): void
    {
        $this->amount = Money::of($pesewas)->toDecimal();
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function setTip(int $pesewas): void
    {
        $this->tip = $pesewas > 0 ? Money::of($pesewas)->toDecimal() : '';
    }

    public function complete(PaymentService $payments): void
    {
        $this->error = null;

        try {
            $amount = Money::fromDecimal($this->amount === '' ? '0' : $this->amount);
            $tip = $this->tipsEnabled && $this->tip !== '' ? Money::fromDecimal($this->tip) : Money::zero();
            $by = auth()->user();

            match ($this->method) {
                'momo' => $payments->payMobileMoney($this->order, MomoProvider::from($this->provider ?? ''), $amount, $this->reference, $by, $tip),
                'card' => $payments->payCard($this->order, $amount, $this->reference, $by, $tip),
                'account' => app(AccountService::class)->charge($this->order, $amount, $by),
                default => $payments->payCash($this->order, $amount, $by, $tip),
            };
        } catch (\ValueError) {
            $this->error = __('payments::payments.errors.provider_required');

            return;
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->order, $this->suggested, $this->quickAmounts);

        if ($this->order->isPaid()) {
            $this->redirectRoute('pos.receipt', [$this->order, 'print' => 1]);
        } else {
            $this->amount = $this->method === 'cash' ? '' : $this->suggested->toDecimal();
            $this->tip = '';
            $this->reference = '';
        }
    }

    public function render()
    {
        $methods = [PaymentMethod::Cash, PaymentMethod::MobileMoney, PaymentMethod::Card];
        if ($this->order->customer?->allow_account && $this->order->customer->is_active) {
            $methods[] = PaymentMethod::Account;
        }

        return view('pos::livewire.payment-screen', [
            'methods' => $methods,
            'providers' => MomoProvider::cases(),
        ]);
    }
}
