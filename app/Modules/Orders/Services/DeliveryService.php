<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\DeliveryStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\DeliveryDetailsChanged;
use App\Modules\Orders\Events\DeliveryStatusChanged;
use App\Modules\Orders\Events\RiderAssigned;
use App\Modules\Orders\Exceptions\DeliveryException;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/** Own-rider delivery: customer details, fee, rider assignment and the out → delivered journey. */
class DeliveryService
{
    public function __construct(private readonly TenantContext $context, private readonly OrderService $orders) {}

    /** Set (or change) who the order goes to and what the delivery costs. */
    public function setDetails(Order $order, string $customerName, string $phone, string $address, ?string $notes = null, ?Money $fee = null, ?User $by = null): Order
    {
        $this->assertDelivery($order);

        if (blank($phone)) {
            throw new DeliveryException(__('orders::orders.errors.phone_required'));
        }
        if (blank($address)) {
            throw new DeliveryException(__('orders::orders.errors.address_required'));
        }

        $order->customer_name = trim($customerName) ?: null;
        $order->customer_phone = trim($phone);
        $order->delivery_address = trim($address);
        $order->delivery_notes = filled($notes) ? trim($notes) : null;
        $order->delivery_status ??= DeliveryStatus::Pending;
        if ($fee !== null) {
            $order->delivery_fee = $fee->isNegative() ? Money::zero() : $fee;
        }
        $this->orders->recalculate($order);
        DeliveryDetailsChanged::dispatch($order, $by?->id);

        return $order;
    }

    public function assignRider(Order $order, User $rider, ?User $by = null): Order
    {
        $this->assertDelivery($order);
        $this->assertOpen($order);

        if (! $rider->can(Permission::DeliveryRide->value)) {
            throw new DeliveryException(__('orders::orders.errors.not_a_rider', ['name' => $rider->name]));
        }

        $order->rider_id = $rider->id;
        $order->assigned_at = now();
        if ($order->delivery_status !== DeliveryStatus::Out) {
            $order->delivery_status = DeliveryStatus::Assigned;
        }
        $order->save();
        RiderAssigned::dispatch($order, $by?->id);

        return $order;
    }

    /** The rider leaves with the food. */
    public function markOut(Order $order, ?User $by = null): Order
    {
        $this->assertDelivery($order);
        $this->assertOpen($order);

        if (! $order->rider_id) {
            throw new DeliveryException(__('orders::orders.errors.no_rider'));
        }

        return $this->transition($order, DeliveryStatus::Out, $by);
    }

    public function markDelivered(Order $order, ?User $by = null): Order
    {
        $this->assertDelivery($order);
        $this->assertOpen($order);

        return $this->transition($order, DeliveryStatus::Delivered, $by);
    }

    public function markFailed(Order $order, string $reason, ?User $by = null): Order
    {
        $this->assertDelivery($order);
        $this->assertOpen($order);

        $order->delivery_failure = trim($reason) ?: null;

        return $this->transition($order, DeliveryStatus::Failed, $by);
    }

    /** Back to "preparing" so another rider can take it (after a failed attempt). */
    public function reopen(Order $order, ?User $by = null): Order
    {
        $this->assertDelivery($order);

        $order->delivery_failure = null;
        $order->rider_id = null;
        $order->assigned_at = null;
        $order->out_at = null;

        return $this->transition($order, DeliveryStatus::Pending, $by);
    }

    /** Today's delivery orders at the branch, open ones first. */
    public function board(?string $branchId = null): Collection
    {
        return Order::query()->forBranch($branchId ?? $this->context->branchId())->deliveries()
            ->whereIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value, OrderStatus::Paid->value])
            ->where(fn ($q) => $q->whereNull('delivery_status')->orWhere('delivery_status', '!=', DeliveryStatus::Delivered->value)->orWhereDate('business_date', now()->toDateString()))
            ->with(['rider', 'customer', 'items', 'payments'])
            ->orderByRaw("case delivery_status when 'out' then 0 when 'assigned' then 1 when 'pending' then 2 when 'failed' then 3 else 4 end")
            ->orderBy('opened_at')
            ->get();
    }

    /** What a rider sees on their phone: their open deliveries. */
    public function forRider(User $rider): Collection
    {
        return Order::query()->forBranch($rider->branch_id ?? $this->context->branchId())->deliveries()
            ->where('rider_id', $rider->id)
            ->whereIn('delivery_status', [DeliveryStatus::Assigned->value, DeliveryStatus::Out->value])
            ->whereIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value, OrderStatus::Paid->value])
            ->with(['items', 'payments'])
            ->orderBy('out_at')->orderBy('assigned_at')
            ->get();
    }

    /** Staff who can ride, for the assign buttons. */
    public function riders(?string $branchId = null): Collection
    {
        return User::query()->where('is_active', true)->permission(Permission::DeliveryRide->value)->orderBy('name')->get();
    }

    private function transition(Order $order, DeliveryStatus $to, ?User $by): Order
    {
        $order->delivery_status = $to;
        match ($to) {
            DeliveryStatus::Out => $order->out_at = now(),
            DeliveryStatus::Delivered => $order->delivered_at = now(),
            default => null,
        };
        $order->save();
        DeliveryStatusChanged::dispatch($order, $by?->id);

        return $order;
    }

    private function assertDelivery(Order $order): void
    {
        if (! $order->isDelivery()) {
            throw new DeliveryException(__('orders::orders.errors.not_delivery'));
        }
    }

    private function assertOpen(Order $order): void
    {
        if ($order->delivery_status && ! $order->delivery_status->isOpen()) {
            throw new DeliveryException(__('orders::orders.errors.delivery_closed', ['status' => $order->delivery_status->label()]));
        }
    }
}
