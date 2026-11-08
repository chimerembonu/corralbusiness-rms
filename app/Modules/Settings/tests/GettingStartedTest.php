<?php

use App\Modules\Menu\Models\Category;
use App\Modules\Menu\Models\Item;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Pos\Livewire\OrderScreen;
use App\Modules\Printing\Models\Printer;
use App\Modules\Settings\Filament\Widgets\GettingStartedWidget;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * After the setup wizard (install rehearsal, 8 Nov 2026): a new owner gets a "Getting started" list on the
 * back-office home page, and an empty till explains where the menu comes from.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = Tenancy::runAs($this->branch->tenant, null, function () {
        $owner = User::factory()->create(['tenant_id' => $this->branch->tenant_id, 'branch_id' => null, 'name' => 'Efua']);
        $owner->assignRole(Role::Owner->value);

        return $owner;
    });
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows a new restaurant what is left to do, ticking steps off as they are done', function () {
    $this->actingAs($this->owner);
    $this->get('/admin')->assertOk()->assertSee('Getting started')->assertSee('Add your menu')->assertSee('Make a practice sale');

    $done = fn () => collect((new GettingStartedWidget)->steps())->where('done', true)->pluck('key')->all();
    expect($done())->not->toContain('menu')->not->toContain('staff')->not->toContain('printers')->not->toContain('practice');

    $category = Category::create(['name' => 'Rice']);
    Item::create(['category_id' => $category->id, 'name' => 'Jollof', 'price' => 4500]);
    $cashier = User::factory()->forBranch($this->branch)->create();
    $cashier->assignRole(Role::Cashier->value);
    Printer::create(['name' => 'Counter', 'driver' => 'browser', 'prints_receipts' => true]);
    expect($done())->toContain('menu')->toContain('staff')->toContain('printers')->not->toContain('practice');
});

it('disappears once the needed steps are done, or when hidden', function () {
    $this->actingAs($this->owner);
    Livewire::test(GettingStartedWidget::class)->call('hide')->assertSet('hidden', true);
    expect(GettingStartedWidget::canView())->toBeFalse();
    $this->get('/admin')->assertOk()->assertDontSee('Getting started');
});

it('is not shown to staff who cannot change settings', function () {
    $accountant = User::factory()->forBranch($this->branch)->create();
    $accountant->assignRole(Role::Accountant->value);
    $this->actingAs($accountant);
    expect(GettingStartedWidget::canView())->toBeFalse();
});

it('tells the till where the menu comes from while there is none', function () {
    $manager = User::factory()->forBranch($this->branch)->create();
    $manager->assignRole(Role::Manager->value);
    $this->actingAs($manager);
    $order = app(OrderService::class)->open(OrderType::Takeaway, $manager);

    Livewire::test(OrderScreen::class, ['order' => $order])->assertSee('There is no menu yet');
});
