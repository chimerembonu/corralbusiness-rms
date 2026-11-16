<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Livewire\Livewire;

/*
 * Test report 9 Nov 2026, H5: "split evenly" divided what was left by the ORIGINAL number of people every
 * time (65.90 three ways asked for 32.95, then 21.96, then 14.64), because paid shares were never counted.
 * Each share is now what is left divided by the people still to pay.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->orders = app(OrderService::class);
    app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->cashier, Money::of(50000), $this->orders->businessDate());
    $this->actingAs($this->cashier);
});

it('asks every person for the same share, to the pesewa', function (int $ways, int $cokes) {
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($order, $this->menu['coke'], null, $cokes, [], null, $this->cashier);
    $total = $order->fresh()->balanceDue()->amount;

    $asked = [];
    for ($i = 0; $i < $ways; $i++) {
        $share = Livewire::withQueryParams(['ways' => $ways])->test(PaymentScreen::class, ['order' => $order->fresh()])->instance()->suggested;
        $asked[] = $share->amount;
        app(PaymentService::class)->record($order->fresh(), PaymentMethod::Cash, $share, $this->cashier, tendered: $share);
    }

    expect(array_sum($asked))->toBe($total)
        ->and(max($asked) - min($asked))->toBeLessThanOrEqual(1) // equal, or one pesewa apart
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
})->with([
    'two ways' => [2, 3],
    'three ways' => [3, 7],
    'seven ways' => [7, 11],
]);
