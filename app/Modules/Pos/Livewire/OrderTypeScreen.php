<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\TableService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Pos\Concerns\AsksForManagerPin;
use App\Modules\Pos\Services\TrainingMode;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/** Home screen of the till: Dine-in (floor plan), Takeaway, or resume an open order. */
#[Layout('pos::layouts.app', ['title' => 'New order'])]
class OrderTypeScreen extends Component
{
    use AsksForManagerPin;

    public string $mode = 'home'; // home | tables

    /**
     * Riders and kitchen staff may sign in at a till, but they belong on their own screens; anyone else
     * without orders.create (stock counters) may use the home screen but not start or resume an order (re-review).
     */
    public function mount(): void
    {
        $user = auth()->user();
        if ($user->can(Permission::OrdersCreate->value)) {
            return;
        }
        if ($user->can(Permission::DeliveryRide->value)) {
            $this->redirectRoute('pos.rider');
        } elseif ($user->can(Permission::KitchenView->value)) {
            $this->redirectRoute('kitchen.display');
        }
    }

    private function assertCanOrder(): void
    {
        abort_unless(auth()->user()->can(Permission::OrdersCreate->value), 403);
    }

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
        $this->assertCanOrder();
        $this->error = null;
        $this->delivery = ['name' => '', 'phone' => '', 'address' => '', 'notes' => '', 'fee' => Money::of(app(TillSettings::class)->deliveryFee())->toDecimal()];
    }

    public function closeDelivery(): void
    {
        $this->delivery = null;
    }

    public array $deliveryAddresses = [];

    public ?string $notice = null;

    /** Type the phone, tap Find → name and usual address fill in. */
    public function lookupDeliveryCustomer(): void
    {
        $this->error = null;
        $customer = app(CustomerService::class)->findByPhone($this->delivery['phone'] ?? '');
        if (! $customer) {
            $this->deliveryAddresses = [];
            $this->notice = __('customers::customers.pos.not_found');

            return;
        }
        $this->delivery['name'] = $customer->name;
        if ($address = $customer->defaultAddress()) {
            $this->delivery['address'] = $address->address;
            $this->delivery['notes'] = (string) $address->notes;
        }
        $this->deliveryAddresses = $customer->addresses()->get()->map(fn ($a) => ['address' => $a->address, 'notes' => (string) $a->notes, 'label' => $a->label()])->all();
        $this->notice = __('customers::customers.pos.found', ['name' => $customer->name]);
    }

    public function useDeliveryAddress(int $index): void
    {
        if (isset($this->deliveryAddresses[$index])) {
            $this->delivery['address'] = $this->deliveryAddresses[$index]['address'];
            $this->delivery['notes'] = $this->deliveryAddresses[$index]['notes'];
        }
    }

    public function openDelivery(TillSettings $settings, DeliveryService $delivery): void
    {
        $this->error = null;
        $fee = $this->deliveryFeeMoney();

        try {
            $delivery->validateDetails($this->delivery['phone'] ?? '', $this->delivery['address'] ?? '');
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // A fee other than the branch default is a price edit: manager PIN unless this user may edit prices (security review M4).
        if (! $fee->equals(Money::of($settings->deliveryFee())) && ! auth()->user()->can(Permission::OrdersPriceEdit->value)) {
            $this->askManager(Permission::OrdersPriceEdit, 'deliveryApproved', [$this->delivery], __('pos::pos.approval.delivery_fee', ['amount' => money($fee)]));

            return;
        }

        $this->deliveryApproved(null, $this->delivery);
    }

    /** Only reachable through approve() with a manager PIN, or from openDelivery() when no PIN is needed (protected). */
    protected function deliveryApproved(?User $manager, array $delivery): void
    {
        $this->assertCanOrder();
        $this->delivery = $delivery;
        $orders = app(OrderService::class);

        try {
            $order = $orders->open(OrderType::Delivery, auth()->user());
            app(DeliveryService::class)->setDetails($order, $delivery['name'], $delivery['phone'], $delivery['address'], $delivery['notes'], $this->deliveryFeeMoney(), auth()->user(), $manager);
        } catch (RuntimeException $e) {
            if (isset($order)) {
                $orders->void($order, 'Delivery details missing', auth()->user());
            }
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('pos.order', $order);
    }

    private function deliveryFeeMoney(): Money
    {
        $raw = trim((string) ($this->delivery['fee'] ?? ''));

        return Money::fromDecimal($raw === '' ? '0' : $raw);
    }

    /** Switch this device in or out of training mode (manager PIN). */
    public function toggleTraining(TrainingMode $training): void
    {
        $on = ! $training->isOn();
        $this->askManager(Permission::TrainingMode, 'trainingApproved', [$on], $on ? __('pos::pos.training.turn_on') : __('pos::pos.training.turn_off'));
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function trainingApproved(User $manager, bool $on): void
    {
        // Checked again on the server: only someone allowed to run training mode can switch it (security review H1).
        abort_unless($manager->can(Permission::TrainingMode->value), 403);

        if (! app(TrainingMode::class)->hasTill()) { // the state is kept on the till, so the device must have chosen one
            $this->error = __('pos::pos.training.no_till');

            return;
        }
        app(TrainingMode::class)->set($on);
        activity('training_mode')->causedBy(auth()->user())->withProperties(['on' => $on, 'approved_by' => $manager->id])->log($on ? 'training_on' : 'training_off');
        $this->redirectRoute('pos.home');
    }

    public function startTakeaway(OrderService $orders): void
    {
        $this->assertCanOrder();
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
        $this->assertCanOrder();
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
        $this->assertCanOrder();
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
        $this->assertCanOrder();
        $order = Order::query()->active()->forBranch()->findOrFail($orderId);
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
