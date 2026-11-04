<?php

use App\Modules\Inventory\Filament\Resources\Ingredients\IngredientResource;
use App\Modules\Kitchen\Filament\Pages\KitchenRouting;
use App\Modules\Kitchen\Filament\Resources\Stations\StationResource;
use App\Modules\Menu\Filament\Resources\Categories\CategoryResource;
use App\Modules\Menu\Filament\Resources\Items\ItemResource;
use App\Modules\Menu\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Modules\Orders\Filament\Pages\FloorPlan;
use App\Modules\Orders\Filament\Resources\DiningTables\DiningTableResource;
use App\Modules\Orders\Filament\Resources\FloorSections\FloorSectionResource;
use App\Modules\Orders\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Modules\Payments\Filament\Resources\CashierSessions\CashierSessionResource;
use App\Modules\Payments\Filament\Resources\DayCloses\DayCloseResource;
use App\Modules\Pos\Filament\Resources\Tills\TillResource;
use App\Modules\Printing\Filament\Resources\Printers\PrinterResource;
use App\Modules\Printing\Filament\Resources\PrintJobs\PrintJobResource;
use App\Modules\Reports\Filament\Pages\SalesReport;
use App\Modules\Settings\Filament\Pages\ReceiptSettingsPage;
use App\Modules\Settings\Filament\Pages\TillSettingsPage;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;

/*
 * Security review M6: the Inventory and Accountant roles may open /admin, and without per-resource
 * checks they could edit menu prices, create promo codes and change the discount threshold. Every
 * back-office screen now says who may open it.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->users = [];
    foreach ([Role::Owner, Role::Manager, Role::Inventory, Role::Accountant] as $role) {
        $user = User::factory()->forBranch($this->branch)->create(['name' => ucfirst($role->value)]);
        $user->assignRole($role->value);
        $this->users[$role->value] = $user;
    }
    Tenancy::forget();
});

/** A fresh request as this user: new session, no tenant context left over from the previous user. */
function openAdminAs(User $user): void
{
    test()->flushSession();
    Tenancy::forget();
    test()->actingAs($user);
}

function managerOnlyScreens(): array
{
    return [
        'menu items' => ItemResource::getUrl(),
        'new menu item' => ItemResource::getUrl('create'),
        'categories' => CategoryResource::getUrl(),
        'modifier groups' => ModifierGroupResource::getUrl(),
        'promo codes' => PromoCodeResource::getUrl(),
        'new promo code' => PromoCodeResource::getUrl('create'),
        'stations' => StationResource::getUrl(),
        'kitchen routing' => KitchenRouting::getUrl(),
        'floor plan' => FloorPlan::getUrl(),
        'tables' => DiningTableResource::getUrl(),
        'floor sections' => FloorSectionResource::getUrl(),
        'tills' => TillResource::getUrl(),
        'printers' => PrinterResource::getUrl(),
        'print jobs' => PrintJobResource::getUrl(),
        'till settings' => TillSettingsPage::getUrl(),
        'receipt settings' => ReceiptSettingsPage::getUrl(),
    ];
}

it('keeps Inventory and Accountant users out of the menu, promo, setup and settings screens', function () {
    foreach ([Role::Inventory, Role::Accountant] as $role) {
        openAdminAs($this->users[$role->value]);
        foreach (managerOnlyScreens() as $name => $url) {
            $this->get($url)->assertForbidden();
        }
    }
});

it('still lets owners and managers open every one of those screens', function () {
    foreach ([Role::Owner, Role::Manager] as $role) {
        openAdminAs($this->users[$role->value]);
        foreach (managerOnlyScreens() as $name => $url) {
            $this->get($url)->assertOk();
        }
    }
});

it('keeps what those roles are for: stock for Inventory, reports and cash-ups for the Accountant', function () {
    openAdminAs($this->users[Role::Inventory->value]);
    $this->get(IngredientResource::getUrl())->assertOk();
    $this->get(SalesReport::getUrl())->assertForbidden(); // no sales or cash figures for the storekeeper (owner, 4 Nov 2026)
    $this->get(CashierSessionResource::getUrl())->assertForbidden();

    openAdminAs($this->users[Role::Accountant->value]);
    $this->get(SalesReport::getUrl())->assertOk();
    $this->get(CashierSessionResource::getUrl())->assertOk();
    $this->get(DayCloseResource::getUrl())->assertOk();
    $this->get(IngredientResource::getUrl())->assertForbidden();
});

it('hides the guarded screens from the navigation of users who may not open them', function () {
    openAdminAs($this->users[Role::Accountant->value]);
    $this->get('/admin')->assertOk()->assertDontSee('Promo codes')->assertDontSee('Till settings')->assertSee('Sales');
});
