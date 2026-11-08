<?php

use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\RecipeLine;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\StockTakeService;
use App\Modules\Inventory\Services\UsageService;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Reports\Filament\Pages\FoodCostReport;
use App\Modules\Reports\Filament\Pages\RecipeUsageReport;
use App\Modules\Reports\Services\FoodCostService;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Features\FeatureService;
use App\Support\Tenancy\Tenancy;
use Livewire\Livewire;

/*
 * Complete Edition E5.3 — food cost and margin per dish and category, and theoretical (recipe) vs actual
 * usage after a stock take.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->cashier = User::factory()->forBranch($this->branch)->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->manager = User::factory()->forBranch($this->branch)->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->owner = Tenancy::runAs($this->branch->tenant, null, function () {
        $owner = User::factory()->create(['tenant_id' => $this->branch->tenant_id, 'branch_id' => null]);
        $owner->assignRole(Role::Owner->value);

        return $owner;
    });
    installTestLicence('business');
    app(FeatureService::class)->set('recipes', true, $this->owner);
    $this->orders = app(OrderService::class);
    $this->payments = app(PaymentService::class);

    // Jollof (Regular 45.00 incl. nothing: prices exclude tax): 200 g rice at 22.00/kg + 30 ml oil at 28.00/L = 4.40 + 0.84 = 5.24.
    $this->rice = Ingredient::create(['name' => 'Rice', 'unit' => 'kg', 'cost_per_unit' => 2200, 'on_hand' => 10]);
    $this->oil = Ingredient::create(['name' => 'Vegetable oil', 'unit' => 'l', 'cost_per_unit' => 2800, 'on_hand' => 5]);
    RecipeLine::create(['item_id' => $this->menu['jollof']->id, 'ingredient_id' => $this->rice->id, 'quantity' => 200, 'unit' => 'g']);
    RecipeLine::create(['item_id' => $this->menu['jollof']->id, 'ingredient_id' => $this->oil->id, 'quantity' => 30, 'unit' => 'ml']);
});

/** Sell and pay: $jollofs Regular Jollof (with chicken, +15.00) and $cokes Coca-Cola (no recipe). */
function sellAndPay(object $t, int $jollofs, int $cokes = 0): void
{
    $order = $t->orders->open(OrderType::Takeaway, $t->cashier);
    if ($jollofs) {
        $t->orders->addItem($order, $t->menu['jollof'], $t->menu['regular'], $jollofs, [$t->menu['chicken']->id => 1], null, $t->cashier);
    }
    if ($cokes) {
        $t->orders->addItem($order, $t->menu['coke'], null, $cokes, [], null, $t->cashier);
    }
    $order = $order->fresh();
    $t->payments->payCash($order, $order->balanceDue(), $t->cashier);
}

it('shows each dish\'s sales, ingredient cost, margin and food cost %, by dish and by category', function () {
    sellAndPay($this, 2, 3);
    $day = today()->toDateString();
    $report = app(FoodCostService::class)->report($day, $day);

    $jollof = collect($report['dishes'])->firstWhere('name', 'Jollof Rice (Regular)');
    $coke = collect($report['dishes'])->firstWhere('name', 'Coca-Cola');
    $net = $jollof['net']; // 2 × (45.00 + 15.00 chicken), without tax
    expect($jollof['quantity'])->toBe(2)
        ->and($jollof['cost'])->toBe(2 * 524)
        ->and($jollof['margin'])->toBe($net - 1048)
        ->and($jollof['food_cost_pct'])->toBe(round(1048 / $net * 100, 1))
        ->and($jollof['costed'])->toBe('all')
        ->and($coke['costed'])->toBe('none')->and($coke['food_cost_pct'])->toBeNull()
        ->and($report['totals']['cost'])->toBe(1048)
        ->and($report['totals']['costed_net'])->toBe($net)
        ->and($report['totals']['uncosted_net'])->toBe($coke['net'])
        ->and($report['totals']['uncosted_dishes'])->toBe(1)
        ->and(collect($report['categories'])->firstWhere('name', 'Rice')['cost'])->toBe(1048);
});

it('keeps the cost at the price when sold: later recipe or price changes do not rewrite the past', function () {
    sellAndPay($this, 1);
    RecipeLine::query()->update(['quantity' => 999]);
    $this->rice->forceFill(['cost_per_unit' => 99999])->save();

    $day = today()->toDateString();
    expect(collect(app(FoodCostService::class)->report($day, $day)['dishes'])->firstWhere('name', 'Jollof Rice (Regular)')['cost'])->toBe(524);
});

it('compares recipe usage with what a stock take shows was really used', function () {
    sellAndPay($this, 5); // recipes say: 1 kg rice, 0.15 L oil
    app(StockService::class)->waste($this->rice->fresh(), 0.5, $this->manager, 'Burnt pot');
    // Shelf now (system): rice 10 - 1 - 0.5 = 8.5 kg; oil 5 - 0.15 = 4.85 L.

    $takes = app(StockTakeService::class);
    $take = $takes->start($this->manager);
    foreach ($take->lines()->get() as $line) {
        $takes->count($line, match ($line->ingredient_id) {
            $this->rice->id => 8.0,   // 0.5 kg missing
            $this->oil->id => 4.85,   // exactly as expected
            default => null,
        }, $this->manager);
    }
    $takes->complete($take->fresh(), $this->manager);

    $report = app(UsageService::class)->report($take->fresh());
    $rice = collect($report['rows'])->firstWhere('name', 'Rice');
    $oil = collect($report['rows'])->firstWhere('name', 'Vegetable oil');
    expect($rice['recipe'])->toBe(1.0)->and($rice['waste'])->toBe(0.5)->and($rice['missing'])->toBe(0.5)
        ->and($rice['actual'])->toBe(2.0)->and($rice['missing_pct'])->toBe(50.0)
        ->and($rice['missing_value'])->toBe(1100)        // 0.5 kg × 22.00
        ->and($rice['since'])->toBeNull()                 // first count
        ->and($oil['recipe'])->toBe(0.15)->and($oil['missing'])->toBe(0.0)
        ->and($report['rows'][0]['name'])->toBe('Rice') // biggest loss first
        ->and($report['totals']['missing_value'])->toBe(1100);

    // The next stock take (a day later) only looks at what happened since this one.
    $this->travel(1)->days();
    sellAndPay($this, 1);
    $next = $takes->start($this->manager);
    foreach ($next->lines()->get() as $line) {
        $takes->count($line, $line->ingredient_id === $this->rice->id ? 7.8 : null, $this->manager); // exactly 0.2 kg used
    }
    $takes->complete($next->fresh(), $this->manager);
    $second = collect(app(UsageService::class)->report($next->fresh())['rows'])->firstWhere('name', 'Rice');
    expect($second['recipe'])->toBe(0.2)->and($second['missing'])->toBe(0.0)->and($second['since'])->toBe(today()->subDay()->format('d/m/Y'));
});

it('shows both reports in the back office only with "Recipes & food cost" switched on', function () {
    sellAndPay($this, 2);
    $this->actingAs($this->manager);
    $this->get(FoodCostReport::getUrl())->assertOk()->assertSee('Food cost &amp; margin', false);
    Livewire::test(FoodCostReport::class)->assertSee('Jollof Rice (Regular)')->assertSee('GH₵ 10.48')
        ->callAction('excel')->assertFileDownloaded();

    $this->get(RecipeUsageReport::getUrl())->assertOk()->assertSee('No completed stock takes yet');

    app(FeatureService::class)->set('recipes', false, $this->owner);
    $this->get(FoodCostReport::getUrl())->assertForbidden();
    $this->get(RecipeUsageReport::getUrl())->assertForbidden();
});

it('lets whoever manages the stock see recipe usage, but not sales margins', function () {
    $storekeeper = User::factory()->forBranch($this->branch)->create();
    $storekeeper->assignRole(Role::Inventory->value);
    $this->actingAs($storekeeper);

    $this->get(RecipeUsageReport::getUrl())->assertOk();
    $this->get(FoodCostReport::getUrl())->assertForbidden();
});
