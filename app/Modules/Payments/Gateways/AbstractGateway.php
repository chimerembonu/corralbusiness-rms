<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Payments\Gateways\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\Contracts\UnsupportedByGateway;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared plumbing for providers: capability checks, "not supported" defaults, and an HTTP client with
 * short time-outs and retries for status checks, so a slow provider never freezes a till.
 */
abstract class AbstractGateway implements PaymentGateway
{
    /** @var array<string, string|null> */
    protected array $values = [];

    public function configure(array $values): static
    {
        $this->values = $values;

        return $this;
    }

    public function supports(GatewayCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function newReference(): string
    {
        return 'RMS'.Str::upper((string) Str::ulid());
    }

    public function submitOtp(string $reference, ?string $providerReference, string $otp): GatewayResponse
    {
        throw UnsupportedByGateway::capability($this->key(), GatewayCapability::MomoPrompt);
    }

    public function requestMomo(ChargeRequest $charge): GatewayResponse
    {
        throw UnsupportedByGateway::capability($this->key(), GatewayCapability::MomoPrompt);
    }

    public function checkout(ChargeRequest $charge): GatewayResponse
    {
        throw UnsupportedByGateway::capability($this->key(), GatewayCapability::HostedCheckout);
    }

    public function refund(string $reference, ?string $providerReference, Money $amount, string $refundReference): GatewayResponse
    {
        throw UnsupportedByGateway::capability($this->key(), GatewayCapability::Refund);
    }

    public function refundStatus(string $refundReference, ?string $providerRefundId): GatewayResponse
    {
        throw UnsupportedByGateway::capability($this->key(), GatewayCapability::Refund);
    }

    public function statement(CarbonInterface $from, CarbonInterface $to): array
    {
        throw UnsupportedByGateway::capability($this->key(), GatewayCapability::Statement);
    }

    protected function value(string $field): ?string
    {
        return $this->values[$field] ?? null;
    }

    protected function isLive(): bool
    {
        return ($this->values['mode'] ?? 'test') === 'live';
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->connectTimeout((int) config('payments.http.connect_timeout', 5))
            ->timeout((int) config('payments.http.timeout', 15))
            // Only questions (GET: status checks) are asked again. A payment request is never re-sent by
            // itself: a slow provider may already have prompted the customer, and a second request would be
            // refused as a duplicate (or, worse, charge twice). Its status is checked instead.
            ->retry(2, 300, fn ($e, $request, $method = null) => strtoupper((string) $method) === 'GET', throw: false);
    }
}
