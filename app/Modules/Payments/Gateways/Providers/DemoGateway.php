<?php

namespace App\Modules\Payments\Gateways\Providers;

use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\AbstractGateway;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Support\Connections\TestResult;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Demo provider for trials and staff training. Only ever used for training-mode orders (GatewayManager
 * refuses it for real ones) and never contacts anyone. Behaves like a real network so staff can practise:
 *  - a number ending 0000 is declined
 *  - a number ending 1111 never answers (practise "stop waiting")
 *  - any other number is approved about 5 seconds after the prompt
 */
class DemoGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'demo';
    }

    public function capabilities(): array
    {
        return [GatewayCapability::MomoPrompt, GatewayCapability::StatusCheck, GatewayCapability::Refund];
    }

    public function networks(): array
    {
        return MomoProvider::cases();
    }

    public function test(array $values): TestResult
    {
        return TestResult::ok(__('payments::payments.gateway.test.demo_ok'));
    }

    public function requestMomo(ChargeRequest $charge): GatewayResponse
    {
        $outcome = match (true) {
            str_ends_with((string) $charge->phone, '0000') => 'decline',
            str_ends_with((string) $charge->phone, '1111') => 'silent',
            default => 'approve',
        };
        Cache::put("demo-gateway:{$charge->reference}", ['outcome' => $outcome, 'at' => now()->timestamp], now()->addHour());

        return $outcome === 'decline'
            ? GatewayResponse::failed(__('payments::payments.gateway.demo.declined'), $charge->reference)
            : GatewayResponse::pending($charge->reference, __('payments::payments.gateway.demo.sent'));
    }

    public function status(string $reference, ?string $providerReference): GatewayResponse
    {
        $state = Cache::get("demo-gateway:{$reference}");
        if (! $state) {
            return GatewayResponse::failed(__('payments::payments.gateway.status.failed'), $reference);
        }
        if ($state['outcome'] === 'approve' && now()->timestamp - $state['at'] >= 5) {
            return GatewayResponse::succeeded($reference, __('payments::payments.gateway.demo.approved'));
        }

        return GatewayResponse::pending($reference);
    }

    public function refund(string $reference, ?string $providerReference, Money $amount, string $refundReference): GatewayResponse
    {
        return GatewayResponse::succeeded($refundReference, __('payments::payments.refunds.demo'));
    }

    public function refundStatus(string $refundReference, ?string $providerRefundId): GatewayResponse
    {
        return GatewayResponse::succeeded($refundReference, __('payments::payments.refunds.demo'));
    }
}
