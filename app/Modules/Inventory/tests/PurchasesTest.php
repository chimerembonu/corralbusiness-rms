<?php

use App\Modules\Inventory\Enums\PurchaseStatus;
use App\Modules\Inventory\Events\PurchaseReceived;
use App\Modules\Inventory\Exceptions\InventoryException;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Event;

it('goes from draft to ordered to received, posting stock and the last cost', function () {
    Event::fake([PurchaseReceived::class]);
    seedRoles();
    $branch = provisionTenant();
    $manager = User::factory()->forBranch($branch)->create();
    $manager->assignRole(Role::Manager->value);
    $supplier = Supplier::create(['name' => 'Makola Market Traders']);
    $rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10]);
    $tomatoes = Ingredient::create(['name' => 'Tomatoes', 'unit' => 'kg', 'cost_per_unit' => 1800, 'on_hand' => 5]);
    $service = app(PurchaseService::class);

    expect(fn () => $service->create($supplier, [], $manager))->toThrow(InventoryException::class, 'at least one');

    $purchase = $service->create($supplier, [
        ['ingredient_id' => $rice->id, 'quantity' => 50, 'unit_cost' => Money::fromDecimal('21.50')],
        ['ingredient_id' => $tomatoes->id, 'quantity' => 20],                                  // last cost 18.00
    ], $manager, 'Weekly run', '2026-11-05');

    expect($purchase->displayNumber())->toBe('PO0001')
        ->and($purchase->status)->toBe(PurchaseStatus::Draft)
        ->and($purchase->total)->toBePesewas(50 * 2150 + 20 * 1800)
        ->and($purchase->lines()->count())->toBe(2)
        ->and($purchase->supplier->name)->toBe('Makola Market Traders');

    $service->markOrdered($purchase, $manager);
    expect($purchase->fresh()->status)->toBe(PurchaseStatus::Ordered);

    // Only 45 kg of rice turned up.
    $riceLine = $purchase->lines()->where('ingredient_id', $rice->id)->first();
    $received = $service->receive($purchase, [$riceLine->id => 45], $manager, 'INV-77');

    expect($received->status)->toBe(PurchaseStatus::Received)
        ->and($received->reference)->toBe('INV-77')
        ->and($received->received_by_id)->toBe($manager->id)
        ->and($received->total)->toBePesewas(45 * 2150 + 20 * 1800)
        ->and((float) $riceLine->fresh()->quantity_received)->toBe(45.0)
        ->and((float) $rice->fresh()->on_hand)->toBe(55.0)
        ->and($rice->fresh()->cost_per_unit)->toBePesewas(2159) // average cost, the default: (10 kg × 22.00 + 45 kg × 21.50) ÷ 55
        ->and((float) $tomatoes->fresh()->on_hand)->toBe(25.0)
        ->and(app(StockService::class)->movements($rice)->first()->reason)->toBe('Received PO0001');
    Event::assertDispatched(PurchaseReceived::class);

    expect(fn () => $service->receive($received, [], $manager))->toThrow(InventoryException::class, 'already Received');
    expect(fn () => $service->cancel($received))->toThrow(InventoryException::class);

    $draft = $service->create(null, [['ingredient_id' => $rice->id, 'quantity' => 1]], $manager);
    $service->cancel($draft, $manager);
    expect($draft->fresh()->status)->toBe(PurchaseStatus::Cancelled)->and($draft->displayNumber())->toBe('PO0002');
});
