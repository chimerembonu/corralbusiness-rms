<?php

use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Modules\Payments\Gateways\Providers\PaystackGateway;
use App\Modules\Payments\Gateways\Providers\PaySwitchGateway;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/* Complete Edition E2: the two real providers, against recorded examples of their answers (no real calls). */

function charge(string $ref = 'RMSTEST1'): ChargeRequest
{
    return new ChargeRequest($ref, Money::of(4500), 'Order #12', MomoProvider::Mtn, '0241234567');
}

it('Paystack: tests keys, sends a MoMo prompt, follows OTP, verifies, pays online and refunds', function () {
    $paystack = app(PaystackGateway::class)->configure(['mode' => 'test', 'public_key' => 'pk_test_x', 'secret_key' => 'sk_test_abc']);

    Http::fake([
        'api.paystack.co/balance' => Http::response(['status' => true, 'data' => [['currency' => 'GHS', 'balance' => 0]]]),
        'api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'RMSTEST1', 'status' => 'pay_offline', 'display_text' => 'Approve on your phone']]),
        'api.paystack.co/charge/submit_otp' => Http::response(['status' => true, 'data' => ['reference' => 'RMSTEST1', 'status' => 'pay_offline']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['reference' => 'RMSTEST1', 'status' => 'success', 'amount' => 4500, 'currency' => 'GHS']]),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'RMSTEST1']]),
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 77, 'status' => 'pending']]),
    ]);

    expect($paystack->test(['mode' => 'test', 'public_key' => 'pk', 'secret_key' => 'sk_test_abc'])->ok)->toBeTrue()
        ->and($paystack->test(['mode' => 'live', 'public_key' => 'pk', 'secret_key' => 'sk_test_abc'])->ok)->toBeFalse(); // test key in live mode

    $paystack->configure(['mode' => 'test', 'secret_key' => 'sk_test_abc']);
    $sent = $paystack->requestMomo(charge());
    expect($sent->status)->toBe(GatewayStatus::Pending)->and($sent->message)->toBe('Approve on your phone');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.paystack.co/charge'
        && $r['amount'] === 4500 && $r['currency'] === 'GHS' && $r['mobile_money'] === ['phone' => '0241234567', 'provider' => 'mtn']
        && $r->hasHeader('Authorization', 'Bearer sk_test_abc') && str_ends_with($r['email'], '@customers.corralbusiness.app'));

    $verified = $paystack->status('RMSTEST1', null);
    expect($verified->status)->toBe(GatewayStatus::Succeeded)->and($verified->details['amount'])->toBe(4500);

    expect($paystack->checkout(charge())->checkoutUrl)->toBe('https://checkout.paystack.com/abc')
        ->and($paystack->refund('RMSTEST1', null, Money::of(4500))->status)->toBe(GatewayStatus::Pending);
});

it('Paystack: shows the reason Paystack gives when a MoMo prompt fails', function () {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
        'reference' => 'R3', 'status' => 'failed', 'gateway_response' => 'Declined', 'message' => 'The customer did not approve in time',
    ]])]);

    $result = app(PaystackGateway::class)->configure(['mode' => 'test', 'secret_key' => 'sk_test_abc'])->status('R3', null);
    expect($result->status)->toBe(GatewayStatus::Failed)->and($result->message)->toBe('The customer did not approve in time');
});

it('Paystack: Telecel asks for a voucher code, bad keys and declines are plain answers', function () {
    Http::fake([
        'api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'R2', 'status' => 'send_otp', 'display_text' => 'Dial *110# to get a voucher']]),
        'api.paystack.co/balance' => Http::response(['status' => false, 'message' => 'Invalid key'], 401),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient funds']]),
    ]);
    $paystack = app(PaystackGateway::class)->configure(['mode' => 'test', 'secret_key' => 'sk_test_abc']);

    expect($paystack->requestMomo(new ChargeRequest('R2', Money::of(100), 'x', MomoProvider::Telecel, '0201234567'))->status)->toBe(GatewayStatus::NeedsOtp);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/charge') && $r['mobile_money']['provider'] === 'vod');
    expect($paystack->test(['mode' => 'test', 'secret_key' => 'sk_test_abc'])->message)->toContain('did not accept')
        ->and($paystack->status('R2', null))->status->toBe(GatewayStatus::Failed)
        ->and($paystack->status('R2', null)->message)->toBe('Insufficient funds');
});

it('PaySwitch: 12-digit ids, padded pesewas, basic auth, prompt, status and hosted checkout — never card numbers', function () {
    $ps = app(PaySwitchGateway::class)->configure(['mode' => 'test', 'merchant_id' => 'TTM-0001', 'api_user' => 'user', 'api_key' => 'key']);
    expect($ps->newReference())->toMatch('/^[1-9]\d{11}$/')->and(PaySwitchGateway::amount(Money::of(4500)))->toBe('000000004500');

    Http::fake([
        'test.theteller.net/v1.1/transaction/process' => Http::response(['status' => 'Payment request sent', 'code' => '111', 'reason' => 'Approve on your phone']),
        'test.theteller.net/v1.1/users/transactions/*/status' => Http::response(['code' => '000', 'status' => 'approved', 'reason' => 'Transaction successful']),
        'checkout-test.theteller.net/initiate' => Http::response(['status' => 'success', 'code' => 200, 'token' => 't', 'checkout_url' => 'https://checkout-test.theteller.net/pay/t']),
    ]);

    $sent = $ps->requestMomo(charge('123456789012'));
    expect($sent->status)->toBe(GatewayStatus::Pending);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1.1/transaction/process')
        && $r['amount'] === '000000004500' && $r['processing_code'] === '000200' && $r['r-switch'] === 'MTN'
        && $r['subscriber_number'] === '233241234567' && $r['merchant_id'] === 'TTM-0001'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('user:key'))
        && ! isset($r['pan']) && ! isset($r['cvv']));

    expect($ps->status('123456789012', null)->status)->toBe(GatewayStatus::Succeeded)
        ->and($ps->checkout(charge('123456789013'))->checkoutUrl)->toBe('https://checkout-test.theteller.net/pay/t');
});

it('PaySwitch: a time-out after sending keeps waiting; access denied and declines are plain answers', function () {
    $ps = app(PaySwitchGateway::class)->configure(['mode' => 'test', 'merchant_id' => 'TTM-0001', 'api_user' => 'user', 'api_key' => 'key']);
    Http::fake(['test.theteller.net/v1.1/transaction/process' => fn () => throw new ConnectionException('timed out')]);
    expect($ps->requestMomo(charge('123456789012'))->status)->toBe(GatewayStatus::Pending);

    Http::fake([
        'test.theteller.net/v1.1/users/transactions/*' => Http::sequence()
            ->push(['code' => '979', 'reason' => 'Access denied, invalid credential'])
            ->push(['code' => '101', 'reason' => 'Insufficient funds in wallet'])
            ->push(['code' => '404', 'reason' => 'Transaction not found']),
    ]);
    expect($ps->test(['mode' => 'test', 'merchant_id' => 'TTM-0001', 'api_user' => 'user', 'api_key' => 'bad'])->ok)->toBeFalse()
        ->and($ps->status('123456789012', null))->status->toBe(GatewayStatus::Failed)
        ->and($ps->test(['mode' => 'test', 'merchant_id' => 'TTM-0001', 'api_user' => 'user', 'api_key' => 'key'])->ok)->toBeTrue(); // "not found" = details accepted
});
