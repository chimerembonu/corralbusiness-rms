<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Reports\Services\ReportService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Models\User;
use Livewire\Livewire;

/*
 * Re-review of H5: removing a line after the bill was printed needed a manager, but the "−" button did
 * not, so 10 × Coca-Cola could quietly become 1 × after the customer had paid for ten. Reducing a
 * quantity after the bill now needs a manager and shows in the voids report; increasing never does.
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
    $this->line = $this->orders->addItem($this->order, $this->menu['coke'], null, 10, [], null, $this->cashier);
    $this->orders->markBillPrinted($this->order, $this->cashier);
    $this->actingAs($this->cashier);
});

it('needs a manager to make a line smaller after the bill, logs it and reports it', function () {
    expect(fn () => $this->orders->changeQuantity($this->order, $this->line, 1, $this->cashier))->toThrow(ManagerPinRequiredException::class);
    expect($this->line->fresh()->quantity)->toBe(10);

    $this->orders->changeQuantity($this->order, $this->line, 11, $this->cashier); // more is never a problem
    expect($this->line->fresh()->quantity)->toBe(11);

    $this->orders->changeQuantity($this->order, $this->line->fresh(), 1, $this->cashier, $this->manager);
    expect($this->line->fresh()->quantity)->toBe(1)->and($this->order->fresh()->total)->toBePesewas(1200);

    $report = app(ReportService::class)->voidsAndDiscounts(now()->toDateString(), now()->toDateString());
    expect($report['reduced_lines'])->toHaveCount(1)
        ->and($report['reduced_lines'][0]['detail'])->toBe('Coca-Cola 11 → 1 · after the bill was printed')
        ->and($report['reduced_lines'][0]['value'])->toBe(12000)
        ->and($report['reduced_lines'][0]['by'])->toBe('Kofi · approved by Akosua')
        ->and($report['totals']['reduced_lines'])->toBe(12000);
});

it('opens the manager keypad when the cashier taps minus after the bill', function () {
    Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('changeLineQuantity', $this->line->id, 1)->assertSet('approval', null) // plus: fine
        ->call('changeLineQuantity', $this->line->id, -1)
        ->assertSet('approval.permission', 'orders.void')->assertSee('Reduce Coca-Cola to 10 after the bill was printed')
        ->call('approve', '1111')->assertSet('approval.error', 'A manager PIN is required for this action.')
        ->call('approve', '2468')->assertSet('approval', null);

    expect($this->line->fresh()->quantity)->toBe(10);
});

it('sends a minus that would empty the line down the void path', function () {
    $single = $this->orders->addItem($this->order, $this->menu['water'], null, 1, [], null, $this->cashier);
    Livewire::test(OrderScreen::class, ['order' => $this->order])
        ->call('changeLineQuantity', $single->id, -1)->assertSet('modal', 'void');
    expect($single->fresh()->quantity)->toBe(1);
});

it('also logs and reports reductions before the bill was printed (security review N4)', function () {
    $order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $line = $this->orders->addItem($order, $this->menu['coke'], null, 3, [], null, $this->cashier);
    $this->orders->changeQuantity($order, $line, 1, $this->cashier); // no bill yet: no PIN needed, but now on record
    $this->orders->changeQuantity($order, $line->fresh(), 2, $this->cashier); // going up is not a reduction

    $practice = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $practice->forceFill(['is_training' => true])->save();
    $practiceLine = $this->orders->addItem($practice->fresh(), $this->menu['coke'], null, 3, [], null, $this->cashier);
    $this->orders->changeQuantity($practice->fresh(), $practiceLine, 1, $this->cashier); // practice orders stay out

    $report = app(ReportService::class)->voidsAndDiscounts(now()->toDateString(), now()->toDateString());
    expect($report['reduced_lines'])->toHaveCount(1)
        ->and($report['reduced_lines'][0]['detail'])->toBe('Coca-Cola 3 → 1')
        ->and($report['reduced_lines'][0]['value'])->toBe(2400)
        ->and($report['reduced_lines'][0]['by'])->toBe('Kofi');
});
