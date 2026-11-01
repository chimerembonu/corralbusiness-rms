<?php

use App\Modules\Customers\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
    $this->hotel = app(CustomerService::class)->create(['name' => 'Golden Tulip', 'phone' => '0302213344', 'allow_account' => true, 'credit_limit' => Money::fromDecimal('500')]);
    $this->hotel->forceFill(['account_balance' => 12000])->save();
});

it('manages customers, takes account payments and prints a statement in the back office', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Dup', 'phone' => '+233 30 221 3344'])->call('create')->assertHasFormErrors(['phone']);
    Livewire::test(CreateCustomer::class)
        ->fillForm(['name' => 'Ama Serwaa', 'phone' => '0241234567', 'notes' => 'Extra pepper', 'is_active' => true, 'allow_account' => false])->call('create')->assertHasNoFormErrors();
    Tenancy::set($this->branch->tenant, $this->branch);
    expect(Customer::query()->where('name', 'Ama Serwaa')->first()?->phone_digits)->toBe('233241234567');

    $this->get('/admin/customers')->assertOk()->assertSee('Golden Tulip')->assertSee('Ama Serwaa')->assertSee('GH₵ 120.00');

    Livewire::test(EditCustomer::class, ['record' => $this->hotel->id])
        ->callAction('receive', ['amount' => '70', 'method' => 'cash'])->assertHasNoErrors()
        ->callAction('adjust', ['amount' => '-50', 'reason' => 'Goodwill'])->assertHasNoErrors();
    expect($this->hotel->fresh()->account_balance)->toBe(0);

    $this->get('/customers/'.$this->hotel->id.'/statement')->assertOk()->assertSee('Golden Tulip')->assertSee('Goodwill')->assertSee('70.00');
    $this->get('/admin/customers/'.$this->hotel->id.'/edit')->assertOk()->assertSee('Statement');
});
