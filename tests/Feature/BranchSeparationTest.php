<?php

use App\Modules\Inventory\Enums\Unit;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Kitchen\Models\Station;
use App\Modules\Orders\Filament\Resources\DiningTables\Pages\CreateDiningTable;
use App\Modules\Orders\Models\FloorSection;
use App\Modules\Pos\Filament\Resources\Tills\Pages\CreateTill;
use App\Modules\Pos\Filament\Resources\Tills\Pages\ListTills;
use App\Modules\Pos\Filament\Resources\Tills\TillResource;
use App\Modules\Pos\Models\Till;
use App\Modules\Printing\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Modules\Printing\Filament\Resources\Printers\PrinterResource;
use App\Modules\Printing\Models\Printer;
use App\Modules\Settings\Models\Branch;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Resources\Staff\Pages\ListStaff;
use App\Modules\Staff\Models\User;
use App\Support\Filament\BranchResource;
use App\Support\Tenancy\Tenancy;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;

/*
 * Complete Edition E3.1 (security review D2): with two branches, the back office and the kitchen screens of
 * one branch never show or reach the other branch's records. Across branches is head office (/hq).
 */
beforeEach(function () {
    seedRoles();
    $this->osu = provisionTenant('Mama Efua Chop Bar');
    $this->tenant = $this->osu->tenant;
    $this->airport = Tenancy::runAs($this->tenant, null, fn () => Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Airport', 'code' => 'AIR', 'timezone' => 'Africa/Accra']));

    $this->osuManager = User::factory()->forBranch($this->osu)->create(['name' => 'Akosua (Osu)']);
    $this->osuManager->assignRole(Role::Manager->value);
    $this->airportManager = User::factory()->forBranch($this->airport)->create(['name' => 'Yaw (Airport)']);
    $this->airportManager->assignRole(Role::Manager->value);
    $this->owner = Tenancy::runAs($this->tenant, null, function () {
        $owner = User::factory()->create(['name' => 'Efua (owner)', 'tenant_id' => $this->tenant->id, 'branch_id' => null]);
        $owner->assignRole(Role::Owner->value);

        return $owner;
    });

    // Each branch has its own till, printer, station, floor section and ingredient.
    $this->made = [];
    foreach (['osu' => $this->osu, 'airport' => $this->airport] as $key => $branch) {
        $this->made[$key] = Tenancy::runAs($this->tenant, $branch, fn () => [
            'till' => Till::create(['name' => "Till {$branch->name}", 'code' => 'T1']),
            'printer' => Printer::create(['name' => "Printer {$branch->name}", 'driver' => 'browser']),
            'station' => Station::create(['name' => "Grill {$branch->name}", 'code' => 'GRILL']),
            'section' => FloorSection::create(['name' => "Terrace {$branch->name}"]),
            'ingredient' => Ingredient::create(['name' => "Rice {$branch->name}", 'unit' => Unit::Kilogram, 'cost_per_unit' => 1000, 'on_hand' => 10]),
        ]);
    }
});

it('makes every back-office list of branch records stick to its own branch', function () {
    $restaurantWide = [];
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $model = new ($resource::getModel());
        $belongsToBranch = ! property_exists($model, 'hasBranch') || (fn () => $this->hasBranch)->call($model);
        if ($belongsToBranch) {
            expect(is_subclass_of($resource, BranchResource::class))->toBeTrue("{$resource} lists branch records: make it extend BranchResource");
        } else {
            $restaurantWide[] = class_basename($resource);
        }
    }

    // The menu, customers, suppliers and promo codes belong to the whole restaurant and stay shared.
    expect($restaurantWide)->toEqualCanonicalizing(['CustomerResource', 'SupplierResource', 'CategoryResource', 'ItemResource', 'ModifierGroupResource', 'PromoCodeResource']);
});

it('shows a manager only their own branch\'s records, and "not found" for the other branch\'s', function () {
    $this->actingAs($this->osuManager);
    Tenancy::set($this->tenant, $this->osu); // as SetTenantFromUser does for this manager

    Livewire::test(ListTills::class)
        ->assertCanSeeTableRecords([$this->made['osu']['till']])
        ->assertCanNotSeeTableRecords([$this->made['airport']['till']]);

    // Opening the other branch's records by their address: not found.
    $this->get(TillResource::getUrl('edit', ['record' => $this->made['airport']['till']]))->assertNotFound();
    $this->get(PrinterResource::getUrl('edit', ['record' => $this->made['airport']['printer']]))->assertNotFound();
    $this->get(TillResource::getUrl('edit', ['record' => $this->made['osu']['till']]))->assertOk();

    // Every branch list, all at once: nothing of the Airport branch.
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (is_subclass_of($resource, BranchResource::class)) {
            expect($resource::getEloquentQuery()->where($resource::getModel()::make()->qualifyColumn('branch_id'), $this->airport->id)->count())->toBe(0, $resource);
        }
    }

    // Drop-downs in forms only offer this branch's printers, stations and floor sections — and the printer
    // form has no branch picker any more (a printer belongs to the branch it is added in).
    $only = fn (array $names) => fn (Select $field) => array_values($field->getOptions()) === $names;
    Livewire::test(CreateTill::class)->assertFormFieldExists('receipt_printer_id', $only([$this->made['osu']['printer']->name]));
    Livewire::test(CreateDiningTable::class)->assertFormFieldExists('section_id', $only([$this->made['osu']['section']->name]));
    Livewire::test(CreatePrinter::class)->fillForm(['prints_kitchen_tickets' => true])
        ->assertFormFieldExists('station_ids', $only([$this->made['osu']['station']->name]))->assertFormFieldDoesNotExist('branch_id');
});

it('lists this branch\'s staff plus the restaurant\'s owner, but not other branches\' staff', function () {
    $this->actingAs($this->osuManager);
    Tenancy::set($this->tenant, $this->osu);

    Livewire::test(ListStaff::class)
        ->assertCanSeeTableRecords([$this->osuManager, $this->owner])
        ->assertCanNotSeeTableRecords([$this->airportManager]);
});

it('records made in the back office land in the branch being worked in', function () {
    $this->actingAs($this->airportManager);
    Tenancy::set($this->tenant, $this->airport);

    Livewire::test(CreatePrinter::class)
        ->fillForm(['name' => 'Bar printer', 'driver' => 'browser', 'paper_width' => 80])
        ->call('create')->assertHasNoFormErrors();

    expect(Printer::query()->withoutGlobalScopes()->where('name', 'Bar printer')->sole()->branch_id)->toBe($this->airport->id);
});

it('lets kitchen screens listen only to their own branch\'s kitchen', function () {
    $channel = Broadcast::driver()->getChannels()->get('kitchen.{branchId}');
    $kitchen = fn (Branch $branch) => tap(User::factory()->forBranch($branch)->create(), fn ($u) => $u->assignRole(Role::Kitchen->value));
    $osuCook = $kitchen($this->osu);

    expect($channel($osuCook, $this->osu->id))->toBeTrue()
        ->and($channel($osuCook, $this->airport->id))->toBeFalse()       // another branch's kitchen
        ->and($channel($this->owner, $this->airport->id))->toBeTrue()    // the owner belongs to the whole restaurant
        ->and($channel($this->owner, 'not-a-branch'))->toBeFalse();

    $otherRestaurant = provisionTenant('Another Restaurant');
    expect($channel($this->owner, $otherRestaurant->id))->toBeFalse();  // never another restaurant's kitchen
});
