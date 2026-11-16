<?php

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Gateways\GatewayPaymentService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/*
 * Test report 9 Nov 2026, H2: when sending a MoMo prompt to Paystack timed out (or Paystack answered with a
 * server error), the app called it failed and never checked again, though the prompt may already have been
 * on the customer's phone. The cashier then sent a second prompt and the customer could pay twice, with the
 * first payment never recorded. Now the app keeps checking, refuses a second prompt meanwhile, and records
 * the money if it arrives.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $orders = app(OrderService::class);
    app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->cashier, Money::of(5000), $orders->businessDate());
    $this->order = $orders->open(OrderType::Takeaway, $this->cashier);
    $orders->addItem($this->order, $this->menu['coke'], null, 2, [], null, $this->cashier);
    $this->due = $this->order->fresh()->balanceDue();

    config(['features.features.momo_prompt.available' => true]);
    installTestLicence('business');
    $this->service = app(GatewayPaymentService::class);
    $this->charge = 'timeout';
    $this->verify = 'pending';
    Http::fake([
        'api.paystack.co/balance' => Http::response(['status' => true, 'data' => []]),
        'api.paystack.co/charge' => fn () => match ($this->charge) {
            'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
            '502' => Http::response('<html>Bad Gateway</html>', 502),
            '400' => Http::response(['status' => false, 'message' => 'Invalid phone number'], 400),
        },
        'api.paystack.co/transaction/verify/*' => fn () => Http::response(['status' => true, 'data' => ['status' => $this->verify, 'amount' => $this->due->amount]]),
    ]);
    $connections = app(ConnectionService::class);
    $connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_abc']);
    expect($connections->test('paystack')->ok)->toBeTrue();
    app(FeatureService::class)->set('momo_prompt', true, $this->owner);
});

it('keeps checking after a send that timed out or hit a server error, and records the money once', function (string $mode) {
    $this->charge = $mode;
    $tx = $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier);
    expect($tx->status)->toBe(GatewayStatus::Pending)->and($tx->message)->toContain('may already be on the customer');

    // No second prompt while we don't know whether the first one reached the phone.
    expect(fn () => $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier))
        ->toThrow(PaymentException::class, 'already sent');

    // The customer approves the first prompt: it is recorded, once.
    $this->verify = 'success';
    $this->service->refresh($tx);
    $this->service->refresh($tx);
    expect($tx->fresh()->status)->toBe(GatewayStatus::Succeeded)
        ->and(Payment::query()->count())->toBe(1)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Paid);
})->with(['time-out' => 'timeout', 'server error' => '502']);

it('still calls a clear refusal from Paystack failed, so the cashier can try again', function () {
    $this->charge = '400';
    $tx = $this->service->startMomoPrompt($this->order, MomoProvider::Mtn, '0241234567', $this->due, $this->cashier);
    expect($tx->status)->toBe(GatewayStatus::Failed)->and($tx->message)->toContain('Invalid phone number');
});
