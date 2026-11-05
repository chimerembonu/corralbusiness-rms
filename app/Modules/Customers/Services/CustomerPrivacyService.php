<?php

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Reports\Services\ReportDocument;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Audit\AuditChain;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Customers' rights under Ghana's Data Protection Act, 2012 (Act 843) — Complete Edition E9.3:
 *  - export: everything held about a customer, in one Excel file;
 *  - anonymise: their name, phone, email, TIN, notes and addresses are removed, on request or after the
 *    restaurant's retention period. Orders, payments and account entries stay (sales and tax records must be
 *    kept), without the personal details; an order that carries the buyer's TIN (a VAT invoice) keeps the
 *    name and TIN the invoice needs. The audit log's copies of those details are blanked too.
 */
class CustomerPrivacyService
{
    /** Personal fields, wherever they appear (customer, address, order; and their audit log entries). */
    private const PERSONAL = ['name', 'phone', 'phone_digits', 'email', 'tin', 'notes', 'tags', 'address', 'label',
        'customer_name', 'customer_phone', 'delivery_address', 'delivery_notes'];

    /** Who may export or anonymise: the owner and managers. */
    public static function allowed(?User $user): bool
    {
        return $user?->hasAnyRole([Role::Owner->value, Role::Manager->value]) ?? false;
    }

    public function export(Customer $customer): BinaryFileResponse
    {
        $f = fn (string $k) => __('customers::customers.privacy.export.'.$k);
        $profile = [
            [$f('name'), $customer->name], [$f('phone'), $customer->phone ?? ''], [$f('email'), $customer->email ?? ''],
            [$f('tin'), $customer->tin ?? ''], [$f('notes'), $customer->notes ?? ''], [$f('tags'), implode(', ', $customer->tags ?? [])],
            [$f('since'), $customer->created_at?->format('d/m/Y') ?? ''], [$f('last_order'), $customer->last_order_at?->format('d/m/Y') ?? ''],
            [$f('orders_count'), (string) $customer->orders_count], [$f('points'), (string) $customer->points_balance],
            [$f('account_balance'), money((int) $customer->account_balance)],
        ];
        $addresses = $customer->addresses()->get()->map(fn (CustomerAddress $a) => [$a->label ?? '', $a->address, $a->notes ?? ''])->all();
        $orders = Order::query()->withoutGlobalScopes()->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)
            ->with('items')->orderBy('opened_at')->get()
            ->map(fn (Order $o) => [$o->opened_at?->format('d/m/Y H:i') ?? '', '#'.$o->displayNumber(), $o->type->label(), $o->status->label(),
                $o->items->map(fn ($i) => $i->quantity.' × '.$i->displayName())->implode(', '), $o->delivery_address ?? '', $o->total->amount])->all();
        $account = $customer->accountTransactions()->orderBy('happened_at')->get()
            ->map(fn ($t) => [$t->happened_at?->format('d/m/Y H:i') ?? '', $t->type->label(), (int) $t->amount, (int) $t->balance_after, $t->reference ?? ''])->all();

        return (new ReportDocument(__('customers::customers.privacy.export.title', ['name' => $customer->name]), now()->toDateString(), now()->toDateString()))
            ->section($f('profile'), [$f('field'), $f('value')], $profile)
            ->section($f('addresses'), [$f('label'), $f('address'), $f('notes')], $addresses)
            ->section($f('orders'), [$f('when'), $f('order'), $f('type'), $f('status'), $f('items'), $f('delivery_address'), $f('total')], $orders, [6])
            ->section($f('account'), [$f('when'), $f('what'), $f('amount'), $f('balance'), $f('reference')], $account, [2, 3])
            ->excel();
    }

    /**
     * Remove a customer's personal details. $reason is kept in the audit log (never their details).
     *
     * @throws CustomerException when they still owe (or are owed) money, or have an order still open
     */
    public function anonymise(Customer $customer, ?User $by, string $reason): Customer
    {
        if ($customer->anonymised_at) {
            return $customer;
        }
        if (blank($reason)) {
            throw new CustomerException(__('customers::customers.privacy.errors.reason'));
        }
        if ((int) $customer->account_balance !== 0) {
            throw new CustomerException(__('customers::customers.privacy.errors.balance', ['balance' => money(abs((int) $customer->account_balance))]));
        }
        $open = Order::query()->withoutGlobalScopes()->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)
            ->whereIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value])->exists();
        if ($open) {
            throw new CustomerException(__('customers::customers.privacy.errors.open_order'));
        }

        return DB::transaction(function () use ($customer, $by, $reason) {
            $orders = Order::query()->withoutGlobalScopes()->where('tenant_id', $customer->tenant_id)->where('customer_id', $customer->id)->get();
            $addresses = $customer->addresses()->withTrashed()->get();

            $this->quietly(function () use ($customer, $orders, $addresses) {
                $customer->forceFill([
                    'name' => __('customers::customers.privacy.anonymised_name', ['code' => strtoupper(substr((string) $customer->id, -6))]),
                    'phone' => null, 'email' => null, 'tin' => null, 'notes' => null, 'tags' => null,
                    'allow_account' => false, 'is_active' => false, 'points_balance' => 0, 'anonymised_at' => now(),
                ])->save();
                $addresses->each->forceDelete();
                $orders->each(fn (Order $o) => $this->stripOrder($o));
            });

            $removed = array_merge(
                $this->scrubLog($customer->getMorphClass(), [$customer->id]),
                $this->scrubLog((new CustomerAddress)->getMorphClass(), $addresses->pluck('id')->all()),
                $this->scrubLog((new Order)->getMorphClass(), $orders->pluck('id')->all()),
            );

            $log = activity('customers')->performedOn($customer);
            $entry = ($by ? $log->causedBy($by) : $log->causedByAnonymous()) // the nightly clean-up: nobody did it by hand
                ->withProperties(['reason' => trim($reason), 'orders' => $orders->count(), 'redacted_entries' => count($removed), 'redactions' => AuditChain::redactionDigest($removed)])
                ->log('customer_anonymised');
            app(AuditChain::class)->storeRedactions($removed, $entry?->getKey(), $customer->tenant_id);

            return $customer->refresh();
        });
    }

    /**
     * Retention clean-up (nightly, when Till settings → Customers sets a number of months): customers with no
     * visit for that long are anonymised (unless they still owe or are owed money), and older orders lose
     * their guest names, phones and delivery addresses.
     *
     * @return array{customers: int, orders: int}
     */
    public function cleanUp(int $months): array
    {
        if ($months < 1) {
            return ['customers' => 0, 'orders' => 0];
        }
        $cutoff = Carbon::now()->subMonths($months);
        $customers = Customer::query()->withTrashed()->whereNull('anonymised_at')->where('account_balance', 0)
            ->where(fn ($q) => $q->where('last_order_at', '<', $cutoff)->orWhere(fn ($w) => $w->whereNull('last_order_at')->where('created_at', '<', $cutoff)))
            ->get();
        $count = 0;
        foreach ($customers as $customer) {
            try {
                $this->anonymise($customer, null, __('customers::customers.privacy.retention_reason', ['months' => $months]));
                $count++;
            } catch (CustomerException) {
                // an open order: next night
            }
        }

        $orders = Order::query()->withoutGlobalScopes()->where('tenant_id', Tenancy::tenantId())->whereNull('personal_data_removed_at')
            ->where('opened_at', '<', $cutoff)->whereNotIn('status', [OrderStatus::Open->value, OrderStatus::Sent->value])
            ->where(fn ($q) => $q->whereNotNull('customer_name')->orWhereNotNull('customer_phone')->orWhereNotNull('delivery_address')->orWhereNotNull('delivery_notes'))
            ->limit(2000)->get();
        if ($orders->isNotEmpty()) {
            DB::transaction(function () use ($orders, $months) {
                $this->quietly(fn () => $orders->each(fn (Order $o) => $this->stripOrder($o)));
                $removed = $this->scrubLog((new Order)->getMorphClass(), $orders->pluck('id')->all());
                $entry = activity('customers')->causedByAnonymous()
                    ->withProperties(['months' => $months, 'orders' => $orders->count(), 'redacted_entries' => count($removed), 'redactions' => AuditChain::redactionDigest($removed)])
                    ->log('order_details_cleaned_up');
                app(AuditChain::class)->storeRedactions($removed, $entry?->getKey(), Tenancy::tenantId());
            });
        }

        return ['customers' => $count, 'orders' => $orders->count()];
    }

    /** An order keeps its money; a VAT invoice (buyer's TIN) keeps the name and TIN it needs. */
    private function stripOrder(Order $order): void
    {
        $taxInvoice = filled($order->customer_tin);
        $order->forceFill([
            'customer_name' => $taxInvoice ? $order->customer_name : null,
            'customer_phone' => null,
            'delivery_address' => null,
            'delivery_notes' => null,
            'personal_data_removed_at' => now(),
        ])->saveQuietly();
    }

    /** Blank personal fields in the audit log entries of these records (who did what, and when, stays). */
    /**
     * Blank personal fields in the audit log entries of these records (who did what, and when, stays). The
     * tamper-evident chain (E9.4) records the removal, so the check still passes.
     *
     * @return list<array{activity_id: int, old_content_hash: string, new_content_hash: string}>
     */
    private function scrubLog(string $subjectType, array $ids): array
    {
        return app(AuditChain::class)->redact($subjectType, $ids, fn (array $data) => $this->blank($data));
    }

    private function blank(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->blank($value);
            } elseif (in_array($key, self::PERSONAL, true) && $value !== null) {
                $data[$key] = '(removed)';
            }
        }

        return $data;
    }

    /** The changes themselves are not written to the audit log (they would copy the details being removed). */
    private function quietly(callable $work): void
    {
        $status = app(ActivityLogStatus::class);
        $was = ! $status->disabled();
        $status->disable();
        try {
            $work();
        } finally {
            if ($was) {
                $status->enable();
            }
        }
    }
}
