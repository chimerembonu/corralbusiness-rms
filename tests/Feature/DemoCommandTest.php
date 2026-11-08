<?php

use App\Console\Commands\DemoCommand;
use App\Modules\Inventory\Models\ModifierRecipeLine;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Menu\Models\Item;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Settings\Models\Tenant;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantScope;

it('loads the demo restaurant with menu and owner login, and can run twice', function () {
    $this->artisan('rms:demo', ['--password' => 'demo-pass-123'])->assertSuccessful();

    $tenant = Tenant::where('slug', DemoCommand::TENANT_SLUG)->firstOrFail();
    $owner = User::withoutGlobalScope(TenantScope::class)->where('email', 'chimere@corralsystems.com')->firstOrFail();

    Tenancy::set($tenant, $tenant->branches()->first());

    expect($owner->hasRole('owner'))->toBeTrue()
        ->and($owner->tenant_id)->toBe($tenant->id)
        ->and(Item::count())->toBeGreaterThan(25)
        ->and(Item::where('code', 'JOL')->first()->variants()->count())->toBe(2)
        ->and(Item::where('code', 'CMB1')->first()->comboItems()->count())->toBe(2)
        ->and(DiningTable::count())->toBe(12)
        ->and(RecipeLine::query()->where('item_id', Item::where('code', 'JOL')->value('id'))->count())->toBe(6) // demo recipes (E5.1)
        ->and(ModifierRecipeLine::query()->count())->toBeGreaterThan(5) // demo recipes for extras
        ->and(app(PinAuthenticator::class)->attempt('1234', 'till-1')->id)->toBe($owner->id);

    $items = Item::count();
    $recipeLines = RecipeLine::count();
    $extraLines = ModifierRecipeLine::count();
    $this->artisan('rms:demo')->assertSuccessful();

    expect(Item::count())->toBe($items)
        ->and(RecipeLine::count())->toBe($recipeLines)
        ->and(ModifierRecipeLine::count())->toBe($extraLines)
        ->and(Tenant::count())->toBe(1)
        ->and(User::withoutGlobalScope(TenantScope::class)->count())->toBe(11);

    Tenancy::forget();
});
