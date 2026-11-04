<?php

namespace App\Modules\Payments\Gateways\Providers;

use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\AbstractGateway;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Modules\Payments\Gateways\Data\GatewayStatus;
use App\Support\Connections\TestResult;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;

/**
 * PaySwitch / theteller (Ghana): MoMo prompts (processing_code 000200, r-switch MTN/VDF/ATL), status
 * checks, and the hosted checkout page for cards and MoMo online. Docs: theteller.net/documentation.
 *
 * Deliberately NOT used: theteller's direct card API (processing_code 000000), which needs the card
 * number and CVV to pass through our server — that would put the till inside PCI DSS scope.
 */
class PaySwitchGateway extends AbstractGateway
{
    private const ACCESS_DENIED = ['600', '979', '999'];

    public function key(): string
    {
        return 'payswitch';
    }

    public function capabilities(): array
    {
        return [GatewayCapability::MomoPrompt, GatewayCapability::HostedCheckout, GatewayCapability::StatusCheck];
    }

    public function networks(): array
    {
        return [MomoProvider::Mtn, MomoProvider::Telecel, MomoProvider::At];
    }

    /** theteller wants a unique 12-digit transaction id. */
    public function newReference(): string
    {
        return (string) random_int(1, 9).str_pad((string) random_int(0, 99_999_999_999), 11, '0', STR_PAD_LEFT);
    }

    public function test(array $values): TestResult
    {
        $this->configure($values);
        $mode = $this->isLive() ? 'live' : 'test';
        try {
            // A status check for an id that cannot exist: good details get "not found", bad ones get "access denied".
            $response = $this->api()->withHeaders(['Merchant-Id' => (string) $this->value('merchant_id')])
                ->get('/v1.1/users/transactions/'.$this->newReference().'/status');
        } catch (ConnectionException) {
            return TestResult::failed(__('payments::payments.gateway.test.unreachable', ['provider' => 'PaySwitch']));
        }
        $code = (string) $response->json('code');
        if (in_array($response->status(), [401, 403], true) || in_array($code, self::ACCESS_DENIED, true) || str_contains(strtolower((string) $response->json('reason')), 'invalid api')) {
            return TestResult::failed(__('payments::payments.gateway.test.bad_keys', ['provider' => 'PaySwitch']));
        }
        if ($response->serverError()) {
            return TestResult::failed(__('payments::payments.gateway.test.unexpected', ['provider' => 'PaySwitch', 'detail' => $response->status()]));
        }

        return TestResult::ok(__('payments::payments.gateway.test.ok', ['provider' => 'PaySwitch', 'mode' => $mode]));
    }

    public function requestMomo(ChargeRequest $charge): GatewayResponse
    {
        try {
            $response = $this->api()->post('/v1.1/transaction/process', [
                'amount' => self::amount($charge->amount),
                'processing_code' => '000200',
                'transaction_id' => $charge->reference,
                'desc' => mb_substr($charge->description, 0, 100),
                'merchant_id' => $this->value('merchant_id'),
                'subscriber_number' => '233'.substr((string) $charge->phone, 1),
                'r-switch' => config('payments.payswitch.networks.'.$charge->network?->value),
            ]);
        } catch (ConnectionException) {
            // theteller often holds the line until the customer approves: a time-out here usually means
            // the prompt WAS sent, so keep checking the status rather than calling it failed.
            return GatewayResponse::pending($charge->reference, __('payments::payments.gateway.status.pending'));
        }

        return $this->fromCode((string) $response->json('code'), $response->json('reason') ?? $response->json('status'), $charge->reference);
    }

    public function status(string $reference, ?string $providerReference): GatewayResponse
    {
        $response = $this->api()->withHeaders(['Merchant-Id' => (string) $this->value('merchant_id')])
            ->get('/v1.1/users/transactions/'.rawurlencode($reference).'/status');

        return $this->fromCode((string) $response->json('code'), $response->json('reason') ?? $response->json('status'), $reference, pendingIfUnknown: true);
    }

    public function checkout(ChargeRequest $charge): GatewayResponse
    {
        $base = config('payments.payswitch.checkout_url.'.($this->isLive() ? 'live' : 'test'));
        $response = $this->http()->withBasicAuth((string) $this->value('api_user'), (string) $this->value('api_key'))->post($base.'/initiate', [
            'merchant_id' => $this->value('merchant_id'),
            'transaction_id' => $charge->reference,
            'desc' => mb_substr($charge->description, 0, 100),
            'amount' => self::amount($charge->amount),
            'redirect_url' => $charge->returnUrl,
            'email' => $charge->email ?? '',
            'API_Key' => $this->value('api_key'),
            'apiuser' => $this->value('api_user'),
        ]);
        if (! $response->json('checkout_url')) {
            return GatewayResponse::failed((string) ($response->json('reason') ?? __('payments::payments.gateway.unreachable_send')));
        }

        return new GatewayResponse(GatewayStatus::Pending, $charge->reference, null, $response->json('checkout_url'));
    }

    /** theteller amounts: 12 characters, zero-padded, in pesewas (GH₵ 1.00 = "000000000100"). Confirm in the test account. */
    public static function amount(Money $amount): string
    {
        return str_pad((string) $amount->amount, 12, '0', STR_PAD_LEFT);
    }

    private function fromCode(string $code, ?string $reason, string $reference, bool $pendingIfUnknown = false): GatewayResponse
    {
        return match (true) {
            $code === '000' => GatewayResponse::succeeded($reference, $reason),
            in_array($code, ['111', '200', ''], true) => GatewayResponse::pending($reference, $reason),
            in_array($code, self::ACCESS_DENIED, true) => GatewayResponse::failed(__('payments::payments.gateway.test.bad_keys', ['provider' => 'PaySwitch']), $reference),
            $pendingIfUnknown && ! in_array($code, ['100', '101', '102', '103', '104', '105', '107', '114'], true) => GatewayResponse::pending($reference, $reason),
            default => GatewayResponse::failed($reason ?: __('payments::payments.gateway.status.failed'), $reference),
        };
    }

    private function api()
    {
        return $this->http()->baseUrl(config('payments.payswitch.base_url.'.($this->isLive() ? 'live' : 'test')))
            ->withBasicAuth((string) $this->value('api_user'), (string) $this->value('api_key'));
    }
}
