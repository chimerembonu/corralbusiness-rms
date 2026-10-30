<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Home screen of the till: Dine-in (pick a table), Takeaway, or resume an open order. */
#[Layout('pos::layouts.app', ['title' => 'New order'])]
class OrderTypeScreen extends Component
{
    public string $mode = 'home'; // home | tables

    public ?string $error = null;

    public function showTables(): void
    {
        $this->mode = 'tables';
    }

    public function showHome(): void
    {
        $this->mode = 'home';
    }

    public function startTakeaway(OrderService $orders): void
    {
        $order = $orders->open(OrderType::Takeaway, auth()->user());

        $this->redirectRoute('pos.order', $order);
    }

    public function openTable(string $tableId, OrderService $orders): void
    {
        $table = DiningTable::query()->active()->with('currentOrder')->findOrFail($tableId);

        if ($table->currentOrder) {
            $this->redirectRoute('pos.order', $table->currentOrder);

            return;
        }

        $order = $orders->open(OrderType::DineIn, auth()->user(), $table, $table->seats);

        $this->redirectRoute('pos.order', $order);
    }

    public function resume(string $orderId): void
    {
        $order = Order::query()->active()->findOrFail($orderId);

        $this->redirectRoute('pos.order', $order);
    }

    public function render()
    {
        return view('pos::livewire.order-type-screen', [
            'tables' => DiningTable::query()->active()->ordered()->with('currentOrder')->get()->groupBy(fn (DiningTable $t) => $t->section ?? ''),
            'openOrders' => Order::query()->active()->forBranch()->with('table')->orderByDesc('opened_at')->limit(30)->get(),
        ]);
    }
}
