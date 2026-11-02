<?php

use App\Modules\Inventory\Livewire\StockTakeScreen;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Livewire\CashScreen;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Pos\Livewire\OrdersList;
use App\Modules\Pos\Livewire\OrderTypeScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Security review C1: the "…Approved" step behind every manager-PIN prompt must not be
 * callable from the browser with a manager's ID instead of their PIN.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->cashier->givePermissionTo(Permission::InventoryCount->value); // may count stock, may not sign a count off
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    app(CashService::class)->open($this->till, $this->cashier, Money::fromDecimal('500'), now()->toDateString());

    $orders = app(OrderService::class);
    $this->order = $orders->open(OrderType::Takeaway, $this->cashier);
    $this->line = $orders->addItem($this->order, $this->menu['coke'], null, 1, [], null, $this->cashier);
    $orders->sendToKitchen($this->order, $this->cashier);
    $this->actingAs($this->cashier);
});

it('rejects every Approved action called directly with a manager id instead of a PIN', function () {
    $attempts = [
        [OrderScreen::class, ['order' => $this->order], 'voidApproved', [$this->manager->id, null, 'Pocketed the cash']],
        [OrderScreen::class, ['order' => $this->order], 'voidApproved', [$this->manager->id, $this->line->id, 'Pocketed the cash']],
        [OrderScreen::class, ['order' => $this->order], 'discountApproved', [$this->manager->id, null, 'percent', 100, 'Friend']],
        [OrderScreen::class, ['order' => $this->order], 'serviceChargeApproved', [$this->manager->id, true]],
        [OrdersList::class, [], 'refundApproved', [$this->manager->id, 'any-payment', 'Changed mind']],
        [CashScreen::class, [], 'cashUpApproved', [$this->manager->id, 0, [], 'short']],
        [CashScreen::class, [], 'payOutApproved', [$this->manager->id, 50000, 'Bank deposit', '']],
        [CashScreen::class, [], 'closeDayApproved', [$this->manager->id, '']],
        [CashScreen::class, [], 'reopenApproved', [$this->manager->id, 'oops']],
        [OrderTypeScreen::class, [], 'trainingApproved', [$this->manager->id, true]],
        [OrderTypeScreen::class, [], 'deliveryApproved', [$this->manager->id, ['name' => 'X', 'phone' => '1', 'address' => 'Y', 'notes' => '', 'fee' => '0']]],
        [OrderScreen::class, ['order' => $this->order], 'deliveryApproved', [$this->manager->id, ['name' => 'X', 'phone' => '1', 'address' => 'Y', 'notes' => '', 'fee' => '0']]],
        [StockTakeScreen::class, [], 'finishApproved', [$this->manager->id, 'any-take']],
    ];

    foreach ($attempts as [$screen, $params, $method, $args]) {
        expect(fn () => Livewire::test($screen, $params)->call($method, ...$args))
            ->toThrow(MethodNotFoundException::class, $method);
    }

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Sent)
        ->and($order->discount_total->isZero())->toBeTrue()
        ->and($order->service_charge_waived)->toBeFalse()
        ->and($this->line->fresh()->voided_at)->toBeNull()
        ->and(app(CashService::class)->current($this->cashier)?->isOpen())->toBeTrue();
});

it('does not let the browser plant an approval of its own', function () {
    expect(fn () => Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->set('approval', ['permission' => 'orders.void', 'method' => 'voidApproved', 'args' => [null, 'x'], 'title' => 'x', 'error' => null]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Sent);
});

it('still voids through the keypad with a real manager PIN', function () {
    Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('startVoid', $this->line->id)->call('chooseVoidReason', 'Kitchen mistake')->call('confirmVoid')
        ->assertSet('approval.method', 'voidApproved')
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')->assertSet('approval', null);

    expect($this->line->fresh()->voided_at)->not->toBeNull()
        ->and($this->line->fresh()->voided_by_id)->toBe($this->manager->id);
});
