<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Filament\Pages\PaymentProvidersPage;
use App\Modules\Payments\Gateways\GatewayManager;
use App\Modules\Payments\Gateways\GatewayPaymentService;
use App\Modules\Payments\Gateways\GatewayRefundService;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Livewire\OrdersList;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Features\FeatureService;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Complete Edition E2.4 — a manager refunds a Mobile Money prompt payment from the till and the money goes back
 * through the same provider where it can; otherwise the till says "refund by hand" and it is recorded.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->owner = User::factory()->forBranch($this->branch)->create();
    $this->owner->assignRole(Role::Owner->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->cashier = User::factory()->forBranch($this->branch)->withPin('1111')->create(['name' => 'Kofi']);
    $this->cashier->assignRole(Role::Cashier->value);
    $this->orders = app(OrderService::class);
    app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->cashier, Money::of(5000), $this->orders->businessDate());
    $this->order = $this->orders->open(OrderType::Takeaway, $this->cashier);
    $this->orders->addItem($this->order, $this->menu['coke'], null, 2, [], null, $this->cashier);
    $this->due = $this->order->fresh()->balanceDue();

    config(['features.features.momo_prompt.available' => true]);
    installTestLicence('business');
    $this->refundAnswer = fn () => Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 3018284, 'status' => 'pending', 'reference' => 'x']]);
    $this->refundState = 'processed';
    Http::fake([
        'api.paystack.co/balance' => Http::response(['status' => true, 'data' => []]),
        'api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'PSREF1', 'status' => 'pay_offline']]),
        'api.paystack.co/transaction/verify/*' => fn () => Http::response(['status' => true, 'data' => ['reference' => 'PSREF1', 'status' => 'success', 'amount' => $this->due->amount]]),
        'api.paystack.co/refund?*' => fn (Request $r) => Http::response(['status' => true, 'data' => [['id' => 3018284, 'reference' => $r->data()['reference'] ?? '', 'status' => $this->refundState]]]),
        'api.paystack.co/refund' => fn () => ($this->refundAnswer)(),
        'test.theteller.net/*' => Http::response(['code' => '000', 'status' => 'approved', 'reason' => 'Transaction Successful']),
    ]);
});

/** Paid at the till by a Mobile Money prompt through the given provider. */
function paidByPrompt(object $t, string $gateway = 'paystack'): Payment
{
    $connections = app(ConnectionService::class);
    if ($gateway === 'paystack') {
        $connections->save('paystack', ['mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_abc']);
    } else {
        $connections->save('payswitch', ['mode' => 'test', 'merchant_id' => 'TTM-1', 'api_user' => 'u', 'api_key' => 'k']);
    }
    expect($connections->test($gateway)->ok)->toBeTrue();
    app(GatewayManager::class)->setDefault('till_momo', $gateway, $t->owner);
    app(FeatureService::class)->set('momo_prompt', true, $t->owner);

    $service = app(GatewayPaymentService::class);
    $transaction = $service->startMomoPrompt($t->order, MomoProvider::Mtn, '0551234987', $t->due, $t->cashier);
    $service->refresh($transaction);

    return Payment::query()->sole();
}

it('sends the refund back through Paystack in the background and follows it until done', function () {
    $payment = paidByPrompt($this);

    app(PaymentService::class)->refund($payment, 'Wrong order', $this->manager, $this->cashier);

    $refund = GatewayRefund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Sent)            // sent straight away by the queued job
        ->and($refund->amount->amount)->toBe($this->due->amount)
        ->and($refund->provider_reference)->toBe('3018284')
        ->and($refund->reason)->toBe('Wrong order')
        ->and($refund->approved_by_id)->toBe($this->manager->id);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.paystack.co/refund' && $r->method() === 'POST'
        && $r['transaction'] === 'PSREF1' && $r['amount'] === $this->due->amount && $r['reference'] === $refund->reference);

    // The every-minute check asks Paystack how it is going.
    $this->travel(2)->minutes();
    expect(app(GatewayRefundService::class)->checkOutstanding())->toBe(1);
    expect($refund->fresh()->status)->toBe(RefundStatus::Refunded)
        ->and($refund->fresh()->message)->toContain('10 working days');

    // Refunding is once only, and nothing more is asked once it is done.
    expect(fn () => app(PaymentService::class)->refund($payment->fresh(), 'Again', $this->manager))->toThrow(PaymentException::class, 'already');
    $this->travel(5)->minutes();
    expect(app(GatewayRefundService::class)->checkOutstanding())->toBe(0)
        ->and(GatewayRefund::query()->count())->toBe(1);
});

it('keeps trying when Paystack cannot be reached, and never refunds twice', function () {
    $payment = paidByPrompt($this);
    $this->refundAnswer = fn () => throw new ConnectionException('timed out');

    app(PaymentService::class)->refund($payment, 'Cold drink', $this->manager, $this->cashier);
    $refund = GatewayRefund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Queued)->and($refund->message)->toContain('tried again every minute')
        ->and($refund->attempts)->toBe(1);

    // The first one did arrive after all: Paystack says "already exists", so we just follow it.
    $this->refundAnswer = fn () => Http::response(['status' => false, 'message' => 'A refund with reference already exists'], 409);
    $this->travel(2)->minutes();
    app(GatewayRefundService::class)->checkOutstanding();
    expect($refund->fresh()->status)->toBe(RefundStatus::Sent);

    $this->travel(2)->minutes();
    app(GatewayRefundService::class)->checkOutstanding();
    expect($refund->fresh()->status)->toBe(RefundStatus::Refunded);
    // Every refund request carried the same reference of ours, so Paystack can never pay it out twice.
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && $pair[0]->url() === 'https://api.paystack.co/refund')
        ->map(fn ($pair) => $pair[0]['reference'])->unique()->values()->all())->toBe([$refund->reference]);

    // No internet for three days: a person gives the money back instead.
    $other = GatewayRefund::query()->sole()->replicate()->fill(['reference' => 'RFOTHER0001', 'status' => RefundStatus::Queued, 'next_check_at' => null]);
    $other->tenant_id = $refund->tenant_id;
    $other->created_at = now()->subDays(4);
    $other->save();
    $this->refundAnswer = fn () => throw new ConnectionException('no internet');
    app(GatewayRefundService::class)->checkOutstanding();
    expect($other->fresh()->status)->toBe(RefundStatus::Failed)->and($other->fresh()->message)->toContain('three days');
});

it('says "refund by hand" when Paystack refuses, and a manager confirms it was given back', function () {
    $payment = paidByPrompt($this);
    $this->refundAnswer = fn () => Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], 400);

    app(PaymentService::class)->refund($payment, 'Wrong order', $this->manager, $this->cashier);
    $refund = GatewayRefund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Failed)->and($refund->status->needsHand())->toBeTrue()
        ->and($refund->message)->toContain('Transaction has been fully reversed')->and($refund->message)->toContain('by hand');

    // The owner sees it on Settings → Payment providers until it is given back.
    $this->actingAs($this->owner);
    Livewire::test(PaymentProvidersPage::class)->assertSee('Refunds to give back by hand')->assertSee('055•••4987')->assertSee('fully reversed');

    expect(fn () => app(GatewayRefundService::class)->markDoneByHand($refund, $this->cashier))->toThrow(PaymentException::class, 'Only a manager');
    app(GatewayRefundService::class)->markDoneByHand($refund, $this->manager, 'Cash from the drawer');
    expect($refund->fresh()->status)->toBe(RefundStatus::DoneByHand)
        ->and($refund->fresh()->done_by_hand_by_id)->toBe($this->manager->id)
        ->and($refund->fresh()->done_by_hand_note)->toBe('Cash from the drawer');
    Livewire::test(PaymentProvidersPage::class)->assertSee('No refunds waiting');
    expect(fn () => app(GatewayRefundService::class)->markDoneByHand($refund->fresh(), $this->manager))->toThrow(PaymentException::class, 'does not need');

    // Paystack itself giving up later (needs the customer's bank details) also lands on the managers' list.
    $refund->forceFill(['status' => RefundStatus::Sent, 'next_check_at' => null])->save();
    $this->refundState = 'needs-attention';
    app(GatewayRefundService::class)->checkOutstanding();
    expect($refund->fresh()->status)->toBe(RefundStatus::Failed)->and($refund->fresh()->message)->toContain('bank details');
});

it('PaySwitch cannot send Mobile Money back: the refund is marked "by hand" at once, with no call to PaySwitch', function () {
    $payment = paidByPrompt($this, 'payswitch');

    app(PaymentService::class)->refund($payment, 'Wrong order', $this->manager, $this->cashier);

    $refund = GatewayRefund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::ByHand)->and($refund->message)->toContain('cannot send Mobile Money back automatically');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'refund') || str_contains($r->url(), 'reversal'));
});

it('does nothing extra for payments that did not come through a provider', function () {
    $payment = app(PaymentService::class)->payCash($this->order, Money::of(5000), $this->cashier);
    app(PaymentService::class)->refund($payment, 'Cold drink', $this->manager, $this->cashier);

    expect(GatewayRefund::query()->count())->toBe(0);
});

it('shows the cashier where the money is going, and lets a manager confirm a refund by hand with a PIN', function () {
    $payment = paidByPrompt($this, 'payswitch');
    $this->actingAs($this->cashier);

    $screen = Livewire::test(OrdersList::class)->call('open', $this->order->id)
        ->call('startRefund', $payment->id)->set('refund.reason', 'Wrong order')->call('confirmRefund')
        ->call('approve', '2468')
        ->assertSee('Now give the customer')->assertSee('055•••4987')->assertSee('Refund by hand');

    $refund = GatewayRefund::query()->sole();
    $screen->set("handNotes.{$refund->id}", 'Sent from the restaurant MoMo phone')->call('confirmDoneByHand', $refund->id)
        ->assertSet('approval.permission', 'orders.refund')
        ->call('approve', '2468')->assertSee('marked as given back by hand')->assertSee('Refunded by hand');

    expect($refund->fresh()->status)->toBe(RefundStatus::DoneByHand)
        ->and($refund->fresh()->done_by_hand_note)->toBe('Sent from the restaurant MoMo phone');
});
