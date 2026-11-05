<?php

use App\Modules\Customers\Exceptions\CustomerException;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Services\CustomerPrivacyService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Settings\Filament\Pages\TillSettingsPage;
use App\Modules\Settings\Models\Setting;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

/*
 * Complete Edition E9.3 — customers' rights under Ghana's Data Protection Act (Act 843): a copy of
 * everything held about them, removal of their personal details, and removal after a retention period.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->customers = app(CustomerService::class);
    $this->ama = $this->customers->create(['name' => 'Ama Serwaa', 'phone' => '0241234567', 'email' => 'ama@example.com', 'notes' => 'Extra pepper', 'tags' => ['regular']], $this->manager);
    $this->customers->addAddress($this->ama, 'House 12, Osu', 'Home', 'Blue gate');
    $this->ama->update(['notes' => 'Extra pepper, no onions']); // an audit log entry with the old and new notes
});

/** A finished delivery order for Ama, with her details on it. */
function privacyOrder(array $details = [], string $status = 'paid'): Order
{
    $order = orders()->open(OrderType::Delivery, test()->cashier);
    orders()->addItem($order, test()->menu['coke']);
    test()->customers->attach($order, test()->ama);
    $order->fresh()->update($details + ['customer_name' => 'Ama Serwaa', 'customer_phone' => '0241234567', 'delivery_address' => 'House 12, Osu', 'delivery_notes' => 'Call at the gate']);
    $order->forceFill(['status' => $status])->saveQuietly();

    return $order->fresh();
}

function orders(): OrderService
{
    return app(OrderService::class);
}

it('gives the owner and managers a copy of everything held about a customer', function () {
    privacyOrder();

    $this->actingAs($this->manager);
    Livewire::test(EditCustomer::class, ['record' => $this->ama->id])
        ->assertActionVisible('exportData')->assertActionVisible('anonymise')
        ->callAction('exportData')->assertFileDownloaded();
    expect(Activity::query()->where('description', 'customer_data_exported')->where('causer_id', $this->manager->id)->exists())->toBeTrue();

    $response = app(CustomerPrivacyService::class)->export($this->ama->fresh());
    $book = IOFactory::load($response->getFile()->getPathname());
    $text = collect($book->getAllSheets())->map(fn ($sheet) => collect($sheet->toArray())->flatten()->filter()->implode(' | '))->implode(' | ');
    expect($text)->toContain('Ama Serwaa')->toContain('0241234567')->toContain('ama@example.com')->toContain('Extra pepper, no onions')
        ->toContain('House 12, Osu')->toContain('Blue gate')->toContain('1 × Coca-Cola');
    expect(collect($book->getSheetNames())->implode(','))->toContain('Saved addresses')->toContain('Orders')->toContain('Account');

    // Cashiers manage customers on the till, but neither export nor remove them.
    $this->actingAs($this->cashier);
    Livewire::test(EditCustomer::class, ['record' => $this->ama->id])->assertActionHidden('exportData')->assertActionHidden('anonymise');
    expect(CustomerPrivacyService::allowed($this->cashier))->toBeFalse();
});

it('removes a customer\'s personal details everywhere, keeping the sales and the VAT invoice', function () {
    $delivery = privacyOrder();
    $invoice = privacyOrder(['customer_tin' => 'C0012345678']);
    $addressIds = CustomerAddress::query()->where('customer_id', $this->ama->id)->pluck('id')->all();
    $totalBefore = Order::query()->sum('total');

    $this->actingAs($this->manager);
    Livewire::test(EditCustomer::class, ['record' => $this->ama->id])
        ->callAction('anonymise', ['reason' => ''])->assertHasActionErrors(['reason']);
    Livewire::test(EditCustomer::class, ['record' => $this->ama->id])
        ->callAction('anonymise', ['reason' => 'The customer asked by phone'])->assertHasNoActionErrors()->assertRedirect();

    $ama = Customer::query()->withTrashed()->find($this->ama->id);
    expect($ama->name)->toStartWith('Removed customer')->and($ama->phone)->toBeNull()->and($ama->phone_digits)->toBeNull()
        ->and($ama->email)->toBeNull()->and($ama->notes)->toBeNull()->and($ama->tags)->toBeNull()
        ->and($ama->is_active)->toBeFalse()->and($ama->anonymised_at)->not->toBeNull()
        ->and(DB::table('customer_addresses')->whereIn('id', $addressIds)->count())->toBe(0)
        ->and($this->customers->search('Ama'))->toHaveCount(0)
        ->and($this->customers->findByPhone('0241234567'))->toBeNull();

    // The orders stay (same totals), without her details; the VAT invoice keeps the buyer's name and TIN.
    expect($delivery->fresh())->customer_name->toBeNull()->customer_phone->toBeNull()->delivery_address->toBeNull()->delivery_notes->toBeNull()
        ->and($delivery->fresh()->customer_id)->toBe($this->ama->id)
        ->and($invoice->fresh())->customer_name->toBe('Ama Serwaa')->customer_tin->toBe('C0012345678')->customer_phone->toBeNull()
        ->and((int) Order::query()->sum('total'))->toBe((int) $totalBefore);

    // The audit log keeps who did what and when, but no longer her details (except on the VAT invoice's own entries).
    $logs = Activity::query()->whereIn('subject_id', [$this->ama->id, $delivery->id])->get();
    expect($logs)->not->toBeEmpty()
        ->and($logs->map(fn ($a) => json_encode([$a->attribute_changes, $a->properties]))->implode(' '))
        ->not->toContain('Ama Serwaa')->not->toContain('0241234567')->not->toContain('Extra pepper')->not->toContain('House 12');
    $entry = Activity::query()->where('description', 'customer_anonymised')->first();
    expect($entry->causer_id)->toBe($this->manager->id)->and($entry->properties['reason'])->toBe('The customer asked by phone');

    // Done once: the buttons are gone.
    Livewire::test(EditCustomer::class, ['record' => $this->ama->id])->assertActionHidden('anonymise')->assertActionHidden('exportData');
});

it('refuses while the customer owes money or has an order still open', function () {
    $privacy = app(CustomerPrivacyService::class);

    $this->ama->forceFill(['account_balance' => 2500])->save();
    expect(fn () => $privacy->anonymise($this->ama->fresh(), $this->manager, 'Asked'))->toThrow(CustomerException::class, 'not settled (GH₵ 25.00)');

    $this->ama->forceFill(['account_balance' => 0])->save();
    privacyOrder(status: OrderStatus::Sent->value);
    expect(fn () => $privacy->anonymise($this->ama->fresh(), $this->manager, 'Asked'))->toThrow(CustomerException::class, 'order still open')
        ->and(fn () => $privacy->anonymise($this->ama->fresh(), $this->manager, ' '))->toThrow(CustomerException::class);
    expect($this->ama->fresh()->name)->toBe('Ama Serwaa');
});

it('removes details after the retention period, each night, only when the restaurant sets one', function () {
    $old = privacyOrder();
    $guest = orders()->open(OrderType::Takeaway, $this->cashier); // a walk-in who only gave a name
    orders()->addItem($guest, $this->menu['coke']);
    $guest->fresh()->update(['customer_name' => 'Yaw', 'customer_phone' => '0209999999']);
    $guest->forceFill(['status' => OrderStatus::Paid->value])->saveQuietly();
    $this->ama->forceFill(['last_order_at' => now()])->save();
    $owes = $this->customers->create(['name' => 'Golden Tulip', 'phone' => '0302213344']);
    $owes->forceFill(['account_balance' => 9000])->save();

    $this->travel(13)->months();
    $recent = $this->customers->create(['name' => 'Kwame', 'phone' => '0551112222']);

    // Off by default: nothing happens.
    $this->artisan('rms:customers:clean-up')->assertSuccessful();
    expect($this->ama->fresh()->name)->toBe('Ama Serwaa')->and($guest->fresh()->customer_name)->toBe('Yaw');

    // The owner sets 12 months on Till settings (restaurant-wide).
    $owner = User::factory()->create(['tenant_id' => $this->branch->tenant_id, 'branch_id' => null]);
    $owner->assignRole(Role::Owner->value);
    $this->actingAs($owner);
    Livewire::test(TillSettingsPage::class)->set('data.customer_retention_months', 12)->call('save')->assertHasNoErrors();
    expect(Setting::query()->where('key', TillSettings::CUSTOMER_RETENTION_MONTHS)->whereNull('branch_id')->exists())->toBeTrue();

    $this->artisan('rms:customers:clean-up')->expectsOutputToContain('1 customer(s)')->assertSuccessful();
    expect($this->ama->fresh()->anonymised_at)->not->toBeNull()
        ->and($old->fresh()->delivery_address)->toBeNull()
        ->and($guest->fresh())->customer_name->toBeNull()->customer_phone->toBeNull()
        ->and($owes->fresh()->name)->toBe('Golden Tulip')   // still owes: kept until settled
        ->and($recent->fresh()->name)->toBe('Kwame');       // seen recently
    $entry = Activity::query()->where('description', 'customer_anonymised')->first();
    expect($entry->causer_id)->toBeNull()->and($entry->properties['reason'])->toContain('12 months');
});
