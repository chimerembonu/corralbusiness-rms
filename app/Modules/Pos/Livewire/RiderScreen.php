<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Staff\Enums\Permission;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/** The rider's phone screen: their deliveries with big Out / Delivered / Problem buttons. */
#[Layout('pos::layouts.app', ['title' => 'My deliveries'])]
class RiderScreen extends Component
{
    public ?string $problemFor = null;

    public string $problemReason = '';

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can(Permission::DeliveryRide->value), 403);
    }

    public function markOut(string $orderId, DeliveryService $delivery): void
    {
        $this->run(fn () => $delivery->markOut($this->mine($orderId), auth()->user()));
    }

    public function markDelivered(string $orderId, DeliveryService $delivery): void
    {
        $this->run(fn () => $delivery->markDelivered($this->mine($orderId), auth()->user()));
    }

    public function startProblem(?string $orderId): void
    {
        $this->problemFor = $orderId;
        $this->problemReason = '';
    }

    public function confirmProblem(DeliveryService $delivery): void
    {
        $this->run(function () use ($delivery) {
            $delivery->markFailed($this->mine($this->problemFor), $this->problemReason ?: __('pos::pos.delivery.problem'), auth()->user());
            $this->problemFor = null;
        });
    }

    private function mine(string $orderId): Order
    {
        return Order::query()->where('rider_id', auth()->id())->findOrFail($orderId);
    }

    private function run(callable $callback): void
    {
        $this->error = null;
        try {
            $callback();
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(DeliveryService $delivery)
    {
        return view('pos::livewire.rider-screen', ['orders' => $delivery->forRider(auth()->user())]);
    }
}
