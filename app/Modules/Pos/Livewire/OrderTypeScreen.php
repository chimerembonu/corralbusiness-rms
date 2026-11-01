<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\TableService;
use App\Modules\Orders\Support\TillSettings;
use App\Support\Money\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/** Home screen of the till: Dine-in (floor plan), Takeaway, or resume an open order. */
#[Layout('pos::layouts.app', ['title' => 'New order'])]
class OrderTypeScreen extends Component
{
    public string $mode = 'home'; // home | tables

    public ?string $sectionId = null;

    public ?string $error = null;

    /** Table waiting for a guest count before its order opens. */
    public ?string $coversFor = null;

    public int $covers = 2;

    /** Delivery form (null = closed): name, phone, address, notes, fee (cedis). */
    public ?array $delivery = null;

    public function showTables(): void
    {
        $this->mode = 'tables';
        $this->error = null;
    }

    public function showHome(): void
    {
        $this->mode = 'home';
        $this->coversFor = null;
    }

    public function selectSection(?string $sectionId): void
    {
        $this->sectionId = $sectionId ?: null;
    }

    public function startDelivery(): void
    {
        $this->error = null;
        $this->delivery = ['name' => '', 'phone' => '', 'address' => '', 'notes' => '', 'fee' => Money::of(app(TillSettings::class)->deliveryFee())->toDecimal()];
    }

    public function closeDelivery(): void
    {
        $this->delivery = null;
    }

    public function openDelivery(OrderService $orders, DeliveryService $delivery): void
    {
        $this->error = null;

        try {
            $fee = Money::fromDecimal($this->delivery['fee'] === '' ? '0' : $this->delivery['fee']);
            $order = $orders->open(OrderType::Delivery, auth()->user());
            $delivery->setDetails($order, $this->delivery['name'], $this->delivery['phone'], $this->delivery['address'], $this->delivery['notes'], $fee, auth()->user());
        } catch (RuntimeException $e) {
            if (isset($order)) {
                $orders->void($order, 'Delivery details missing', auth()->user());
            }
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('pos.order', $order);
    }

    public function startTakeaway(OrderService $orders): void
    {
        try {
            $order = $orders->open(OrderType::Takeaway, auth()->user());
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('pos.order', $order);
    }

    /** Tap a table: resume its order, follow a merged table to its host, or ask how many guests. */
    public function tapTable(string $tableId): void
    {
        $this->error = null;
        $table = DiningTable::query()->active()->with(['currentOrder', 'mergedInto.currentOrder'])->findOrFail($tableId);

        if ($table->isMerged()) {
            $table = $table->mergedInto;
        }

        if ($table->currentOrder) {
            $this->redirectRoute('pos.order', $table->currentOrder);

            return;
        }

        $this->coversFor = $table->id;
        $this->covers = max(1, $table->seats);
    }

    public function setCovers(int $covers): void
    {
        $this->covers = max(1, min(99, $covers));
    }

    public function adjustCovers(int $delta): void
    {
        $this->setCovers($this->covers + $delta);
    }

    public function closeCovers(): void
    {
        $this->coversFor = null;
    }

    public function openTable(OrderService $orders): void
    {
        $table = DiningTable::query()->active()->with('currentOrder')->findOrFail($this->coversFor);

        try {
            $order = $orders->open(OrderType::DineIn, auth()->user(), $table, $this->covers);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            $this->coversFor = null;

            return;
        }

        $this->redirectRoute('pos.order', $order);
    }

    public function resume(string $orderId, OrderService $orders): void
    {
        $order = Order::query()->active()->findOrFail($orderId);
        $orders->recall($order, auth()->user());

        $this->redirectRoute('pos.order', $order);
    }

    public function render(TableService $tables)
    {
        $plan = $this->mode === 'tables' ? $tables->floorPlan() : collect();
        $current = $plan->first(fn (array $g) => ($g['section']?->id ?? '') === ($this->sectionId ?? '')) ?? $plan->first();

        return view('pos::livewire.order-type-screen', [
            'plan' => $plan,
            'current' => $current,
            'coversTable' => $this->coversFor ? DiningTable::query()->find($this->coversFor) : null,
            'openOrders' => $this->mode === 'home'
                ? Order::query()->active()->forBranch()->with('table')->orderByDesc('opened_at')->limit(30)->get()
                : collect(),
        ]);
    }
}
