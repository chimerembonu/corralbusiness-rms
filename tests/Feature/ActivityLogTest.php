<?php

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Models\TaxComponent;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Activity;

it('records changes to audited models with tenant and branch', function () {
    $branch = provisionTenant();
    $user = User::factory()->forBranch($branch)->create(['name' => 'Ama']);
    $this->actingAs($user);

    $user->update(['name' => 'Ama Serwaa']);

    $activity = Activity::query()->where('subject_type', User::class)->where('event', 'updated')->sole();

    expect($activity->tenant_id)->toBe($branch->tenant_id)
        ->and($activity->branch_id)->toBe($branch->id)
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Ama Serwaa')
        ->and($activity->attribute_changes['old']['name'])->toBe('Ama');
});

it('never writes PINs or passwords into the audit log', function () {
    $branch = provisionTenant();
    $user = User::factory()->forBranch($branch)->withPin('1234')->create();

    $user->update(['pin' => '5678', 'password' => 'new-secret']);

    foreach (Activity::query()->where('subject_type', User::class)->get() as $activity) {
        expect(json_encode($activity->attribute_changes))->not->toContain('pin', 'password')
            ->and(json_encode($activity->properties))->not->toContain('pin', 'password');
    }
});

it('logs tax rate changes', function () {
    provisionTenant();
    TaxComponent::where('code', 'VAT')->first()->update(['rate_basis_points' => 1250]);

    expect(Activity::query()->where('log_name', 'tax_component')->where('event', 'updated')->exists())->toBeTrue();
});

it('logs order lines and stock movements', function () {
    seedRoles();
    $branch = provisionTenant();
    $menu = demoMenu();
    $manager = User::factory()->forBranch($branch)->create();
    $manager->assignRole(Role::Manager->value);
    $this->actingAs($manager);

    $orders = app(OrderService::class);
    $order = $orders->open(OrderType::Takeaway, $manager);
    $line = $orders->addItem($order, $menu['coke'], null, 2, [], null, $manager);
    $orders->voidItem($order, $line, 'Wrong item', $manager, $manager);

    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10, 'reorder_level' => 5]);
    app(StockService::class)->waste($rice, 2, $manager, 'Spoiled');

    $created = Activity::query()->where('log_name', 'order_item')->where('subject_id', $line->id)->where('event', 'created')->sole();
    $voided = Activity::query()->where('log_name', 'order_item')->where('subject_id', $line->id)->where('event', 'updated')->latest('id')->first();
    $movement = StockMovement::query()->where('ingredient_id', $rice->id)->sole();
    $logged = Activity::query()->where('log_name', 'stock_movement')->where('subject_id', $movement->id)->where('event', 'created')->sole();

    expect($created->attribute_changes['attributes']['quantity'])->toBe(2)
        ->and($created->causer_id)->toBe($manager->id)
        ->and($voided->attribute_changes['attributes']['status'])->toBe('voided')
        ->and($logged->attribute_changes['attributes']['reason'])->toBe('Spoiled')
        ->and($logged->branch_id)->toBe($branch->id);
});
