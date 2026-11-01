<?php

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Menu\Models\Item;
use App\Modules\Settings\Filament\Pages\ImportPage;
use App\Modules\Settings\Services\Import\Importers;
use App\Modules\Settings\Services\Import\ImportTemplates;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->dir = storage_path('app/import-tests');
    File::ensureDirectoryExists($this->dir);
});

afterEach(fn () => File::deleteDirectory(storage_path('app/import-tests')));

function csv(string $name, array $headings, array $rows): string
{
    $path = test()->dir.'/'.$name.'.csv';
    $h = fopen($path, 'w');
    fputcsv($h, $headings);
    foreach ($rows as $row) {
        fputcsv($h, $row);
    }
    fclose($h);

    return $path;
}

it('imports menu items with sizes from a spreadsheet, previews first, and updates on re-import', function () {
    $file = csv('menu', ImportTemplates::headings('menu'), [
        ['Rice', 'Jollof Rice', 'JOL', '', 'Regular', '45.00', 'With chicken', 'yes', 'popular, spicy', 'no', ''],
        ['Rice', 'Jollof Rice', 'JOL', '', 'Large', '60', '', 'yes', '', 'no', ''],
        ['Drinks', 'Club Beer', 'CLB', 'GHS 15.00', '', '', '', 'yes', '', 'yes', '48'],
        ['Drinks', 'Bottled Water', '', '5', '', '', '', 'no', '', 'yes', '120'],
        ['', 'Mystery', '', '10', '', '', '', '', '', '', ''],
        ['Rice', 'No price', '', '', '', '', '', '', '', '', ''],
    ]);

    $preview = Importers::preview('menu', $file);
    expect($preview->count('new'))->toBe(3)->and($preview->count('error'))->toBe(2)->and(Item::query()->count())->toBe(0) // preview changes nothing
        ->and($preview->summary())->toBe('3 new, 2 errors');

    $result = Importers::import('menu', $file);
    expect($result->count('new'))->toBe(3);
    $jollof = Item::query()->where('code', 'JOL')->with(['variants', 'category'])->first();
    expect($jollof->category->name)->toBe('Rice')->and($jollof->price)->toBePesewas(4500)->and($jollof->tags)->toBe(['popular', 'spicy'])
        ->and($jollof->variants->pluck('name')->all())->toBe(['Regular', 'Large'])->and($jollof->variants->firstWhere('name', 'Large')->price)->toBePesewas(6000)
        ->and($jollof->variants->firstWhere('is_default', true)->name)->toBe('Regular');
    $beer = Item::query()->where('code', 'CLB')->first();
    expect($beer->price)->toBePesewas(1500)->and($beer->track_stock)->toBeTrue()->and((float) $beer->stock_qty)->toBe(48.0);
    expect(Item::query()->where('name', 'Bottled Water')->first()->taxable)->toBeFalse();

    // Re-import with a new price and a renamed size: items are updated, not duplicated.
    $again = csv('menu2', ImportTemplates::headings('menu'), [
        ['Rice', 'Jollof Rice', 'JOL', '', 'Regular', '50', '', 'yes', '', 'no', ''],
        ['Drinks', 'Club Beer', 'CLB', '16', '', '', '', 'yes', '', 'yes', '999'],
    ]);
    $result = Importers::import('menu', $again);
    expect($result->count('update'))->toBe(2)->and(Item::query()->count())->toBe(3)
        ->and($jollof->fresh()->price)->toBePesewas(5000)->and($jollof->variants()->count())->toBe(2)
        ->and($beer->fresh()->price)->toBePesewas(1600)->and((float) $beer->fresh()->stock_qty)->toBe(48.0); // stock not overwritten
});

it('imports ingredients with suppliers and opening stock', function () {
    $file = csv('ingredients', ImportTemplates::headings('ingredients'), [
        ['Long-grain rice', 'ING001', 'Dry goods', 'kg', 'Bag', '25', '22.00', '80', '25', 'Makola Market Traders'],
        ['Eggs', '', 'Dairy & eggs', 'pcs', 'Crate', '30', '2.50', '180', '60', ''],
        ['Bad unit', '', '', 'bucket', '', '', '1', '1', '0', ''],
    ]);
    $result = Importers::import('ingredients', $file);
    expect($result->count('new'))->toBe(2)->and($result->count('error'))->toBe(1)->and($result->rows[2]['message'])->toContain('bucket');

    $rice = Ingredient::query()->where('code', 'ING001')->first();
    expect($rice->unit->value)->toBe('kg')->and($rice->cost_per_unit)->toBePesewas(2200)->and((float) $rice->on_hand)->toBe(80.0)
        ->and($rice->packLabel())->toBe('Bag of 25 kg')->and($rice->supplier->name)->toBe('Makola Market Traders')
        ->and($rice->movements()->count())->toBe(1)->and(Supplier::query()->count())->toBe(1);

    $result = Importers::import('ingredients', csv('ingredients2', ImportTemplates::headings('ingredients'), [['Long-grain rice', 'ING001', 'Dry goods', 'kg', '', '', '24', '999', '30', '']]));
    expect($result->count('update'))->toBe(1)->and((float) $rice->fresh()->on_hand)->toBe(80.0)->and($rice->fresh()->cost_per_unit)->toBePesewas(2400)->and((float) $rice->fresh()->reorder_level)->toBe(30.0);
});

it('imports staff with roles and PINs and refuses owners, bad roles and duplicate PINs', function () {
    $existing = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua Mensah']);
    $existing->assignRole(Role::Manager->value);

    $file = csv('staff', ImportTemplates::headings('staff'), [
        ['Kofi Boateng', '024 100 0001', 'cashier', '1001', 'Senior cashier', '', '2026-01-15'],
        ['Akosua Mensah', '024 100 0002', 'manager, cashier', '', 'Restaurant manager', '', ''],
        ['Yaw Asante', '024 100 0003', 'waiter', '2468', '', '', ''],
        ['Nana', '', 'owner', '3333', '', '', ''],
        ['Ama', '', 'chef', '4444', '', '', ''],
        ['No pin', '', 'waiter', '', '', '', ''],
    ]);
    $result = Importers::import('staff', $file);
    expect($result->count('new'))->toBe(1)->and($result->count('update'))->toBe(1)->and($result->count('error'))->toBe(4);

    $kofi = User::query()->where('name', 'Kofi Boateng')->first();
    expect($kofi->getRoleNames()->all())->toBe(['cashier'])->and($kofi->job_title)->toBe('Senior cashier')->and($kofi->started_on->toDateString())->toBe('2026-01-15')
        ->and(app(PinAuthenticator::class)->attempt('1001', 'till:test')->id)->toBe($kofi->id)
        ->and($existing->fresh()->getRoleNames()->sort()->values()->all())->toBe(['cashier', 'manager'])->and($existing->fresh()->phone)->toBe('024 100 0002')
        ->and(User::query()->count())->toBe(2);
});

it('shows the import page and downloads templates', function () {
    $owner = User::factory()->forBranch($this->branch)->create();
    $owner->assignRole(Role::Owner->value);
    $this->actingAs($owner);

    $this->get('/admin/import')->assertOk()->assertSee('Import data')->assertSee('Download template');
    Excel::fake();
    Livewire::test(ImportPage::class)->callAction('template')->assertHasNoErrors();
    Excel::assertDownloaded('corralbusiness-menu-template.xlsx', fn ($export) => $export->headings()[0] === 'Category' && count($export->array()) === 4);
});

it('imports from the command line with a dry run first', function () {
    $file = csv('cli', ImportTemplates::headings('menu'), [['Drinks', 'Sobolo', 'SOB', '8', '', '', '', 'yes', '', 'no', '']]);
    $this->artisan('rms:import', ['type' => 'menu', 'file' => $file, '--dry-run' => true])->expectsOutputToContain('Preview: 1 new')->assertExitCode(0);
    expect(Item::query()->count())->toBe(0);
    $this->artisan('rms:import', ['type' => 'menu', 'file' => $file])->expectsOutputToContain('Imported: 1 new')->assertExitCode(0);
    expect(Item::query()->where('code', 'SOB')->exists())->toBeTrue();
    $this->artisan('rms:import', ['type' => 'nonsense', 'file' => $file])->assertExitCode(1);
});
