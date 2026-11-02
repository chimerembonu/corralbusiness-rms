<?php

namespace App\Modules\Orders\Services;

use App\Modules\Menu\Models\Item;
use App\Modules\Menu\Models\ItemVariant;
use App\Modules\Menu\Models\Modifier;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\Enums\DeliveryStatus;
use App\Modules\Orders\Enums\DiscountType;
use App\Modules\Orders\Enums\OrderItemStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\CourseFired;
use App\Modules\Orders\Events\ItemAdded;
use App\Modules\Orders\Events\ItemCourseChanged;
use App\Modules\Orders\Events\ItemDiscountApplied;
use App\Modules\Orders\Events\ItemQuantityChanged;
use App\Modules\Orders\Events\ItemRemoved;
use App\Modules\Orders\Events\ItemSeatChanged;
use App\Modules\Orders\Events\ItemVoided;
use App\Modules\Orders\Events\OrderCoversChanged;
use App\Modules\Orders\Events\OrderCreated;
use App\Modules\Orders\Events\OrderDiscountApplied;
use App\Modules\Orders\Events\OrderHeld;
use App\Modules\Orders\Events\OrderNotesChanged;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Events\OrderRecalled;
use App\Modules\Orders\Events\OrderSentToKitchen;
use App\Modules\Orders\Events\OrderSplit;
use App\Modules\Orders\Events\OrderVoided;
use App\Modules\Orders\Events\ServiceChargeChanged;
use App\Modules\Orders\Exceptions\DiscountException;
use App\Modules\Orders\Exceptions\InvalidModifierSelectionException;
use App\Modules\Orders\Exceptions\ItemNotOrderableException;
use App\Modules\Orders\Exceptions\OrderLockedException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\PromoCode;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Services\DayCloseService;
use App\Modules\Pos\Services\TrainingMode;
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
        private readonly TillSettings $settings,
    ) {}

    public function open(OrderType $type, ?User $by = null, ?DiningTable $table = null, int $covers = 1): Order
    {
        if ($type === OrderType::DineIn && $table) {
            app(TableService::class)->assertFree($table);
        }

        $businessDate = $this->businessDate();
        app(DayCloseService::class)->assertOpen($businessDate, $table?->branch_id ?? $this->context->branchId());

        return DB::transaction(function () use ($type, $by, $table, $covers, $businessDate) {
            $order = new Order([
                'business_date' => $businessDate,
                'number' => $this->counters->next('orders', $businessDate),
                'type' => $type,
                'table_id' => $table?->id,
                'covers' => max(1, $covers),
                'opened_by_id' => $by?->id,
                'opened_at' => now(),
            ]);
            $order->is_training = app(TrainingMode::class)->isOn();
            $order->branch_id = $table?->branch_id ?? $this->context->branchId();
            $order->service_charge_bp = $this->serviceChargeRateFor($type, $order->branch_id);
            if ($type === OrderType::Delivery) {
                $order->delivery_status = DeliveryStatus::Pending;
                $order->delivery_fee = Money::of($this->settings->deliveryFee($order->branch_id));
                $order->total = $order->delivery_fee;
            }
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

    /**
     * Remove a line that was never sent to the kitchen. Once a bill has been printed for the order
     * this is no longer a quiet removal: it has to go through voidItem() with a manager (security review H5).
     */
    public function removeItem(Order $order, OrderItem $line, ?User $by = null): OrderItem
    {
        $this->assertEditable($order);
        $this->assertPending($line);

        if ($order->bill_printed_at !== null) {
            throw new ManagerPinRequiredException(Permission::OrdersVoid->value);
        }

        $line->forceFill(['removed_by_id' => $by?->id])->save();
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

    // ---- Discounts, promo codes, service charge ------------------------------

    /**
     * Order-level discount. Above the branch threshold (Till settings) it needs someone with the
     * discount permission as $approvedBy (manager PIN). Replaces any promo code.
     *
     * @param  int  $value  basis points for percent (10 % = 1000) or pesewas for fixed
     */
    public function applyDiscount(Order $order, ?DiscountType $type, int $value = 0, ?string $reason = null, ?User $by = null, ?User $approvedBy = null): Order
    {
        $this->assertEditable($order);

        if ($type === null || $value <= 0) {
            return $this->removeDiscount($order, $by);
        }
        if (blank($reason)) {
            throw new DiscountException(__('orders::orders.errors.reason_required'));
        }

        $lineDiscounts = $this->itemDiscountTotal($order);
        $base = $order->subtotal->subtract($lineDiscounts);
        $this->assertDiscountAllowed($type, $value, $base, $order->branch_id, $approvedBy, $lineDiscounts, $order->subtotal);

        $this->releasePromoCode($order);
        $order->discount_type = $type;
        $order->discount_value = $value;
        $order->discount_reason = $reason;
        $order->discount_approved_by_id = $approvedBy?->id;
        $this->recalculate($order);
        OrderDiscountApplied::dispatch($order, $by?->id);

        return $order;
    }

    /** Discount on one line (same threshold rule as the order discount). */
    public function applyItemDiscount(Order $order, OrderItem $line, ?DiscountType $type, int $value = 0, ?string $reason = null, ?User $by = null, ?User $approvedBy = null): OrderItem
    {
        $this->assertEditable($order);

        if ($type !== null && $value > 0) {
            if (blank($reason)) {
                throw new DiscountException(__('orders::orders.errors.reason_required'));
            }
            // Everything already off the order except this line's own discount (which is being replaced).
            $alreadyOff = $order->discount_total->subtract($line->itemDiscount());
            $this->assertDiscountAllowed($type, $value, $line->unitTotal()->multiply($line->quantity), $order->branch_id, $approvedBy, $alreadyOff, $order->subtotal);
        }

        $line->item_discount_type = $value > 0 ? $type : null;
        $line->item_discount_value = $value > 0 && $type ? $value : 0;
        $line->item_discount_reason = $value > 0 && $type ? $reason : null;
        $line->item_discount_approved_by_id = $value > 0 && $type ? $approvedBy?->id : null;
        $line->save();
        $this->recalculate($order);
        $line->refresh();
        ItemDiscountApplied::dispatch($order, $line, $by?->id);

        return $line;
    }

    /** Type a promo code at the till. Replaces any manual order discount. */
    public function applyPromoCode(Order $order, string $code, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $promo = PromoCode::query()->forBranchOrTenant($order->branch_id)->where('code', strtoupper(trim($code)))->first();
        if (! $promo) {
            throw new DiscountException(__('orders::orders.promo.unknown'));
        }

        $base = $order->subtotal->subtract($this->itemDiscountTotal($order));
        if ($reason = $promo->rejectionReason($base)) {
            throw new DiscountException($reason);
        }

        $this->releasePromoCode($order);
        $order->discount_type = $promo->type;
        $order->discount_value = $promo->value;
        $order->discount_reason = $promo->name;
        $order->discount_approved_by_id = null;
        $order->promo_code_id = $promo->id;
        $order->promo_code = $promo->code;
        $promo->increment('uses_count');
        $order->setRelation('promoCode', $promo);
        $this->recalculate($order);
        OrderDiscountApplied::dispatch($order, $by?->id);

        return $order;
    }

    public function removeDiscount(Order $order, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $this->releasePromoCode($order);
        $order->discount_type = null;
        $order->discount_value = 0;
        $order->discount_reason = null;
        $order->discount_approved_by_id = null;
        $this->recalculate($order);
        OrderDiscountApplied::dispatch($order, $by?->id);

        return $order;
    }

    /** Waive (or restore) the service charge. Needs the discount permission (manager PIN). */
    public function setServiceChargeWaived(Order $order, bool $waived, User $approvedBy, ?User $by = null): Order
    {
        $this->assertEditable($order);

        if (! $approvedBy->can(Permission::OrdersDiscount->value)) {
            throw new ManagerPinRequiredException(Permission::OrdersDiscount->value);
        }

        $order->service_charge_waived = $waived;
        $this->recalculate($order);
        ServiceChargeChanged::dispatch($order, $by?->id ?? $approvedBy->id);

        return $order;
    }

    // ---- Courses, seats, firing ----------------------------------------------

    /** Which course a line belongs to (1 = goes straight away; 2+ are held until fired). Pending lines only. */
    public function setCourse(Order $order, OrderItem $line, ?int $course, ?User $by = null): OrderItem
    {
        $this->assertEditable($order);
        $this->assertPending($line);

        $line->course = $course && $course > 1 ? min(9, $course) : null;
        $line->save();
        ItemCourseChanged::dispatch($order, $line, $by?->id);

        return $line;
    }

    public function setSeat(Order $order, OrderItem $line, ?int $seat, ?User $by = null): OrderItem
    {
        $this->assertEditable($order);

        $line->seat = $seat && $seat > 0 ? min(99, $seat) : null;
        $line->save();
        ItemSeatChanged::dispatch($order, $line, $by?->id);

        return $line;
    }

    /** Tell the kitchen to start a held course. Returns the lines that were fired. */
    public function fireCourse(Order $order, int $course, ?User $by = null): Collection
    {
        $this->assertEditable($order);

        $lines = $order->items()->where('status', OrderItemStatus::Sent->value)->where('course', $course)->whereNull('fired_at')->get();
        if ($lines->isEmpty()) {
            throw new OrderLockedException(__('orders::orders.errors.no_course', ['number' => $course]));
        }

        foreach ($lines as $line) {
            $line->forceFill(['fired_at' => now()])->save();
        }
        $order->unsetRelation('items');
        CourseFired::dispatch($order, $course, $lines, $by?->id);

        return $lines;
    }

    // ---- Split bill, hold & recall ------------------------------------------

    /**
     * Move the chosen lines onto a new order (same table/type) so they can be paid separately.
     * Kitchen tickets already printed stay as they are. Returns the new order.
     *
     * @param  array<int, string>  $lineIds
     */
    public function split(Order $order, array $lineIds, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $lines = $order->activeItems()->whereIn('id', $lineIds)->get();
        if ($lines->isEmpty()) {
            throw new OrderLockedException(__('orders::orders.errors.nothing_to_split'));
        }
        if ($lines->count() >= $order->activeItems()->count()) {
            throw new OrderLockedException(__('orders::orders.errors.cannot_split_all'));
        }

        return DB::transaction(function () use ($order, $lines, $by) {
            $seats = $lines->pluck('seat')->filter()->unique()->count();
            $businessDate = $this->businessDate();

            $new = new Order([
                'business_date' => $businessDate,
                'number' => $this->counters->next('orders', $businessDate),
                'type' => $order->type,
                'table_id' => $order->table_id,
                'covers' => max(1, $seats),
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'opened_by_id' => $by?->id ?? $order->opened_by_id,
                'opened_at' => now(),
                'service_charge_bp' => $order->service_charge_bp,
                'service_charge_waived' => $order->service_charge_waived,
            ]);
            $new->branch_id = $order->branch_id;
            $new->split_from_id = $order->id;
            $new->status = $lines->contains(fn (OrderItem $l) => $l->isSent()) ? OrderStatus::Sent : OrderStatus::Open;
            $new->sent_at = $new->status === OrderStatus::Sent ? ($order->sent_at ?? now()) : null;
            $new->save();

            OrderItem::query()->whereIn('id', $lines->pluck('id'))->update(['order_id' => $new->id]);

            $order->covers = max(1, $order->covers - $seats);
            $this->recalculate($order);
            $this->recalculate($new);

            OrderSplit::dispatch($new, $order->id, $by?->id);

            return $new->fresh(['items']);
        });
    }

    /** @param  array<int, int>  $seats */
    public function splitBySeats(Order $order, array $seats, ?User $by = null): Order
    {
        $ids = $order->activeItems()->whereIn('seat', $seats)->pluck('id')->all();

        return $this->split($order, $ids, $by);
    }

    /** Park the order (e.g. a takeaway customer who will come back); optionally tag it with a name. */
    public function hold(Order $order, ?string $customerName = null, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $order->held_at = now();
        if (filled($customerName)) {
            $order->customer_name = trim($customerName);
        }
        $order->save();
        OrderHeld::dispatch($order, $by?->id);

        return $order;
    }

    public function recall(Order $order, ?User $by = null): Order
    {
        if ($order->held_at === null) {
            return $order;
        }

        $order->held_at = null;
        $order->save();
        OrderRecalled::dispatch($order, $by?->id);

        return $order;
    }

    /** Send every pending line to the kitchen. Returns the lines that were sent. */
    public function sendToKitchen(Order $order, ?User $by = null): Order
    {
        $this->assertEditable($order);

        // Lock the order and pick the pending lines inside the transaction, so two tills sending at
        // once cannot both send (and deduct stock for) the same lines (security review M2).
        $pending = DB::transaction(function () use ($order) {
            $this->assertEditable(Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail());

            $pending = $order->items()->where('status', OrderItemStatus::Pending->value)->lockForUpdate()->get();
            if ($pending->isEmpty()) {
                return $pending;
            }
            foreach ($pending as $line) {
                $line->forceFill(['status' => OrderItemStatus::Sent, 'sent_at' => now()])->save();
            }

            $order->status = OrderStatus::Sent;
            $order->sent_at ??= now();
            $order->save();

            return $pending;
        });

        if ($pending->isEmpty()) {
            return $order;
        }

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
    /** Number of guests at the table. */
    public function setCovers(Order $order, int $covers, ?User $by = null): Order
    {
        $this->assertEditable($order);

        $order->covers = max(1, min(99, $covers));
        $order->save();

        OrderCoversChanged::dispatch($order, $by?->id);

        return $order;
    }

    public function void(Order $order, string $reason, User $by): Order
    {
        $this->assertEditable($order);

        // Sent to the kitchen, or a bill already printed: cancelling needs a manager (security review H5).
        $needsManager = $order->status === OrderStatus::Sent || $order->bill_printed_at !== null;
        if ($needsManager && ! $by->can(Permission::OrdersVoid->value)) {
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

    /** The customer has seen the bill: from now on removing lines or cancelling needs a manager (security review H5). */
    public function markBillPrinted(Order $order, ?User $by = null): Order
    {
        if ($order->bill_printed_at !== null || ! $order->isActive()) {
            return $order;
        }

        $order->forceFill(['bill_printed_at' => now()])->save();
        activity('order')->performedOn($order)->causedBy($by)->log('bill_printed');

        return $order;
    }

    public function recalculate(Order $order): Order
    {
        if ($order->promo_code_id && ! $order->relationLoaded('promoCode')) {
            $order->load('promoCode');
        }
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

    /** The service charge rate for new orders of this type at the branch (Till settings). */
    public function serviceChargeRateFor(OrderType $type, ?string $branchId = null): int
    {
        return in_array($type->value, $this->settings->serviceChargeOrderTypes($branchId), true)
            ? $this->settings->serviceChargeBp($branchId)
            : 0;
    }

    /**
     * Does a discount of this size need a manager? Threshold is a % of the base amount (Till settings).
     * When $alreadyOff and $subtotal are given, the discount is also judged together with everything
     * already off the order (security review M1): 10 % on each line plus 10 % on the order is 19 %, not 10 %.
     */
    public function discountNeedsManager(DiscountType $type, int $value, Money $base, ?string $branchId = null, ?Money $alreadyOff = null, ?Money $subtotal = null): bool
    {
        $threshold = $this->settings->discountThresholdBp($branchId);
        $thisOne = $type === DiscountType::Percent
            ? $value > $threshold
            : ($base->isZero() || Money::of($value)->greaterThan($base->basisPoints($threshold)));
        if ($thisOne) {
            return true;
        }
        if ($alreadyOff === null || $subtotal === null || $subtotal->isZero()) {
            return false;
        }
        $proposed = $type === DiscountType::Percent ? $base->basisPoints($value) : Money::of($value);

        return $alreadyOff->add($proposed)->greaterThan($subtotal->basisPoints($threshold));
    }

    private function assertDiscountAllowed(DiscountType $type, int $value, Money $base, ?string $branchId, ?User $approvedBy, ?Money $alreadyOff = null, ?Money $subtotal = null): void
    {
        if ($type === DiscountType::Fixed && Money::of($value)->greaterThan($base)) {
            throw new DiscountException(__('orders::orders.errors.discount_too_big'));
        }
        if ($type === DiscountType::Percent && $value > 10000) {
            throw new DiscountException(__('orders::orders.errors.discount_too_big'));
        }
        if ($this->discountNeedsManager($type, $value, $base, $branchId, $alreadyOff, $subtotal) && ! $approvedBy?->can(Permission::OrdersDiscount->value)) {
            throw new ManagerPinRequiredException(Permission::OrdersDiscount->value);
        }
    }

    private function itemDiscountTotal(Order $order): Money
    {
        return $order->activeItems()->get()->reduce(fn (Money $c, OrderItem $l) => $c->add($l->itemDiscount()), Money::zero());
    }

    /** Give a promo code its use back when it is removed or replaced. */
    private function releasePromoCode(Order $order): void
    {
        if ($order->promo_code_id) {
            PromoCode::query()->whereKey($order->promo_code_id)->where('uses_count', '>', 0)->decrement('uses_count');
            $order->promo_code_id = null;
            $order->promo_code = null;
            $order->unsetRelation('promoCode');
        }
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
