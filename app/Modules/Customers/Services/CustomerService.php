<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\LoyaltyProvider;
use App\Modules\Customers\Events\CustomerAttached;
use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/** Customer records, phone lookup, attaching a customer to an order, and the running stats. */
class CustomerService
{
    public function __construct(private readonly LoyaltyProvider $loyalty) {}

    public function findByPhone(?string $phone): ?Customer
    {
        $digits = Customer::digits($phone);

        return $digits ? Customer::query()->where('phone_digits', $digits)->first() : null;
    }

    /** @return Collection<int, Customer> up to $limit matches on name or phone */
    public function search(string $term, int $limit = 12): Collection
    {
        if (trim($term) === '') {
            return new Collection;
        }

        return Customer::query()->active()->search($term)->orderByDesc('last_order_at')->orderBy('name')->limit($limit)->get();
    }

    /** @param  array<string, mixed>  $data  name, phone, email, notes, allow_account, credit_limit */
    public function create(array $data, ?User $by = null): Customer
    {
        if (blank($data['name'] ?? null)) {
            throw new CustomerException(__('customers::customers.errors.name_required'));
        }
        if (filled($data['phone'] ?? null) && $this->findByPhone($data['phone'])) {
            throw new CustomerException(__('customers::customers.errors.phone_taken', ['phone' => $data['phone']]));
        }

        $customer = new Customer([
            'name' => trim($data['name']),
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
            'email' => filled($data['email'] ?? null) ? trim($data['email']) : null,
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'allow_account' => (bool) ($data['allow_account'] ?? false),
            'credit_limit' => $data['credit_limit'] ?? null,
        ]);
        $customer->save();

        if (filled($data['address'] ?? null)) {
            $this->addAddress($customer, $data['address'], null, $data['address_notes'] ?? null, true);
        }

        return $customer;
    }

    public function addAddress(Customer $customer, string $address, ?string $label = null, ?string $notes = null, bool $default = false): CustomerAddress
    {
        $address = trim($address);
        $existing = $customer->addresses()->where('address', $address)->first();
        if ($existing) {
            if ($default && ! $existing->is_default) {
                $customer->addresses()->update(['is_default' => false]);
                $existing->forceFill(['is_default' => true])->save();
            }

            return $existing;
        }
        if ($default || ! $customer->addresses()->exists()) {
            $customer->addresses()->update(['is_default' => false]);
            $default = true;
        }

        return $customer->addresses()->create(['label' => $label, 'address' => $address, 'notes' => $notes, 'is_default' => $default]);
    }

    /** Put a customer on an order; the order's name/phone follow, and a delivery gets the saved address. */
    public function attach(Order $order, Customer $customer, ?User $by = null): Order
    {
        $order->customer_id = $customer->id;
        $order->customer_name = $customer->name;
        $order->customer_phone = $customer->phone ?? $order->customer_phone;
        $order->customer_tin = $order->customer_tin ?: $customer->tin; // business customers: TIN on the receipt
        if ($order->isDelivery() && blank($order->delivery_address) && ($address = $customer->defaultAddress())) {
            $order->delivery_address = $address->address;
            $order->delivery_notes = $order->delivery_notes ?: $address->notes;
        }
        $order->save();

        CustomerAttached::dispatch($order, $customer, $by?->id);

        return $order;
    }

    public function detach(Order $order, ?User $by = null): Order
    {
        $order->customer_id = null;
        $order->save();

        return $order;
    }

    /**
     * A delivery order was saved with a phone number: find that customer or create them,
     * remember the address, and attach. Nothing happens without a phone.
     */
    public function rememberFromDelivery(Order $order, ?User $by = null): ?Customer
    {
        if (blank($order->customer_phone)) {
            return null;
        }
        $customer = $this->findByPhone($order->customer_phone);
        if (! $customer) {
            $customer = $this->create(['name' => $order->customer_name ?: __('customers::customers.unnamed', ['phone' => $order->customer_phone]), 'phone' => $order->customer_phone]);
        } elseif ($order->customer_name && $customer->name !== $order->customer_name && str_starts_with($customer->name, __('customers::customers.unnamed_prefix'))) {
            $customer->forceFill(['name' => $order->customer_name])->save();
        }
        if (filled($order->delivery_address)) {
            $this->addAddress($customer, $order->delivery_address, null, $order->delivery_notes);
        }
        if ($order->customer_id !== $customer->id) {
            $order->customer_id = $customer->id;
            $order->saveQuietly();
        }

        return $customer;
    }

    /** Recount orders, spend and last visit from paid orders (called when an order is paid). */
    public function syncStats(Customer $customer): Customer
    {
        $paid = Order::query()->withoutGlobalScopes()->where('is_training', false)->where('customer_id', $customer->id)->where('status', OrderStatus::Paid->value);

        $customer->forceFill([
            'orders_count' => (clone $paid)->count(),
            'total_spent' => Money::of((int) (clone $paid)->sum('total')),
            'last_order_at' => (clone $paid)->max('paid_at'),
        ])->saveQuietly();

        return $customer;
    }

    /** Called when an order is paid: stats, then loyalty points. */
    public function recordPaidOrder(Order $order): void
    {
        if (! $order->customer_id || $order->is_training) {
            return;
        }
        $customer = Customer::query()->withoutGlobalScopes()->find($order->customer_id);
        if (! $customer) {
            return;
        }
        $this->syncStats($customer);
        $this->loyalty->award($customer, $order);
    }

    /** @return Collection<int, Order> */
    public function history(Customer $customer, int $limit = 20): Collection
    {
        return $customer->orders()->with(['items', 'branch'])->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Refunded->value, OrderStatus::Void->value])->limit($limit)->get();
    }
}
