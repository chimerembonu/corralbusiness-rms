<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Services\PaymentService;
use App\Support\Money\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/** Cash payment with an on-screen keypad and change calculation. */
#[Layout('pos::layouts.app', ['title' => 'Payment'])]
class PaymentScreen extends Component
{
    #[Locked]
    public string $orderId;

    /** Amount handed over, typed on the keypad, in cedis e.g. "150.00". */
    public string $tendered = '';

    public ?string $error = null;

    public function mount(Order $order): void
    {
        $this->orderId = $order->id;

        if (! $order->isActive()) {
            $this->redirectRoute('pos.receipt', $order);
        }
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['items.modifiers', 'payments'])->findOrFail($this->orderId);
    }

    /** @return array<int, int> quick-pick amounts in pesewas: exact, then round notes above it */
    #[Computed]
    public function quickAmounts(): array
    {
        $due = $this->order->balanceDue()->amount;
        $amounts = [$due];
        foreach ([1000, 2000, 5000, 10000, 20000, 50000] as $note) {
            $rounded = (int) (ceil($due / $note) * $note);
            if ($rounded > $due && ! in_array($rounded, $amounts, true)) {
                $amounts[] = $rounded;
            }
        }

        return array_slice($amounts, 0, 5);
    }

    public function setTendered(int $pesewas): void
    {
        $this->tendered = Money::of($pesewas)->toDecimal();
    }

    public function complete(PaymentService $payments): void
    {
        $this->error = null;

        try {
            $tendered = Money::fromDecimal($this->tendered === '' ? '0' : $this->tendered);
            $payments->payCash($this->order, $tendered, auth()->user());
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        unset($this->order);

        if ($this->order->isPaid()) {
            $this->redirectRoute('pos.receipt', [$this->order, 'print' => 1]);
        } else {
            $this->tendered = '';
        }
    }

    public function render()
    {
        return view('pos::livewire.payment-screen');
    }
}
