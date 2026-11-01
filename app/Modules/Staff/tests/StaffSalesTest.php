<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\StaffSalesService;
use App\Support\Money\Money;

it('totals orders served and payments taken per staff member', function () {
    seedRoles();
    $branch = provisionTenant();
    $menu = demoMenu();
    $ama = User::factory()->forBranch($branch)->create(['name' => 'Ama']);
    $ama->assignRole(Role::Waiter->value);
    $kofi = User::factory()->forBranch($branch)->create(['name' => 'Kofi']);
    $kofi->assignRole(Role::Cashier->value);
    $manager = User::factory()->forBranch($branch)->create(['name' => 'Akosua']);
    $manager->assignRole(Role::Manager->value);
    $orders = app(OrderService::class);
    $payments = app(PaymentService::class);

    $a = $orders->open(OrderType::DineIn, $ama);
    $orders->addItem($a, $menu['coke'], quantity: 3);                                // 36.00
    $payments->payCash($a, Money::fromDecimal('40'), $kofi, Money::fromDecimal('2'));  // Kofi takes it, 2.00 tip
    $b = $orders->open(OrderType::Takeaway, $ama);
    $orders->addItem($b, $menu['coke']);                                              // 12.00
    $payments->payMobileMoney($b, MomoProvider::Mtn, Money::fromDecimal('12'), 'MP1', $ama);
    $c = $orders->open(OrderType::Takeaway, $ama);
    $orders->addItem($c, $menu['coke']);
    $orders->void($c, 'Customer left', $manager);

    $rows = app(StaffSalesService::class)->between(now()->toDateString(), now()->toDateString());
    $amaRow = $rows->firstWhere('user.id', $ama->id);
    $kofiRow = $rows->firstWhere('user.id', $kofi->id);

    expect($rows)->toHaveCount(2)
        ->and($rows->first()['user']->id)->toBe($ama->id) // highest sales first
        ->and($amaRow['orders'])->toBe(2)->and($amaRow['sales'])->toBePesewas(4800)->and($amaRow['average'])->toBePesewas(2400)
        ->and($amaRow['voids'])->toBe(1)->and($amaRow['payments'])->toBe(1)->and($amaRow['taken'])->toBePesewas(1200)
        ->and($kofiRow['orders'])->toBe(0)->and($kofiRow['payments'])->toBe(1)->and($kofiRow['taken'])->toBePesewas(3600)->and($kofiRow['tips'])->toBePesewas(200);
});
