<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;
use Livewire\Livewire;

/*
 * Security review H5: ring a drink, print the bill, pocket the cash, remove the line, cancel the order.
 * Once a bill has been printed, removing lines or cancelling needs a manager, and quiet removals show in the report.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->orders = app(OrderService::class);
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->line = $this->orders->addItem($this->order, $this->menu['coke'], null, 2, [], null, $this->cashier);
    $this->actingAs($this->cashier);
});

it('lets a cashier remove a line and cancel while no bill has been printed, and reports the removal', function () {
    $this->orders->removeItem($this->order, $this->line, $this->cashier);
    $this->orders->void($this->order->fresh(), 'Cancelled at till', $this->cashier);

    expect($this->order->fresh()->status)->toBe(OrderStatus::Void)
        ->and(OrderItem::withTrashed()->find($this->line->id)->removed_by_id)->toBe($this->cashier->id);

    $report = app(ReportService::class)->voidsAndDiscounts(now()->toDateString(), now()->toDateString());
    expect($report['removed_lines'])->toHaveCount(1)
        ->and($report['removed_lines'][0]['detail'])->toBe('2 × Coca-Cola')
        ->and($report['removed_lines'][0]['by'])->toBe('Kofi')
        ->and($report['totals']['removed_lines'])->toBe(2400)
        ->and($report['voided_orders'])->toHaveCount(1)
        ->and($report['voided_orders'][0]['detail'])->toBe('Cancelled at till');
});

it('needs a manager to remove a line or cancel once the bill has been printed', function () {
    $this->get('/pos/orders/'.$this->order->id.'/receipt')->assertOk();
    $order = $this->order->fresh();
    expect($order->bill_printed_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'bill_printed')->where('subject_id', $order->id)->exists())->toBeTrue();

    expect(fn () => $this->orders->removeItem($order, $this->line, $this->cashier))->toThrow(ManagerPinRequiredException::class)
        ->and(fn () => $this->orders->void($order, 'Cancelled at till', $this->cashier))->toThrow(ManagerPinRequiredException::class)
        ->and($this->line->fresh()->deleted_at)->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::Open);

    // The manager path works and is recorded with a reason.
    $this->orders->voidItem($order, $this->line, 'Customer changed mind', $this->manager, $this->cashier);
    expect($this->line->fresh()->voided_by_id)->toBe($this->manager->id)->and($this->line->fresh()->void_reason)->toBe('Customer changed mind');
    $this->orders->void($order->fresh(), 'Walked out', $this->manager);
    expect($order->fresh()->status)->toBe(OrderStatus::Void)->and($order->fresh()->voided_by_id)->toBe($this->manager->id);
});

it('opens the manager void prompt on the till for remove and cancel after a bill', function () {
    $this->orders->markBillPrinted($this->order, $this->cashier);

    Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('removeLine', $this->line->id)->assertSet('modal', 'void')->assertSet('void.line_id', $this->line->id)
        ->call('chooseVoidReason', 'Wrong item')->call('confirmVoid')->assertSet('approval.method', 'voidApproved')
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.');
    expect($this->line->fresh()->deleted_at)->toBeNull()->and($this->line->fresh()->voided_at)->toBeNull();

    Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('cancelOrder')->assertSet('modal', 'void')->assertSet('void.line_id', null);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Open);
});
