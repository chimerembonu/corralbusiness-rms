<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Models\ModifierGroup;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\DeliveryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\TableService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Pos\Concerns\AsksForManagerPin;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Arr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/** The main order-taking screen: order panel on the left, category tabs + item grid on the right. */
#[Layout('pos::layouts.app', ['title' => 'Order'])]
class OrderScreen extends Component
{
    use AsksForManagerPin, ShowsFriendlyErrors;

    #[Locked]
    public string $orderId;

    public ?string $categoryId = null;

    public ?string $error = null;

    public ?string $notice = null;

    /** Item picker (sizes + modifiers) state, null when closed. */
    public ?array $picker = null;

    /** Line whose note is being edited. */
    public ?string $noteLineId = null;

    public string $noteText = '';

    /** Table sheet: null (closed) | menu | move | merge | covers. */
    public ?string $tableSheet = null;

    public ?string $sheetSectionId = null;

    public int $covers = 1;

    /** Which modal is open: null | actions | void | discount | promo | note | split | hold. */
    public ?string $modal = null;

    /** Void modal: ['line_id' => ?string, 'reason' => string] (line_id null = whole order). */
    public array $void = ['line_id' => null, 'reason' => ''];

    /** Discount modal: ['line_id' => ?string, 'type' => percent|fixed, 'value' => "10", 'reason' => string]. */
    public array $discount = ['line_id' => null, 'type' => 'percent', 'value' => '', 'reason' => ''];

    public string $promoCode = '';

    public string $orderNote = '';

    /** Split modal: ['mode' => items|seats|even, 'lines' => [id…], 'seats' => [n…], 'ways' => 2]. */
    public array $split = ['mode' => 'items', 'lines' => [], 'seats' => [], 'ways' => 2];

    public string $holdName = '';

    /** Customer modal: search box and the "new customer" mini form. */
    public string $customerSearch = '';

    public array $newCustomer = ['name' => '', 'phone' => ''];

    /** Saved addresses of the customer found by phone on the delivery form. */
    public array $deliveryAddresses = [];

    /** Delivery details modal (null = closed). */
    public ?array $delivery = null;

    public function mount(Order $order): void
    {
        abort_unless(auth()->user()->can(Permission::OrdersCreate->value), 403); // riders / kitchen staff cannot edit orders
        Tenancy::assertOwns($order);
        $this->orderId = $order->id;

        if (! $order->isActive()) {
            $this->redirectRoute('pos.receipt', $order);
        }
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['items.modifiers', 'table.mergedTables', 'customer'])->findOrFail($this->orderId);
    }

    #[Computed]
    public function catalog()
    {
        return app(MenuService::class)->catalogFor($this->order->branch_id);
    }

    #[Computed]
    public function currentCategory()
    {
        $catalog = $this->catalog;

        return $catalog->firstWhere('id', $this->categoryId) ?? $catalog->first();
    }

    public function selectCategory(string $categoryId): void
    {
        $this->categoryId = $categoryId;
    }

    // ---- Adding items --------------------------------------------------------

    public function tapItem(string $itemId): void
    {
        $this->error = null;
        $item = Item::query()->with(['variants' => fn ($q) => $q->active(), 'modifierGroups.modifiers'])->findOrFail($itemId);
        $groups = $item->modifierGroups->where('is_active', true);

        if ($item->variants->isEmpty() && $groups->isEmpty()) {
            $this->addLine($item, null, 1, [], null);

            return;
        }

        $default = $item->variants->firstWhere('is_default', true) ?? $item->variants->first();
        $selections = [];
        foreach ($groups as $group) {
            $selections[$group->id] = [];
            foreach ($group->modifiers->where('is_active', true)->where('is_sold_out', false) as $modifier) {
                if ($modifier->is_default && $group->min_select > 0) {
                    $selections[$group->id][$modifier->id] = 1;
                    break;
                }
            }
        }

        $this->picker = [
            'item_id' => $item->id,
            'name' => $item->name,
            'variant_id' => $default?->id,
            'quantity' => 1,
            'selections' => $selections,
            'notes' => '',
            'errors' => [],
        ];
    }

    public function pickerVariant(string $variantId): void
    {
        $this->picker['variant_id'] = $variantId;
    }

    public function pickerQuantity(int $delta): void
    {
        $this->picker['quantity'] = max(1, $this->picker['quantity'] + $delta);
    }

    /** Tap a modifier: single-choice groups swap, multi-choice groups toggle (or count up when quantity is allowed). */
    public function pickerToggle(string $groupId, string $modifierId): void
    {
        $group = ModifierGroup::query()->findOrFail($groupId);
        $current = $this->picker['selections'][$groupId] ?? [];

        if ($group->max_select === 1) {
            $this->picker['selections'][$groupId] = isset($current[$modifierId]) && $group->min_select === 0 ? [] : [$modifierId => 1];

            return;
        }

        if (isset($current[$modifierId])) {
            if ($group->allow_quantity) {
                $current[$modifierId]++;
            } else {
                unset($current[$modifierId]);
            }
        } else {
            $current[$modifierId] = 1;
        }

        $this->picker['selections'][$groupId] = $current;
    }

    public function pickerDecrease(string $groupId, string $modifierId): void
    {
        $current = $this->picker['selections'][$groupId] ?? [];
        if (isset($current[$modifierId])) {
            $current[$modifierId]--;
            if ($current[$modifierId] <= 0) {
                unset($current[$modifierId]);
            }
        }
        $this->picker['selections'][$groupId] = $current;
    }

    public function pickerAdd(): void
    {
        $item = Item::query()->findOrFail($this->picker['item_id']);
        $variant = $this->picker['variant_id'] ? ItemVariant::query()->find($this->picker['variant_id']) : null;
        $modifiers = [];
        foreach ($this->picker['selections'] as $chosen) {
            foreach ($chosen as $modifierId => $qty) {
                $modifiers[$modifierId] = ($modifiers[$modifierId] ?? 0) + (int) $qty;
            }
        }

        if ($this->addLine($item, $variant, (int) $this->picker['quantity'], $modifiers, $this->picker['notes'] ?: null)) {
            $this->picker = null;
        }
    }

    public function pickerClose(): void
    {
        $this->picker = null;
    }

    private function addLine(Item $item, ?ItemVariant $variant, int $quantity, array $modifiers, ?string $notes): bool
    {
        try {
            app(OrderService::class)->addItem($this->order, $item, $variant, $quantity, $modifiers, $notes, auth()->user());
            unset($this->order);

            return true;
        } catch (RuntimeException $e) {
            if ($this->picker) {
                $this->picker['errors'] = [FriendlyError::message($e)];
            } else {
                $this->error = FriendlyError::message($e);
            }

            return false;
        }
    }

    // ---- Lines ---------------------------------------------------------------

    public function changeLineQuantity(string $lineId, int $delta): void
    {
        $line = $this->line($lineId);
        $quantity = $line->quantity + $delta;

        if ($this->order->bill_printed_at !== null && $delta < 0) { // the customer has seen the bill: a manager must agree
            if ($quantity < 1) {
                $this->removeLine($lineId);

                return;
            }
            $this->withManagerFallback(
                fn () => $this->quantityApproved(null, $lineId, $quantity),
                Permission::OrdersVoid, 'quantityApproved', [$lineId, $quantity],
                __('pos::pos.approval.reduce_quantity', ['item' => $line->displayName(), 'quantity' => $quantity]),
            );

            return;
        }

        $this->run(fn (OrderService $orders) => $orders->changeQuantity($this->order, $line, $quantity, auth()->user()));
    }

    /** Only reachable through approve() with a manager PIN, or from changeLineQuantity() (protected: never callable from the browser). */
    protected function quantityApproved(?User $manager, string $lineId, int $quantity): void
    {
        $this->error = null;
        try {
            app(OrderService::class)->changeQuantity($this->order, $this->line($lineId), $quantity, auth()->user(), $manager);
            unset($this->order);
        } catch (ManagerPinRequiredException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    public function removeLine(string $lineId): void
    {
        if ($this->order->bill_printed_at !== null) { // the customer has seen the bill: manager void with a reason
            $this->startVoid($lineId);

            return;
        }

        $this->run(fn (OrderService $orders) => $orders->removeItem($this->order, $this->line($lineId), auth()->user()));
    }

    public function editNote(string $lineId): void
    {
        $this->noteLineId = $lineId;
        $this->noteText = (string) $this->line($lineId)->notes;
    }

    public function saveNote(): void
    {
        $this->run(function (OrderService $orders) {
            $orders->setLineNotes($this->order, $this->line($this->noteLineId), trim($this->noteText) ?: null);
            $this->noteLineId = null;
            $this->noteText = '';
        });
    }

    public function closeNote(): void
    {
        $this->noteLineId = null;
    }

    // ---- Table sheet: move, merge, guests ------------------------------------

    public function openTableSheet(string $mode = 'menu'): void
    {
        $this->error = null;
        $this->notice = null;
        $this->tableSheet = $mode;
        $this->sheetSectionId = $this->order->table?->section_id;
        $this->covers = $this->order->covers;
    }

    public function closeTableSheet(): void
    {
        $this->tableSheet = null;
    }

    public function selectSheetSection(?string $sectionId): void
    {
        $this->sheetSectionId = $sectionId ?: null;
    }

    public function moveTo(string $tableId): void
    {
        $this->run(function (TableService $tables) use ($tableId) {
            $table = DiningTable::query()->with('currentOrder')->findOrFail($tableId);
            $tables->transfer($this->order, $table, auth()->user());
            $this->tableSheet = null;
            $this->notice = __('pos::pos.order.table_sheet.moved', ['table' => $table->name]);
        });
    }

    public function mergeWith(string $tableId): void
    {
        $this->run(function (TableService $tables) use ($tableId) {
            $from = DiningTable::query()->findOrFail($tableId);
            $tables->merge($from, $this->order->table, auth()->user());
            $this->tableSheet = null;
            $this->notice = __('pos::pos.order.table_sheet.merged', ['table' => $from->name]);
        });
    }

    public function adjustCovers(int $delta): void
    {
        $this->covers = max(1, min(99, $this->covers + $delta));
    }

    public function saveCovers(): void
    {
        $this->run(function (OrderService $orders) {
            $orders->setCovers($this->order, $this->covers, auth()->user());
            $this->tableSheet = null;
        });
    }

    #[Computed]
    public function floorPlan()
    {
        return $this->tableSheet ? app(TableService::class)->floorPlan($this->order->branch_id) : collect();
    }

    // ---- Modals ----------------------------------------------------------------

    public function openModal(string $name): void
    {
        $this->error = null;
        $this->notice = null;
        $this->modal = $name;
        if ($name !== 'delivery') {
            $this->delivery = null;
        }

        if ($name === 'note') {
            $this->orderNote = (string) $this->order->notes;
        }
        if ($name === 'hold') {
            $this->holdName = (string) $this->order->customer_name;
        }
        if ($name === 'split') {
            $this->split = ['mode' => 'items', 'lines' => [], 'seats' => [], 'ways' => 2];
        }
        if ($name === 'customer') {
            $this->customerSearch = '';
            $this->newCustomer = ['name' => '', 'phone' => ''];
        }
    }

    // ---- Customer --------------------------------------------------------------

    #[Computed]
    public function customerMatches()
    {
        return app(CustomerService::class)->search($this->customerSearch);
    }

    public function attachCustomer(string $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        app(CustomerService::class)->attach($this->order, $customer, auth()->user());
        unset($this->order);
        $this->modal = null;
        $this->notice = __('customers::customers.pos.attached', ['name' => $customer->name]);
    }

    public function removeCustomer(): void
    {
        app(CustomerService::class)->detach($this->order, auth()->user());
        unset($this->order);
        $this->modal = null;
        $this->notice = __('customers::customers.pos.removed');
    }

    public function createCustomer(): void
    {
        $this->error = null;
        try {
            $customer = app(CustomerService::class)->create(Arr::only($this->newCustomer, ['name', 'phone', 'address']), auth()->user()); // never account settings from the till
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }
        $this->attachCustomer($customer->id);
    }

    /** Delivery form: type the phone, tap Find → name and usual address fill in. */
    public function lookupDeliveryCustomer(): void
    {
        $this->error = null;
        $customer = app(CustomerService::class)->findByPhone($this->delivery['phone'] ?? '');
        if (! $customer) {
            $this->deliveryAddresses = [];
            $this->notice = __('customers::customers.pos.not_found');

            return;
        }
        $this->delivery['name'] = $this->delivery['name'] ?: $customer->name;
        $this->delivery['name'] = $customer->name;
        $addresses = $customer->addresses()->get();
        if ($address = $customer->defaultAddress()) {
            $this->delivery['address'] = $address->address;
            $this->delivery['notes'] = $this->delivery['notes'] ?: (string) $address->notes;
        }
        $this->deliveryAddresses = $addresses->map(fn ($a) => ['address' => $a->address, 'notes' => (string) $a->notes, 'label' => $a->label()])->all();
        $this->notice = __('customers::customers.pos.found', ['name' => $customer->name]);
    }

    public function useDeliveryAddress(int $index): void
    {
        if (isset($this->deliveryAddresses[$index])) {
            $this->delivery['address'] = $this->deliveryAddresses[$index]['address'];
            $this->delivery['notes'] = $this->deliveryAddresses[$index]['notes'];
        }
    }

    public function closeModal(): void
    {
        $this->modal = null;
        $this->delivery = null;
    }

    public function editDelivery(): void
    {
        $o = $this->order;
        $this->delivery = ['name' => (string) $o->customer_name, 'phone' => (string) $o->customer_phone, 'address' => (string) $o->delivery_address, 'notes' => (string) $o->delivery_notes, 'fee' => $o->delivery_fee->toDecimal()];
        $this->openModal('delivery');
    }

    /** Save; a changed fee is a price edit, so the manager keypad opens unless this user may edit prices (security review M4). */
    public function saveDelivery(): void
    {
        $raw = trim((string) ($this->delivery['fee'] ?? ''));
        $fee = Money::fromTyped($raw);

        $this->withManagerFallback(
            fn () => $this->deliveryApproved(null, $this->delivery),
            Permission::OrdersPriceEdit, 'deliveryApproved', [$this->delivery],
            __('pos::pos.approval.delivery_fee', ['amount' => money($fee)]),
        );
    }

    /** Only reachable through approve() with a manager PIN, or from saveDelivery() (protected: never callable from the browser). */
    protected function deliveryApproved(?User $manager, array $delivery): void
    {
        $this->delivery = $delivery;
        $this->error = null;
        $raw = trim((string) ($delivery['fee'] ?? ''));

        try {
            app(DeliveryService::class)->setDetails($this->order, $delivery['name'], $delivery['phone'], $delivery['address'], $delivery['notes'], Money::fromTyped($raw), auth()->user(), $manager);
            $this->modal = null;
            $this->delivery = null;
            unset($this->order);
        } catch (ManagerPinRequiredException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
            $this->modal = 'delivery';
        }
    }

    #[Computed]
    public function settings(): TillSettings
    {
        return app(TillSettings::class);
    }

    // ---- Lines: course, seat, void, discount ----------------------------------

    /** Tap the course chip: none → 2 → 3 → none. */
    public function cycleCourse(string $lineId): void
    {
        $this->run(function (OrderService $orders) use ($lineId) {
            $line = $this->line($lineId);
            $max = max(2, count($this->settings->courses($this->order->branch_id)));
            $next = ($line->course ?? 1) + 1;
            $orders->setCourse($this->order, $line, $next > $max ? null : $next, auth()->user());
        });
    }

    /** Tap the seat chip: none → 1 → 2 … → covers → none. */
    public function cycleSeat(string $lineId): void
    {
        $this->run(function (OrderService $orders) use ($lineId) {
            $line = $this->line($lineId);
            $next = ($line->seat ?? 0) + 1;
            $orders->setSeat($this->order, $line, $next > max(1, $this->order->covers) ? null : $next, auth()->user());
        });
    }

    public function fireCourse(int $course): void
    {
        $this->run(function (OrderService $orders) use ($course) {
            $orders->fireCourse($this->order, $course, auth()->user());
            $this->notice = __('pos::pos.order.course_fired', ['number' => $course]);
        });
    }

    /** Open the void modal for a sent line (pending lines are simply removed). */
    public function startVoid(?string $lineId = null): void
    {
        $this->void = ['line_id' => $lineId, 'reason' => ''];
        $this->openModal('void');
    }

    public function chooseVoidReason(string $reason): void
    {
        $this->void['reason'] = $reason;
    }

    /** Reason chosen → ask a manager, then void. */
    public function confirmVoid(): void
    {
        if (blank($this->void['reason'])) {
            $this->error = __('orders::orders.errors.reason_required');

            return;
        }

        $this->modal = null;
        $title = $this->void['line_id']
            ? __('pos::pos.approval.void_line', ['item' => $this->line($this->void['line_id'])->displayName()])
            : __('pos::pos.approval.void_order', ['number' => $this->order->displayNumber()]);

        $this->askManager(Permission::OrdersVoid, 'voidApproved', [$this->void['line_id'], $this->void['reason']], $title);
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function voidApproved(User $manager, ?string $lineId, string $reason): void
    {
        if ($lineId) {
            $this->run(fn (OrderService $orders) => $orders->voidItem($this->order, $this->line($lineId), $reason, $manager, auth()->user()));

            return;
        }

        try {
            app(OrderService::class)->void($this->order, $reason, $manager);
            $this->redirectRoute('pos.home');
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    public function startDiscount(?string $lineId = null): void
    {
        $this->error = null;
        $line = $lineId ? $this->line($lineId) : null;
        $this->discount = [
            'line_id' => $lineId,
            'type' => ($line ? $line->item_discount_type : $this->order->discount_type)?->value ?? 'percent',
            'value' => '',
            'reason' => $line ? (string) $line->item_discount_reason : (string) ($this->order->promo_code ? '' : $this->order->discount_reason),
        ];
        $current = $line ? ($line->hasItemDiscount() ? $line->item_discount_value : 0) : ($this->order->promo_code ? 0 : $this->order->discount_value);
        if ($current > 0) {
            $this->discount['value'] = $this->discount['type'] === 'percent' ? rtrim(rtrim(number_format($current / 100, 2), '0'), '.') : Money::of($current)->toDecimal();
        }
        $this->openModal('discount');
    }

    public function setDiscountType(string $type): void
    {
        $this->discount['type'] = $type;
        $this->discount['value'] = '';
    }

    public function setDiscountValue(string $value): void
    {
        $this->discount['value'] = $value;
    }

    public function chooseDiscountReason(string $reason): void
    {
        $this->discount['reason'] = $reason;
    }

    /** Apply; if the discount is above the threshold, the manager keypad opens and we retry with their approval. */
    public function applyDiscount(): void
    {
        $value = $this->discountValueInUnits();
        $args = [$this->discount['line_id'], $this->discount['type'], $value, $this->discount['reason']];

        $this->withManagerFallback(
            fn () => $this->discountApproved(null, ...$args),
            Permission::OrdersDiscount, 'discountApproved', $args,
            __('pos::pos.approval.discount', ['amount' => $this->discount['value'].($this->discount['type'] === 'percent' ? '%' : '')]),
        );
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function discountApproved(?User $manager, ?string $lineId, string $type, int $value, string $reason): void
    {
        $this->error = null;
        $kind = $value > 0 ? DiscountType::from($type) : null;

        try {
            $orders = app(OrderService::class);
            if ($lineId) {
                $orders->applyItemDiscount($this->order, $this->line($lineId), $kind, $value, $reason ?: null, auth()->user(), $manager);
            } else {
                $orders->applyDiscount($this->order, $kind, $value, $reason ?: null, auth()->user(), $manager);
            }
            $this->modal = null;
            unset($this->order);
        } catch (ManagerPinRequiredException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
            if ($manager) {
                $this->modal = 'discount';
            }
        }
    }

    public function removeDiscount(?string $lineId = null): void
    {
        $this->run(function (OrderService $orders) use ($lineId) {
            if ($lineId) {
                $orders->applyItemDiscount($this->order, $this->line($lineId), null, 0, null, auth()->user());
            } else {
                $orders->removeDiscount($this->order, auth()->user());
            }
            $this->modal = null;
        });
    }

    public function applyPromoCode(): void
    {
        $this->run(function (OrderService $orders) {
            $orders->applyPromoCode($this->order, $this->promoCode, auth()->user());
            $this->promoCode = '';
            $this->modal = null;
            $this->notice = __('pos::pos.order.promo_applied');
        });
        if ($this->error) {
            $this->modal = 'promo';
        }
    }

    public function saveOrderNote(): void
    {
        $this->run(function (OrderService $orders) {
            $orders->setNotes($this->order, trim($this->orderNote) ?: null, auth()->user());
            $this->modal = null;
        });
    }

    // ---- Service charge --------------------------------------------------------

    public function toggleServiceCharge(): void
    {
        $waive = ! $this->order->service_charge_waived;
        $this->modal = null;
        $this->askManager(Permission::OrdersDiscount, 'serviceChargeApproved', [$waive], $waive ? __('pos::pos.approval.waive_service') : __('pos::pos.approval.restore_service'));
    }

    /** Only reachable through approve() with a manager PIN (protected: never callable from the browser). */
    protected function serviceChargeApproved(User $manager, bool $waive): void
    {
        $this->run(fn (OrderService $orders) => $orders->setServiceChargeWaived($this->order, $waive, $manager, auth()->user()));
    }

    // ---- Split bill -------------------------------------------------------------

    public function setSplitMode(string $mode): void
    {
        $this->split['mode'] = $mode;
    }

    public function toggleSplitLine(string $lineId): void
    {
        $lines = $this->split['lines'];
        $this->split['lines'] = in_array($lineId, $lines, true) ? array_values(array_diff($lines, [$lineId])) : [...$lines, $lineId];
    }

    public function toggleSplitSeat(int $seat): void
    {
        $seats = $this->split['seats'];
        $this->split['seats'] = in_array($seat, $seats, true) ? array_values(array_diff($seats, [$seat])) : [...$seats, $seat];
    }

    public function setSplitWays(int $ways): void
    {
        $this->split['ways'] = max(2, min(12, $ways));
    }

    /** Move the chosen lines (or seats) to a new bill and open it. */
    public function confirmSplit(): void
    {
        $this->run(function (OrderService $orders) {
            $new = $this->split['mode'] === 'seats'
                ? $orders->splitBySeats($this->order, array_map('intval', $this->split['seats']), auth()->user())
                : $orders->split($this->order, $this->split['lines'], auth()->user());
            $this->modal = null;
            $this->redirectRoute('pos.order', $new);
        });
    }

    /** Pay in N equal shares: handled on the payment screen. */
    public function splitEvenly(): void
    {
        if (! auth()->user()->can(Permission::PaymentsTake->value)) { // the payment screen is for cashiers, like Pay
            $this->error = __('pos::pos.cash.pay_needs_cashier');
            $this->modal = null;

            return;
        }

        $this->redirectRoute('pos.pay', [$this->order, 'ways' => $this->split['ways']]);
    }

    // ---- Order actions -------------------------------------------------------

    public function send(): void
    {
        $this->run(function (OrderService $orders) {
            $orders->sendToKitchen($this->order, auth()->user());
            $this->notice = __('pos::pos.order.sent');
        });
    }

    public function pay(): void
    {
        if ($this->order->total->isZero()) {
            $this->error = __('payments::payments.errors.nothing_to_pay');

            return;
        }
        if (! auth()->user()->can(Permission::PaymentsTake->value)) {
            $this->error = __('pos::pos.cash.pay_needs_cashier');

            return;
        }

        $this->redirectRoute('pos.pay', $this->order);
    }

    /** Hold: takeaway orders ask for a name first so they can be found again. */
    public function hold(): void
    {
        if ($this->order->type !== OrderType::DineIn && $this->modal !== 'hold') {
            $this->openModal('hold');

            return;
        }

        $this->confirmHold();
    }

    public function confirmHold(): void
    {
        try {
            app(OrderService::class)->hold($this->order, trim($this->holdName) ?: null, auth()->user());
            $this->redirectRoute('pos.home');
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    /** Cancel: an untouched order just closes; once sent to the kitchen or billed it needs a reason and a manager. */
    public function cancelOrder(): void
    {
        if ($this->order->status === OrderStatus::Sent || $this->order->bill_printed_at !== null) {
            $this->startVoid(null);

            return;
        }

        try {
            app(OrderService::class)->void($this->order, __('pos::pos.order.cancelled_at_till'), auth()->user());
            $this->redirectRoute('pos.home');
        } catch (ManagerPinRequiredException $e) {
            $this->startVoid(null);
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }
    }

    /** Courses that were sent but not fired yet, for the "Fire course N" buttons. */
    #[Computed]
    public function heldCourses(): array
    {
        return $this->order->items
            ->filter(fn (OrderItem $l) => $l->isSent() && ($l->course ?? 1) > 1 && $l->fired_at === null)
            ->pluck('course')->unique()->sort()->values()->all();
    }

    private function discountValueInUnits(): int
    {
        $raw = trim((string) $this->discount['value']);
        if ($raw === '' || ! is_numeric($raw)) {
            return 0;
        }

        return $this->discount['type'] === 'percent'
            ? (int) round((float) $raw * 100)             // 10 → 1000 basis points
            : Money::fromDecimal($raw)->amount;             // 5.50 → 550 pesewas
    }

    private function line(string $lineId): OrderItem
    {
        return $this->order->items->firstWhere('id', $lineId) ?? abort(404);
    }

    private function run(callable $callback): void
    {
        $this->error = null;
        $this->notice = null;

        try {
            app()->call($callback);
        } catch (RuntimeException $e) {
            $this->error = FriendlyError::message($e);
        }

        unset($this->order);
    }

    public function render()
    {
        return view('pos::livewire.order-screen', [
            'isOpen' => $this->order->status === OrderStatus::Open,
        ]);
    }
}
