<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Filament\Pages\PaymentProvidersPage;
use App\Modules\Payments\Gateways\GatewayManager;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Livewire\PaymentScreen;
use App\Modules\Pos\Models\Till;
use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/* Complete Edition E2: the cashier sends a MoMo prompt from the payment screen; the sale finishes by itself when approved. */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    app(CurrentTill::class)->remember($till);
    $orders = app(OrderService::class);
    app(CashService::class)->open($till, $this->cashier, Money::of(5000), $orders->businessDate());
    $this->order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($this->order, $this->menu['coke'], null, 2, [], null, $this->cashier);
    $this->order->forceFill(['is_training' => true])->save(); // practise with the Demo provider
    installTestLicence('business'); // features need a licence that covers them (E1.2)

    $connections = app(ConnectionService::class);
    $connections->save('demo_payments', ['mode' => 'demo']);
    $connections->test('demo_payments');
});

it('hides the prompt button until the owner switches the feature on', function () {
    $this->actingAs($this->cashier);
    Livewire::test(PaymentScreen::class, ['order' => $this->order])->call('setMethod', 'momo')->assertDontSee('Send payment prompt to phone');

    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    Livewire::test(PaymentScreen::class, ['order' => $this->order])->call('setMethod', 'momo')->assertSee('Send payment prompt to phone');
});

it('sends a prompt, waits, and finishes the sale by itself when the customer approves', function () {
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    $this->actingAs($this->cashier);

    $screen = Livewire::test(PaymentScreen::class, ['order' => $this->order])
        ->call('setMethod', 'momo')->call('setProvider', 'mtn')
        ->set('amount', $this->order->fresh()->balanceDue()->toDecimal())
        ->set('phone', '0241234567')
        ->call('sendPrompt')
        ->assertSee('Waiting for the customer to approve')->assertSee('024•••4567');

    $screen->call('checkPrompt')->assertSee('Waiting for the customer');
    $this->travel(6)->seconds();
    $screen->call('checkPrompt')->assertRedirect(route('pos.receipt', [$this->order, 'print' => 1]));

    expect(Payment::query()->sole()->reference)->not->toBeNull()->and($this->order->fresh()->isPaid())->toBeTrue();
});

it('shows a decline and lets the cashier stop waiting', function () {
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    $this->actingAs($this->cashier);
    $due = $this->order->fresh()->balanceDue()->toDecimal();

    Livewire::test(PaymentScreen::class, ['order' => $this->order])
        ->call('setMethod', 'momo')->call('setProvider', 'mtn')->set('amount', $due)
        ->set('phone', '0240000000')->call('sendPrompt')->assertSee('Not paid')->assertSee('declined')
        ->call('dismissPrompt')
        ->set('phone', '0241111111')->call('sendPrompt')->assertSee('Waiting')
        ->call('stopWaiting')->assertSee('Send payment prompt to phone');
    expect(Payment::query()->count())->toBe(0);
});

it('lets only the owner choose the default provider', function () {
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => []])]);
    $c = app(ConnectionService::class);
    $c->save('paystack', ['mode' => 'test', 'public_key' => 'pk_test', 'secret_key' => 'sk_test_x']);
    $c->test('paystack');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $manager = User::factory()->forBranch($this->branch)->create();
    $manager->assignRole(Role::Manager->value);
    $this->actingAs($manager)->get(PaymentProvidersPage::getUrl())->assertForbidden();

    $this->actingAs($this->owner);
    Livewire::test(PaymentProvidersPage::class)->assertSee('Paystack')->assertSee('PaySwitch')->assertSee('Nothing needs attention')
        ->set('defaults.till_momo', 'paystack')->call('save');
    expect(app(GatewayManager::class)->defaultFor('till_momo'))->toBe('paystack');
});
