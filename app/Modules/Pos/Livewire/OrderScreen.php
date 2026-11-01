<?php

namespace App\Modules\Pos\Livewire;

use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Models\ModifierGroup;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\TableService;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/** The main order-taking screen: order panel on the left, category tabs + item grid on the right. */
#[Layout('pos::layouts.app', ['title' => 'Order'])]
class OrderScreen extends Component
{
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
        return Order::query()->with(['items.modifiers', 'table.mergedTables'])->findOrFail($this->orderId);
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
                $this->picker['errors'] = [$e->getMessage()];
            } else {
                $this->error = $e->getMessage();
            }

            return false;
        }
    }

    // ---- Lines ---------------------------------------------------------------

    public function changeLineQuantity(string $lineId, int $delta): void
    {
        $this->run(function (OrderService $orders) use ($lineId, $delta) {
            $line = $this->line($lineId);
            $orders->changeQuantity($this->order, $line, $line->quantity + $delta, auth()->user());
        });
    }

    public function removeLine(string $lineId): void
    {
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

        $this->redirectRoute('pos.pay', $this->order);
    }

    public function hold(): void
    {
        $this->redirectRoute('pos.home');
    }

    public function cancelOrder(): void
    {
        try {
            app(OrderService::class)->void($this->order, __('pos::pos.order.cancelled_at_till'), auth()->user());
            $this->redirectRoute('pos.home');
        } catch (ManagerPinRequiredException $e) {
            $this->error = $e->getMessage();
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }
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
            $this->error = $e->getMessage();
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
