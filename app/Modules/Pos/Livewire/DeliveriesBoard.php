<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/** Deliveries board for the counter: who is preparing, who is out, assign riders. */
#[Layout('pos::layouts.app', ['title' => 'Deliveries'])]
class DeliveriesBoard extends Component
{
    use ShowsFriendlyErrors;

    public ?string $assigning = null;

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::OrdersCreate->value), 403);
    }

    public function startAssign(?string $orderId): void
    {
        $this->assigning = $orderId;
        $this->error = null;
    }

    public function assign(string $orderId, string $riderId, DeliveryService $delivery): void
    {
        $this->run(function () use ($orderId, $riderId, $delivery) {
            $order = Order::query()->forBranch()->findOrFail($orderId);
            // A rider of this branch (or one who works for the whole restaurant): security review N6.
            $rider = User::query()->where(fn ($q) => $q->where('branch_id', $order->branch_id)->orWhereNull('branch_id'))->findOrFail($riderId);
            $delivery->assignRider($order, $rider, auth()->user());
            $this->assigning = null;
            $this->notice = __('pos::pos.delivery.assigned', ['name' => $rider->name]);
        });
    }

    public function markOut(string $orderId, DeliveryService $delivery): void
    {
        $this->run(fn () => $delivery->markOut(Order::query()->forBranch()->findOrFail($orderId), auth()->user()));
    }

    public function markDelivered(string $orderId, DeliveryService $delivery): void
    {
        $this->run(fn () => $delivery->markDelivered(Order::query()->forBranch()->findOrFail($orderId), auth()->user()));
    }

    public function reopen(string $orderId, DeliveryService $delivery): void
    {
        $this->run(fn () => $delivery->reopen(Order::query()->forBranch()->findOrFail($orderId), auth()->user()));
    }

    private function run(callable $callback): void
    {
        $this->error = null;
        $this->notice = null;
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    public function render(DeliveryService $delivery)
    {
        return view('pos::livewire.deliveries-board', [
            'orders' => $delivery->board(),
            'riders' => $delivery->riders(),
        ]);
    }
}
