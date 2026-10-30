<?php

namespace App\Modules\Orders\Services;

use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Models\Modifier;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\ItemAdded;
use App\Modules\Orders\Events\ItemQuantityChanged;
use App\Modules\Orders\Events\ItemRemoved;
use App\Modules\Orders\Events\ItemVoided;
use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Orders\Events\OrderDiscountApplied;
use App\Modules\Orders\Events\OrderNotesChanged;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Orders\Events\OrderVoided;
use App\Modules\Orders\Exceptions\InvalidModifierSelectionException;
use App\Modules\Orders\Exceptions\ItemNotOrderableException;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Settings\Services\CounterService;
use App\Modules\Settings\Services\TaxService;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Everything that changes an order. Every change fires an event (CLAUDE.md §3.7). */
class OrderService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly MenuService $menu,
        private readonly TaxService $taxes,
        private readonly CounterService $counters,
        private readonly OrderCalculator $calculator,
    ) {}

    public function open(OrderType $type, ?User $by = null, ?DiningTable $table = null, int $covers = 1): Order
    {
        if ($type === OrderType::DineIn && $table && $table->isOccupied()) {
            throw new OrderLockedException(__('orders::orders.errors.table_occupied', ['table' => $table->name]));
        }

        return DB::transaction(function () use ($type, $by, $table, $covers) {
            $businessDate = $this->businessDate();

            $order = new Order([
                'business_date' => $businessDate,
                'number' => $this->counters->next('orders', $businessDate),
                'type' => $type,
                'table_id' => $table?->id,
                'covers' => max(1, $covers),
                'opened_by_id' => $by?->id,
                'opened_at' => now(),
            ]);
            $order->branch_id = $table?->branch_id ?? $this->context->branchId();
            $order->save();

            OrderCreated::dispatch($order, $by?->id);

            return $order;
        });
    }

    /**
     * @param  array<string, int>  $modifiers  modifier id → quantity
     */
    public function addItem(Order $order, Item $item, ?ItemVariant $variant = null, int $quantity = 1, array $modifiers = [], ?string $notes = null, ?User $by = null, ?int $seat = null): OrderItem
    {
        $this->assertEditable($order);

        if (! $this->menu->isOrderable($item, null, $order->branch_id)) {
            throw new ItemNotOrderableException(__('orders::orders.errors.not_orderable', ['item' => $item->name]));
        }

        if ($variant && $variant->item_id !== $item->id) {
            throw new ItemNotOrderableException(__('orders::orders.errors.not_orderable', ['item' => $item->name]));
        }

        if (! $variant && $item->variants()->active()->exists()) {
            throw new InvalidModifierSelectionException([__('orders::orders.errors.choose_size', ['item' => $item->name])]);
        }

        $chosen = $this->resolveModifiers($item, $modifiers);

        return DB::transaction(function () use ($order, $item, $variant, $quantity, $chosen, $notes, $by, $seat) {
            $line = new OrderItem([
                'order_id' => $order->id,
                'item_id' => $item->id,
                'variant_id' => $variant?->id,
                'name' => $item->name,
                'variant_name' => $variant?->name,
                'quantity' => max(1, $quantity),
                'unit_price' => $this->menu->priceFor($item, $variant, $order->branch_id),
                'modifiers_total' => $chosen->reduce(fn (Money $c, array $m) => $c->add($m['price']->multiply($m['quantity'])), Money::zero()),
                'taxable' => $item->taxable,
                'tax_exempt_codes' => $item->tax_exempt_codes,
                'notes' => $notes,
                'seat' => $seat,
                'sort_order' => ($order->items()->max('sort_order') ?? 0) + 1,
            ]);
            $line->branch_id = $order->branch_id;
            $line->save();

            foreach ($chosen as $m) {
                $line->modifiers()->create([
                    'branch_id' => $order->branch_id,
                    'modifier_id' => $m['modifier']->id,
                    'group_name' => $m['group_name'],
                    'name' => $m['modifier']->name,
                    'unit_price' => $m['price'],
                    'quantity' => $m['quantity'],
                ]);
            }

            $this->recalculate($order);
            $line->refresh();
            ItemAdded::dispatch($order, $line, $by?->id);

            return $line;
        });
    }

    public function changeQuantity(Order $order, OrderItem $line, int $quantity, ?User $by = null): OrderItem
    {
        $this->assertEditable($order);
        $this->assertPending($line);

        if ($quantity < 1) {
            return $this->removeItem($order, $line, $by);
        }

        $line->quantity = $quantity;
        $line->save();
        $this->recalculate($order);
        $line->refresh();
        ItemQuantityChanged::dispatch($order, $line, $by?->id);

        return $line;
    }

    public function removeItem(Order $order, OrderItem $line, ?User $by = null): OrderItem
    {
        $this->assertEditable($order);
        $this->assertPending($line);

        $line->delete();
        $this->recalculate($order);
        ItemRemoved::dispatch($order, $line, $by?->id);

        return $line;
    }

    /** Void a line that was already sent to the kitchen. Needs someone with the void permission (manager PIN). */
    public function voidItem(Order $order, OrderItem $line, string $reason, User $approvedBy, ?User $by = null): OrderItem
    {
        $this->assertEditable($order);

        if (! $approvedBy->can(Permission::OrdersVoid->value)) {
            throw new ManagerPinRequiredException(Permission::OrdersVoid->value);
        }

        $line->forceFill([
            'status' => OrderItemStatus::Voided,
            'voided_at' => now(),
            'void_reason' => $reason,
            'voided_by_id' => $approvedBy->id,
        ])->save();

        $this->recalculate($order);
        $line->refresh();
        ItemVoided::dispatch($order, $line, $by?->id ?? $approvedBy->id);

        return $line;
    }

    public function setNotes(Order $order, ?string $notes, ?User $by = null): Order
    {
        $this->assertEditable($order);
        $order->notes = $notes;
        $order->save();
        OrderNotesChanged::dispatch($order, $by?->id);

        return $order;
    }

    public function setLineNotes(Order $order, OrderItem $line, ?string $notes): OrderItem
    {
        $this->assertEditable($order);
        $line->notes = $notes;
        $line->save();

        return $line;
    }

    /**
     * Discount placeholder (full discount rules, presets and manager PIN come in Phase 1.2).
     *
     * @param  int  $value  basis points for percent (10 % = 1000) or pesewas for fixed
     */
    public function applyDiscount(Order $order, ?DiscountType $type, int $value = 0, ?string $reason = null, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $order->discount_type = $type;
        $order->discount_value = $type ? max(0, $value) : 0;
        $order->discount_reason = $type ? $reason : null;
        $this->recalculate($order);
        OrderDiscountApplied::dispatch($order, $by?->id);

        return $order;
    }

    /** Send every pending line to the kitchen. Returns the lines that were sent. */
    public function sendToKitchen(Order $order, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $pending = $order->items()->where('status', OrderItemStatus::Pending->value)->get();

        if ($pending->isEmpty()) {
            return $order;
        }

        DB::transaction(function () use ($order, $pending) {
            foreach ($pending as $line) {
                $line->forceFill(['status' => OrderItemStatus::Sent, 'sent_at' => now()])->save();
            }

            $order->status = OrderStatus::Sent;
            $order->sent_at ??= now();
            $order->save();
        });

        OrderSentToKitchen::dispatch($order, $pending, $by?->id);

        return $order;
    }

    /** Called by the Payments module once payments cover the total. */
    public function markPaid(Order $order, Money $paidTotal, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $order->paid_total = $paidTotal;

        if (! $paidTotal->lessThan($order->total)) {
            // Anything still pending goes to the kitchen with the payment (takeaway counters).
            $this->sendToKitchen($order, $by);
            $order->status = OrderStatus::Paid;
            $order->paid_at = now();
            $order->save();
            OrderPaid::dispatch($order, $by?->id);
        } else {
            $order->save();
        }

        return $order;
    }

    /** Cancel an order. Once something was sent to the kitchen this needs the void permission. */
    public function void(Order $order, string $reason, User $by): Order
    {
        $this->assertEditable($order);

        if ($order->status === OrderStatus::Sent && ! $by->can(Permission::OrdersVoid->value)) {
            throw new ManagerPinRequiredException(Permission::OrdersVoid->value);
        }

        $order->forceFill([
            'status' => OrderStatus::Void,
            'voided_at' => now(),
            'void_reason' => $reason,
            'voided_by_id' => $by->id,
        ])->save();

        OrderVoided::dispatch($order, $by->id);

        return $order;
    }

    public function recalculate(Order $order): Order
    {
        $items = $order->activeItems()->get();
        $profile = $this->taxes->profileFor($order->branch_id);

        $this->calculator->recalculate($order, $items, $profile);

        DB::transaction(function () use ($order, $items) {
            $items->each->save();
            $order->save();
        });

        $order->unsetRelation('items');

        return $order;
    }

    public function businessDate(): string
    {
        return now()->toDateString();
    }

    /**
     * @param  array<string, int>  $modifiers  modifier id → quantity
     * @return Collection<int, array{modifier: Modifier, group_name: string, price: Money, quantity: int}>
     */
    private function resolveModifiers(Item $item, array $modifiers): Collection
    {
        $groups = $item->modifierGroups()->with('modifiers')->get()->where('is_active', true);
        $chosen = collect();
        $errors = [];

        foreach ($groups as $group) {
            $ids = [];
            foreach ($group->modifiers as $modifier) {
                $qty = (int) ($modifiers[$modifier->id] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                if ($modifier->is_sold_out || ! $modifier->is_active) {
                    $errors[] = __('orders::orders.errors.modifier_sold_out', ['name' => $modifier->name]);

                    continue;
                }
                $ids = array_merge($ids, array_fill(0, $qty, $modifier->id));
                $chosen->push(['modifier' => $modifier, 'group_name' => $group->name, 'price' => $modifier->price, 'quantity' => $qty]);
            }

            $errors = array_merge($errors, $group->validateSelection($ids));
        }

        if ($errors !== []) {
            throw new InvalidModifierSelectionException($errors);
        }

        return $chosen;
    }

    private function assertEditable(Order $order): void
    {
        if (! $order->isActive()) {
            throw new OrderLockedException(__('orders::orders.errors.order_closed', ['status' => $order->status->label()]));
        }
    }

    private function assertPending(OrderItem $line): void
    {
        if (! $line->isPending()) {
            throw new OrderLockedException(__('orders::orders.errors.line_sent'));
        }
    }
}
