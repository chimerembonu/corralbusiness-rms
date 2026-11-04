<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Gateways\GatewayManager;
use App\Modules\Payments\Gateways\GatewayPaymentService;
use App\Modules\Payments\Models\GatewayTransaction;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Http;

/*
 * Complete Edition E2: taking money through a provider, whichever provider it is. Paid once, never lost,
 * practice never touches real money, and switching the default provider never loses a payment on its way.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->orders = app(OrderService::class);
    $this->till = Till::create(['name' => 'Till 1', 'code' => 'T1']);
    app(CashService::class)->open($this->till, $this->cashier, Money::of(5000), $this->orders->businessDate());
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke'], null, 2, [], null, $this->cashier);
    $this->due = $this->order->fresh()->balanceDue();

    config(['features.features.momo_prompt.available' => true]);
    $this->connections = app(ConnectionService::class);
    $this->service = app(GatewayPaymentService::class);
    $this->verify = 'pending';
    Http::fake([
        'api.paystack.co/balance' => Http::response(['status' => true, 'data' => []]),
        'api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'X', 'status' => 'pay_offline', 'display_text' => 'Approve on your phone']]),
        'api.paystack.co/transaction/verify/*' => fn () => Http::response(['status' => true, 'data' => ['status' => $this->verify, 'amount' => $this->paidAmount ?? $this->due->amount]]),
        'test.theteller.net/v1.1/users/transactions/*' => Http::response(['code' => '404', 'reason' => 'not found']),
    ]);
});

function readyProvider(string $connection, array $values): void
{
    $c = app(ConnectionService::class);
    $c->save($connection, $values);
    expect($c->test($connection)->ok)->toBeTrue();
}

function paystackReady(): void
{
    readyProvider('paystack', ['mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_abc']);
}

it('refuses while the feature is off, and when no provider is ready', function () {
    paystackReady();
    expect(fn () => $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier))->toThrow(PaymentException::class, 'switched off');

    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    $this->connections->remove('paystack');
    expect(fn () => $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier))->toThrow(PaymentException::class, 'switched off'); // needs a provider again
});

it('sends a prompt, records the payment exactly once when approved, and keeps the phone masked', function () {
    paystackReady();
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);

    $tx = $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '024 123 4567', $this->due, $this->cashier);
    expect($tx->status)->toBe(GatewayStatus::Pending)->and($tx->gateway)->toBe('paystack')->and($tx->phone_masked)->toBe('024•••4567')
        ->and(GatewayTransaction::query()->where('phone_masked', 'like', '%1234567%')->exists())->toBeFalse();

    // A second tap while waiting does not send a second prompt.
    expect(fn () => $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier))->toThrow(PaymentException::class, 'already sent');

    expect($this->service->refresh($tx)->status)->toBe(GatewayStatus::Pending);
    $this->verify = 'success';
    $this->service->refresh($tx);
    $this->service->refresh($tx); // checking again never pays twice

    $payment = Payment::query()->sole();
    expect($tx->fresh()->status)->toBe(GatewayStatus::Succeeded)->and($tx->fresh()->payment_id)->toBe($payment->id)
        ->and($payment->method)->toBe(PaymentMethod::MobileMoney)->and($payment->provider)->toBe(MomoProvider::Mtn)
        ->and($payment->amount->amount)->toBe($this->due->amount)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('rejects numbers that are not Ghana mobile numbers', function () {
    paystackReady();
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    expect(fn () => $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '12345', $this->due, $this->cashier))->toThrow(PaymentException::class, 'Mobile Money number');
    expect(GatewayPaymentService::normalisePhone('+233 24 123 4567'))->toBe('0241234567');
});

it('still records money that arrives after the cashier stopped waiting, via the background check', function () {
    paystackReady();
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    $tx = $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier);
    $this->service->cancel($tx, $this->cashier);
    expect($tx->fresh()->status)->toBe(GatewayStatus::Cancelled);

    $this->verify = 'success';
    Tenancy::forget();
    $this->artisan('rms:payments:check')->assertSuccessful();

    Tenancy::set($this->branch->tenant, $this->branch);
    expect($tx->fresh()->status)->toBe(GatewayStatus::Succeeded)->and(Payment::query()->count())->toBe(1);
});

it('flags money it cannot attach (order paid in cash meanwhile, or a different amount) instead of losing it', function () {
    paystackReady();
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    $tx = $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier);
    $this->service->cancel($tx, $this->cashier);
    app(PaymentService::class)->payCash($this->order->fresh(), $this->due, $this->cashier);

    $this->verify = 'success';
    $this->service->refresh($tx);
    expect($tx->fresh()->status)->toBe(GatewayStatus::NeedsAttention)->and(Payment::query()->count())->toBe(1); // only the cash

    $other = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($other, $this->menu['coke'], null, 1, [], null, $this->cashier);
    $this->verify = 'pending';
    $tx2 = $this->service->startMomoPrompt($other, MomoProvider::Mtn, '0241234567', $other->fresh()->balanceDue(), $this->cashier);
    $this->verify = 'success';
    $this->paidAmount = 1; // provider says 1 pesewa was paid
    $this->service->refresh($tx2);
    expect($tx2->fresh()->status)->toBe(GatewayStatus::NeedsAttention)->and($tx2->fresh()->message)->toContain('different amount');
});

it('keeps practice and real money apart: training orders use only the Demo provider, real orders never do', function () {
    readyProvider('demo_payments', ['mode' => 'demo']);
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);

    // Real order, only Demo ready: refused.
    expect(fn () => $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier))->toThrow(PaymentException::class, 'No payment provider is ready');

    // Training order: Demo works, approves after ~5 seconds.
    $practice = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $practice->forceFill(['is_training' => true])->save();
    $this->orders->addItem($practice, $this->menu['coke'], null, 1, [], null, $this->cashier);
    $tx = $this->service->startMomoPrompt($practice->fresh(), MomoProvider::Mtn, '0241234567', $practice->fresh()->balanceDue(), $this->cashier);
    expect($tx->gateway)->toBe('demo');
    $this->travel(6)->seconds();
    expect($this->service->refresh($tx)->status)->toBe(GatewayStatus::Succeeded)->and(Payment::query()->sole()->is_training)->toBeTrue();

    // With Paystack also ready, a training order still never goes to Paystack.
    paystackReady();
    expect(app(GatewayManager::class)->forPurpose('till_momo', training: true)->key())->toBe('demo')
        ->and(app(GatewayManager::class)->forPurpose('till_momo')->key())->toBe('paystack');
});

it('switches the default provider with a setting, without losing a payment already on its way', function () {
    paystackReady();
    readyProvider('payswitch', ['mode' => 'test', 'merchant_id' => 'TTM-0001', 'api_user' => 'u', 'api_key' => 'k']);
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
    $manager = app(GatewayManager::class);

    $manager->setDefault('till_momo', 'paystack', $this->owner);
    $tx = $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier);
    expect($tx->gateway)->toBe('paystack');

    $manager->setDefault('till_momo', 'payswitch', $this->owner);
    expect($manager->forPurpose('till_momo')->key())->toBe('payswitch');

    // The prompt sent through Paystack is still checked with Paystack.
    $this->verify = 'success';
    $this->service->refresh($tx);
    expect($tx->fresh()->status)->toBe(GatewayStatus::Succeeded);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'api.paystack.co/transaction/verify/'));

    // If the chosen default stops working, the next ready provider takes over automatically.
    $this->connections->remove('payswitch');
    expect($manager->forPurpose('till_momo')->key())->toBe('paystack');
});
