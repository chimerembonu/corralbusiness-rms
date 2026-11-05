<?php

use App\Modules\Customers\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Modules\Customers\Filament\Resources\Customers\Pages\EditCustomer;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\AccountService;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Models\Till;
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

    $session = app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->owner, Money::fromDecimal('100'), now()->toDateString());
    Livewire::test(EditCustomer::class, ['record' => $this->hotel->id])
        ->callAction('receive', ['amount' => '70', 'method' => 'cash', 'drawer' => $session->id])->assertHasNoErrors()
        ->callAction('adjust', ['amount' => '-50', 'reason' => 'Goodwill'])->assertHasNoErrors();
    expect($this->hotel->fresh()->account_balance)->toBe(0);

    $this->get('/customers/'.$this->hotel->id.'/statement')->assertOk()->assertSee('Golden Tulip')->assertSee('Goodwill')->assertSee('70.00');
    $this->get('/admin/customers/'.$this->hotel->id.'/edit')->assertOk()->assertSee('Statement');
});

it('puts cash received in the back office into the chosen till\'s drawer, and refuses cash with no till open (security review D3)', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $cashier->assignRole(Role::Cashier->value);

    // No till open: cash is refused; Mobile Money is fine (no drawer involved).
    Livewire::test(EditCustomer::class, ['record' => $this->hotel->id])
        ->callAction('receive', ['amount' => '20', 'method' => 'cash'])->assertHasActionErrors(['drawer']);
    expect(fn () => app(AccountService::class)->receivePayment($this->hotel, PaymentMethod::Cash, Money::of(2000), $this->owner))->toThrow(PaymentException::class, 'open till');
    app(AccountService::class)->receivePayment($this->hotel, PaymentMethod::MobileMoney, Money::of(1000), $this->owner, 'MOMO123', MomoProvider::Mtn);

    // Kofi's till is open: the owner, in the back office, puts the cash in Kofi's drawer.
    $session = app(CashService::class)->open(Till::create(['name' => 'Till 2', 'code' => 'T2']), $cashier, Money::fromDecimal('100'), now()->toDateString());
    Livewire::test(EditCustomer::class, ['record' => $this->hotel->id])
        ->callAction('receive', ['amount' => '30', 'method' => 'cash', 'drawer' => $session->id])->assertHasNoErrors();

    $payment = Payment::query()->where('method', PaymentMethod::Cash->value)->sole();
    expect($payment->cashier_session_id)->toBe($session->id)
        ->and(app(CashService::class)->expected($session->fresh()))->toBePesewas(10000 + 3000); // float + the account cash
});
