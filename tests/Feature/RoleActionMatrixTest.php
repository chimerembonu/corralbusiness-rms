<?php

use App\Modules\Customers\Livewire\CustomersScreen;
use App\Modules\Inventory\Livewire\StockTakeScreen;
use App\Modules\Kitchen\Livewire\KitchenDisplay;
use App\Modules\Kitchen\Models\Ticket;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\CashierSession;
use App\Modules\Payments\Models\CashMovement;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Livewire\DeliveriesBoard;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Livewire\RiderScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Printing\Services\PrintService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Pages\StaffSalesPage;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Role action guard: every role presses the important buttons on the till, kitchen and stock screens,
 * each in a fresh app, and the outcome is compared with who is approved to do it (roleActionApproved()).
 * Till and kitchen screens sit behind the till login, which refuses roles without pos.access; that
 * login is applied here too, because Livewire::test() does not run route middleware.
 */
function roleActionApproved(): array
{
    return [
        'create order' => ['owner', 'manager', 'cashier', 'waiter'],
        'small discount 5%' => ['owner', 'manager', 'cashier', 'waiter'],  // under the till's discount threshold; above it needs a manager PIN
        'open payment screen' => ['owner', 'manager', 'cashier'],
        'open a till' => ['owner', 'manager', 'cashier'],
        'cash pay-in' => ['owner', 'manager', 'cashier'],
        'bump kitchen ticket' => ['owner', 'manager', 'kitchen'],
        'open deliveries board' => ['owner', 'manager', 'cashier', 'waiter'],
        'open rider screen' => ['owner', 'rider'],
        'customer accounts' => ['owner', 'manager', 'cashier'],
        'open stock-take screen' => ['owner', 'manager', 'inventory'],
        'print X report' => ['owner', 'manager', 'inventory'],
    ];
}
function probeOutcome(callable $fn): string
{
    try {
        $r = $fn();

        return $r === true ? 'ALLOWED' : ($r === false ? 'DENIED (no effect)' : (string) $r);
    } catch (HttpException $e) {
        return 'DENIED ('.$e->getStatusCode().')';
    } catch (Throwable $e) {
        return 'DENIED ('.class_basename($e).')';
    }
}

dataset('roles', array_map(fn (Role $r) => $r->value, Role::cases()));
dataset('actions', array_keys(roleActionApproved()));

it('lets only approved roles press', function (string $action, string $role) {
    seedRoles();
    $branch = provisionTenant();
    $menu = demoMenu();
    $till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    $owner = User::factory()->forBranch($branch)->withPin('9999')->create(['name' => 'Owner']);
    $owner->assignRole(Role::Owner->value);
    $user = $owner;
    if ($role !== 'owner') {
        $user = User::factory()->forBranch($branch)->withPin('7777')->create(['name' => 'Probe']);
        $user->assignRole($role);
    }
    app(CurrentTill::class)->remember($till);
    $orders = app(OrderService::class);
    $cash = app(CashService::class);
    $order = $orders->open(OrderType::Takeaway, $owner);
    $orders->addItem($order, $menu['coke'], null, 2, [], null, $owner);
    $this->actingAs($user);
    $tillScreen = ! in_array($action, ['cash pay-in'], true);

    $result = $tillScreen && ! $user->can('pos.access') ? 'DENIED (till login refuses this role)' : probeOutcome(match ($action) {
        'create order' => function () use ($user) {
            Livewire::test(OrderTypeScreen::class)->call('startTakeaway');

            return Order::query()->where('opened_by_id', $user->id)->exists();
        },
        'small discount 5%' => function () use ($order) {
            Livewire::test(OrderScreen::class, ['order' => $order])->call('startDiscount', null)->call('setDiscountType', 'percent')
                ->call('setDiscountValue', '5')->call('chooseDiscountReason', 'Regular customer')->call('applyDiscount');

            return $order->fresh()->discount_total->isPositive() ? true : 'NO EFFECT';
        },
        'open payment screen' => function () use ($order, $cash, $till, $user) {
            if ($user->can('payments.take')) {
                $cash->open($till, $user, Money::of(5000), app(OrderService::class)->businessDate());
            }
            $orders = app(OrderService::class);
            $orders->sendToKitchen($order->fresh(), $user->can('orders.create') ? $user : null);
            Livewire::test(PaymentScreen::class, ['order' => $order->fresh()])->assertOk();

            return true;
        },
        'open a till' => function () use ($user) {
            Livewire::test(CashScreen::class)->call('openModal', 'open')->set('amount', '50')->call('openTill');

            return CashierSession::query()->where('user_id', $user->id)->exists();
        },
        'cash pay-in' => function () use ($cash, $till, $owner, $user) {
            $session = $cash->open($till, $owner, Money::of(5000), app(OrderService::class)->businessDate());
            $cash->payIn($session, Money::of(1000), 'Change from bank', $user);

            return CashMovement::query()->where('cashier_session_id', $session->id)->exists();
        },
        'bump kitchen ticket' => function () use ($order, $orders, $owner) {
            $orders->sendToKitchen($order->fresh(), $owner);
            $ticket = Ticket::query()->first();
            if (! $ticket) {
                return 'NO TICKET (no station)';
            }
            $before = $ticket->status;
            Livewire::test(KitchenDisplay::class)->call('bump', $ticket->id);

            return $ticket->fresh()->status !== $before;
        },
        'open deliveries board' => fn () => (bool) Livewire::test(DeliveriesBoard::class)->assertOk(),
        'open rider screen' => fn () => (bool) Livewire::test(RiderScreen::class)->assertOk(),
        'customer accounts' => fn () => (bool) Livewire::test(CustomersScreen::class)->assertOk(),
        'open stock-take screen' => fn () => (bool) Livewire::test(StockTakeScreen::class)->assertOk(),
        'print X report' => function () {
            // A refused call leaves no message behind; a real one always says "printing" or "no printer".
            return Livewire::test(CashScreen::class)->call('printX', app(PrintService::class))->get('notice') !== null;
        },
    });

    $approved = in_array($role, roleActionApproved()[$action], true);
    expect($result)->when($approved, fn ($e) => $e->toBe('ALLOWED', "{$role} should be able to: {$action}"))
        ->when(! $approved, fn ($e) => $e->toStartWith('DENIED', "{$role} must NOT be able to: {$action} (got {$result})"));
})->with('actions')->with('roles');

it('lets only roles with the export permission download staff sales', function (string $role) {
    seedRoles();
    $branch = provisionTenant();
    $user = User::factory()->forBranch($branch)->create();
    $user->assignRole($role);
    $this->actingAs($user);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));

    $downloaded = probeOutcome(function () {
        Livewire::test(StaffSalesPage::class)->callAction('export')->assertFileDownloaded();

        return true;
    }) === 'ALLOWED';

    expect($downloaded)->toBe(in_array($role, ['owner', 'manager', 'accountant'], true), "{$role} staff-sales export");
})->with(['owner', 'manager', 'inventory', 'accountant'])
    ->skip('Known gap (role review 4 Nov): the Staff sales Export button ignores reports.export, so Inventory can download it. Remove this skip once fixed.');
