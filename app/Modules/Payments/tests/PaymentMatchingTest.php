<?php

use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Gateways\GatewayReconciliationService;
use App\Modules\Payments\Models\GatewayRefund;
use App\Modules\Payments\Models\GatewayTransaction;
use App\Modules\Payments\Services\CashService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Pos\Models\Till;
use App\Modules\Reports\Filament\Pages\PaymentMatchingReport;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Support\Connections\ConnectionService;
use App\Support\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Complete Edition E2.5 — payment matching: the day's provider payments beside the tills' records
 * (matched, missing, "needs a manager"), with the provider's own list on request, and Excel export.
 */
beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->menu = demoMenu();
    $this->manager = User::factory()->forBranch($this->branch)->create();
    $this->manager->assignRole(Role::Manager->value);
    $this->cashier = User::factory()->forBranch($this->branch)->create();
    $this->cashier->assignRole(Role::Cashier->value);
    $this->orders = app(OrderService::class);
    app(CashService::class)->open(Till::create(['name' => 'Till 1', 'code' => 'T1']), $this->cashier, Money::of(5000), $this->orders->businessDate());
    installTestLicence('business');

    $this->paystackList = [];
    $this->paystackDown = false;
    Http::fake([
        'api.paystack.co/balance' => Http::response(['status' => true, 'data' => []]),
        'api.paystack.co/transaction?*' => fn () => $this->paystackDown
            ? Http::response(['status' => false, 'message' => 'Service unavailable'], 503)
            : Http::response(['status' => true, 'data' => $this->paystackList]),
    ]);
    $c = app(ConnectionService::class);
    $c->save('paystack', ['mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_abc']);
    expect($c->test('paystack')->ok)->toBeTrue();
});

/** A provider payment in a given state, optionally recorded on the till (as GatewayPaymentService does). */
function providerPayment(object $t, string $ref, GatewayStatus $status, bool $onTill = false, int $pesewas = 1200, string $gateway = 'paystack'): GatewayTransaction
{
    $order = $t->orders->open(OrderType::Takeaway, $t->cashier);
    $t->orders->addItem($order, $t->menu['coke'], null, 1, [], null, $t->cashier);
    $transaction = GatewayTransaction::create([
        'gateway' => $gateway, 'purpose' => 'till_momo', 'order_id' => $order->id, 'reference' => $ref, 'provider_reference' => $ref,
        'amount' => Money::of($pesewas), 'network' => MomoProvider::Mtn, 'phone_masked' => '055•••4987', 'status' => $status,
        'requested_by_id' => $t->cashier->id, 'expires_at' => now()->addMinutes(3),
    ]);
    if ($onTill) {
        $payment = app(PaymentService::class)->record($order->fresh(), PaymentMethod::MobileMoney, Money::of($pesewas), $t->cashier, reference: $ref, provider: MomoProvider::Mtn);
        $transaction->forceFill(['payment_id' => $payment->id, 'status' => GatewayStatus::Succeeded])->save();
    }

    return $transaction->fresh();
}

function groupOf(array $report, string $reference): string
{
    return collect($report['rows'])->firstWhere('reference', $reference)['group'];
}

it('sorts every provider payment into matched, refunded, needs a manager, waiting or not paid', function () {
    providerPayment($this, 'RMSMATCHED', GatewayStatus::Succeeded, onTill: true);
    providerPayment($this, 'RMSWAITING', GatewayStatus::Pending);
    providerPayment($this, 'RMSFAILED', GatewayStatus::Failed);
    providerPayment($this, 'RMSATTENTION', GatewayStatus::NeedsAttention);
    $refunded = providerPayment($this, 'RMSREFUNDED', GatewayStatus::Succeeded, onTill: true);
    $byHand = providerPayment($this, 'RMSBYHAND', GatewayStatus::Succeeded, onTill: true, gateway: 'payswitch');
    app(PaymentService::class)->refund($refunded->payment, 'Wrong order', $this->manager, $this->cashier);
    app(PaymentService::class)->refund($byHand->payment, 'Wrong order', $this->manager, $this->cashier);
    GatewayRefund::query()->where('payment_id', $refunded->payment_id)->update(['status' => RefundStatus::Refunded->value]);

    $report = app(GatewayReconciliationService::class)->report(today()->toDateString(), today()->toDateString());

    expect(groupOf($report, 'RMSMATCHED'))->toBe('matched')
        ->and(groupOf($report, 'RMSWAITING'))->toBe('waiting')
        ->and(groupOf($report, 'RMSFAILED'))->toBe('not_paid')
        ->and(groupOf($report, 'RMSATTENTION'))->toBe('needs_manager')
        ->and(groupOf($report, 'RMSREFUNDED'))->toBe('refunded')
        ->and(groupOf($report, 'RMSBYHAND'))->toBe('needs_manager')
        ->and(collect($report['rows'])->firstWhere('reference', 'RMSBYHAND')['note'])->toContain('given back by hand')
        ->and($report['groups']['needs_manager'])->toBe(['count' => 2, 'amount' => 2400])
        ->and($report['totals']['till'])->toBe(1200)        // only the matched one is still on the till
        ->and($report['totals']['provider'])->toBeNull()    // Paystack's own list not fetched yet
        ->and($report['statements']['paystack']['status'])->toBe('none')
        ->and($report['statements']['payswitch']['status'])->toBe('unsupported');

    // Another day's report does not show today's payments.
    expect(app(GatewayReconciliationService::class)->report(today()->subDay()->toDateString(), today()->subDay()->toDateString())['rows'])->toBe([]);
});

it('compares with Paystack\'s own list: money the till missed, money Paystack never got, and payments no till knows', function () {
    providerPayment($this, 'RMSMATCHED', GatewayStatus::Succeeded, onTill: true);
    providerPayment($this, 'RMSLATE', GatewayStatus::Expired);                       // the cashier gave up; the customer paid later
    providerPayment($this, 'RMSNOTPAID', GatewayStatus::Succeeded, onTill: true);     // the till has it, Paystack says abandoned
    providerPayment($this, 'RMSSHORT', GatewayStatus::Succeeded, onTill: true);       // Paystack got less
    $this->paystackList = [
        ['reference' => 'RMSMATCHED', 'status' => 'success', 'amount' => 1200, 'currency' => 'GHS', 'paid_at' => now()->toIso8601String()],
        ['reference' => 'rmslate', 'status' => 'success', 'amount' => 1200, 'currency' => 'GHS', 'paid_at' => now()->toIso8601String()],
        ['reference' => 'RMSNOTPAID', 'status' => 'abandoned', 'amount' => 1200, 'currency' => 'GHS', 'paid_at' => null],
        ['reference' => 'RMSSHORT', 'status' => 'success', 'amount' => 1000, 'currency' => 'GHS', 'paid_at' => now()->toIso8601String()],
        ['reference' => 'RMSUNKNOWN01', 'status' => 'success', 'amount' => 5000, 'currency' => 'GHS', 'paid_at' => now()->toIso8601String()],
        ['reference' => 'website-order-77', 'status' => 'success', 'amount' => 9900, 'currency' => 'GHS', 'paid_at' => now()->toIso8601String()], // not ours
    ];

    $service = app(GatewayReconciliationService::class);
    $day = today()->toDateString();
    $service->requestStatement('paystack', $day, $day); // queued job (runs at once in tests)
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.paystack.co/transaction?') && $r['perPage'] === 100
        && str_starts_with($r['from'], $day) && $r['page'] === 1);

    $report = $service->report($day, $day);
    expect($report['statements']['paystack']['status'])->toBe('ready')->and($report['statements']['paystack']['count'])->toBe(6)
        ->and(groupOf($report, 'RMSMATCHED'))->toBe('matched')
        ->and(groupOf($report, 'RMSLATE'))->toBe('needs_manager')
        ->and(collect($report['rows'])->firstWhere('reference', 'RMSLATE')['note'])->toContain('no till recorded it')
        ->and(groupOf($report, 'RMSNOTPAID'))->toBe('needs_manager')
        ->and(groupOf($report, 'RMSSHORT'))->toBe('needs_manager')
        ->and(collect($report['rows'])->firstWhere('reference', 'RMSSHORT')['note'])->toContain('GH₵ 10.00')
        ->and($report['missing'])->toHaveCount(1)
        ->and($report['missing'][0]['reference'])->toBe('RMSUNKNOWN01')
        ->and($report['groups']['missing'])->toBe(['count' => 1, 'amount' => 5000])
        ->and($report['totals']['provider'])->toBe(1200 + 1200 + 1000 + 5000);
});

it('says so plainly when Paystack cannot be reached', function () {
    $this->paystackDown = true;
    $day = today()->toDateString();
    app(GatewayReconciliationService::class)->requestStatement('paystack', $day, $day);

    $report = app(GatewayReconciliationService::class)->report($day, $day);
    expect($report['statements']['paystack']['status'])->toBe('failed')
        ->and($report['statements']['paystack']['error'])->toContain('Could not get Paystack');
});

it('shows the report to managers in the back office, fetches Paystack\'s list on request, and exports to Excel', function () {
    providerPayment($this, 'RMSMATCHED', GatewayStatus::Succeeded, onTill: true);
    providerPayment($this, 'RMSATTENTION', GatewayStatus::NeedsAttention);
    $this->paystackList = [['reference' => 'RMSMATCHED', 'status' => 'success', 'amount' => 1200, 'currency' => 'GHS', 'paid_at' => now()->toIso8601String()]];

    $this->actingAs($this->cashier)->get(PaymentMatchingReport::getUrl())->assertForbidden();
    $this->actingAs($this->manager)->get(PaymentMatchingReport::getUrl())->assertOk()->assertSee('Payment matching');

    Livewire::test(PaymentMatchingReport::class)
        ->assertSee('Needs a manager')->assertSee('RMSATTENTION')->assertSee('could not be added to the order')
        ->assertSee('Press "Compare with Paystack" to add it')
        ->call('compare', 'paystack')
        ->assertSee('Paystack\'s list fetched at')->assertSee('Paid · GH₵ 12.00')
        ->call('compare', 'demo') // not a provider with a list: ignored
        ->callAction('excel')->assertFileDownloaded('payment-matching-'.today()->toDateString().'-to-'.today()->toDateString().'.xlsx');
});
