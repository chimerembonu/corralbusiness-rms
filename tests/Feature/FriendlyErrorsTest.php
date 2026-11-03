<?php

use App\Modules\Kitchen\Livewire\KitchenDisplay;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Support\TillSettings;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Pos\Livewire\DeliveriesBoard;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Errors\FriendlyError;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * Staff who reach something they may not use, or hit an error, see plain words and a way back — never a
 * bare "403 FORBIDDEN", a white error box, or the inside of the database.
 */
beforeEach(function () {
    config(['app.debug' => false]); // what the restaurant's server shows
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    foreach (['waiter' => Role::Waiter, 'cashier' => Role::Cashier, 'rider' => Role::Rider, 'counter' => Role::Inventory, 'manager' => Role::Manager] as $key => $role) {
        $this->{$key} = User::factory()->forBranch($this->branch)->create(['name' => ucfirst($key)]);
        $this->{$key}->assignRole($role->value);
    }
    Tenancy::forget();
});

function asStaff(User $user): void
{
    test()->flushSession();
    Tenancy::forget();
    test()->actingAs($user);
}

it('shows a waiter who opens the back office a plain page with a way back to the till', function () {
    asStaff($this->waiter);
    $this->get('/admin')->assertForbidden()
        ->assertSee('You do not have access to this')->assertSee('Ask a manager')
        ->assertSee('Back to the till')->assertSee(route('pos.home'), false)
        ->assertSee('Sign in as someone else')->assertSee(route('pos.logout'), false)
        ->assertDontSee('FORBIDDEN');

    $this->get('/pos/cash')->assertForbidden()->assertSee('Back to the till');
    $this->get('/hq')->assertForbidden()->assertSee('You do not have access to this');
});

it('sends back-office staff refused one screen back to the back office', function () {
    asStaff($this->counter); // Inventory role: back office yes, menu no
    $this->get('/admin/items')->assertForbidden()->assertSee('Back to the back office')->assertSee(url('/admin'), false);
});

it('shows our own refusal reason, like the kitchen screen\'s', function () {
    asStaff($this->rider);
    $this->get('/kitchen')->assertForbidden()->assertSee('Your account cannot open the kitchen screen.');
});

it('explains a missing page and an expired session in plain words', function () {
    $this->get('/pos/no-such-page')->assertNotFound()->assertSee('This page is not here')->assertSee('Go to sign-in');

    Route::middleware('web')->get('/zz-expired', fn () => throw new TokenMismatchException);
    $this->get('/zz-expired')->assertStatus(419)->assertSee('You were signed out')->assertSee('Sign in again')->assertSee(route('pos.login'), false);
});

it('never shows the technical detail of a crash', function () {
    Route::middleware('web')->get('/zz-boom', fn () => throw new RuntimeException('SQLSTATE[HY000] select * from users'));
    $this->get('/zz-boom')->assertStatus(500)->assertSee('Something went wrong')->assertSee('Try again')->assertDontSee('SQLSTATE');
});

it('shows a refused or failed button press as the screen\'s message line, without internal names', function () {
    asStaff($this->waiter);
    $board = Livewire::test(DeliveriesBoard::class)->call('markOut', '01900000-0000-7000-8000-000000000000');
    expect($board->get('error'))->toBe(__('errors.action.gone'))->not->toContain('App\\');

    // A waiter can see the kitchen screen but not bump tickets: a forced press gets a plain line.
    Tenancy::set($this->branch->tenant, $this->branch);
    $kitchen = Livewire::test(KitchenDisplay::class)->call('start', '01900000-0000-7000-8000-000000000000');
    expect($kitchen->get('error'))->toBe(__('errors.action.forbidden'));
});

it('keeps database errors out of staff messages and in the log', function () {
    Exceptions::fake();
    $e = new QueryException('mysql', 'select * from orders where id = ?', [1], new PDOException('Connection refused'));

    expect(FriendlyError::message($e))->toBe(__('errors.action.failed'))->not->toContain('select');
    Exceptions::assertReported(QueryException::class);

    // Our own errors are written for staff and pass through unchanged.
    expect(FriendlyError::message(new PaymentException('This order is already paid.')))->toBe('This order is already paid.');
});

it('takes a delivery fee typed with the cedi sign, and says plainly when it is not a number', function () {
    asStaff($this->manager);
    Tenancy::set($this->branch->tenant, $this->branch);
    app(SettingsService::class)->set(TillSettings::DELIVERY_FEE, 1000);

    expect(Money::fromTyped('GH₵ 10')->amount)->toBe(1000)
        ->and(Money::fromTyped('GHS12.50')->amount)->toBe(1250)
        ->and(Money::fromTyped('₵1,200')->amount)->toBe(120000)
        ->and(Money::fromTyped('')->amount)->toBe(0);

    Livewire::test(OrderTypeScreen::class)->call('startDelivery')
        ->set('delivery.name', 'Ama')->set('delivery.phone', '024')->set('delivery.address', 'Osu')->set('delivery.fee', 'ten cedis')
        ->call('openDelivery')->assertSet('error', __('errors.action.amount'))->assertNoRedirect();
    expect(Order::query()->count())->toBe(0);

    Livewire::test(OrderTypeScreen::class)->call('startDelivery')
        ->set('delivery.name', 'Ama')->set('delivery.phone', '024')->set('delivery.address', 'Osu')->set('delivery.fee', 'GH₵10')
        ->call('openDelivery')->assertRedirect();
    expect(Order::query()->sole()->delivery_fee)->toBePesewas(1000);
});

it('tells a waiter who taps "split into equal shares" to ask a cashier, instead of a refused page', function () {
    asStaff($this->waiter);
    Tenancy::set($this->branch->tenant, $this->branch);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $this->waiter);
    app(OrderService::class)->addItem($order, $this->menu['coke'], quantity: 2);

    Livewire::test(OrderScreen::class, ['order' => $order])->set('split.ways', 2)->call('splitEvenly')
        ->assertSet('error', __('pos::pos.cash.pay_needs_cashier'))->assertNoRedirect();
});

it('gives the back office the same plain words when a button press fails', function () {
    $notifications = Filament::getPanel('admin')->getErrorNotifications();
    expect($notifications[403]['title'])->toBe(__('errors.pages.403.title'))
        ->and($notifications[404]['body'])->toBe(__('errors.action.gone'))
        ->and($notifications[419]['title'])->toBe(__('errors.pages.419.title'));
});
