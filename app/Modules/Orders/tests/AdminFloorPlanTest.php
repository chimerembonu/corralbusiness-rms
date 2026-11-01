<?php

use App\Modules\Orders\Filament\Pages\FloorPlan;
use App\Modules\Orders\Filament\Resources\DiningTables\DiningTableResource;
use App\Modules\Orders\Filament\Resources\DiningTables\Pages\CreateDiningTable;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\FloorSection;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->indoor = FloorSection::create(['name' => 'Indoor', 'sort_order' => 1]);
    $this->terrace = FloorSection::create(['name' => 'Terrace', 'sort_order' => 2]);
    $this->t1 = DiningTable::create(['name' => 'T1', 'seats' => 4, 'section_id' => $this->indoor->id, 'pos_x' => 1, 'pos_y' => 1]);
    DiningTable::create(['name' => 'P1', 'seats' => 6, 'section_id' => $this->terrace->id]);
    $this->actingAs($this->owner);
});

it('opens the floor plan, sections and tables screens in the back office', function () {
    $this->get('/admin/floor-plan')->assertOk()->assertSee('Floor plan')->assertSee('T1')->assertSee('Indoor')->assertSee('Terrace');
    $this->get('/admin/floor-sections')->assertOk()->assertSee('Indoor');
    $this->get('/admin/dining-tables')->assertOk()->assertSee('T1')->assertSee('P1')->assertSee('Free');
    $this->get(DiningTableResource::getUrl('create'))->assertOk();
});

it('saves a dragged table position and switches sections', function () {
    Livewire::test(FloorPlan::class)
        ->assertSee('T1')->assertDontSee('P1')
        ->call('moveTable', $this->t1->id, 7, 4)
        ->call('selectSection', $this->terrace->id)
        ->assertSee('P1')->assertDontSee('T1');

    expect([$this->t1->fresh()->pos_x, $this->t1->fresh()->pos_y])->toBe([7, 4]);
});

it('adds a table from the floor plan and through the tables screen', function () {
    Livewire::test(FloorPlan::class)
        ->call('selectSection', $this->terrace->id)
        ->callAction('addTable', ['name' => 'P2', 'seats' => 4, 'shape' => 'round', 'width' => 2, 'height' => 2, 'is_active' => true, 'section_id' => $this->terrace->id])
        ->assertHasNoActionErrors()
        ->assertSee('P2');

    Livewire::test(CreateDiningTable::class)
        ->fillForm(['name' => 'T2', 'seats' => 8, 'shape' => 'rect', 'width' => 3, 'height' => 2, 'section_id' => $this->indoor->id, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    expect(DiningTable::query()->where('name', 'P2')->value('section_id'))->toBe($this->terrace->id)
        ->and(DiningTable::query()->where('name', 'T2')->first())->not->toBeNull()
        ->and(DiningTable::query()->where('name', 'T2')->first()->shape->value)->toBe('rect');
});
