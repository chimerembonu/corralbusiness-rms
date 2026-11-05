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
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Paystack (Ghana): MoMo prompts through the Charge API (MTN, Telecel, AT), status through Verify,
 * cards and MoMo online through Paystack's hosted page (Initialize), refunds through the Refund API.
 * Docs: paystack.com/docs — amounts in pesewas, currency GHS, local phone numbers (0551234987).
 * Checked against the docs on 5 Nov 2026: network codes mtn / vod (Telecel) / atl (AT), prompt status
 * pay_offline, 180 seconds for the customer to approve. Test number: MTN 055 123 4987 (approves, no OTP).
 */
class PaystackGateway extends AbstractGateway
{
    public function key(): string
    {
        return 'paystack';
    }

    public function capabilities(): array
    {
        return [GatewayCapability::MomoPrompt, GatewayCapability::HostedCheckout, GatewayCapability::StatusCheck, GatewayCapability::Refund, GatewayCapability::Statement];
    }

    public function networks(): array
    {
        return [MomoProvider::Mtn, MomoProvider::Telecel, MomoProvider::At];
    }

    public function test(array $values): TestResult
    {
        $this->configure($values);
        $secret = (string) $this->value('secret_key');
        $mode = $this->isLive() ? 'live' : 'test';
        if (! str_starts_with($secret, "sk_{$mode}_")) {
            return TestResult::failed(__('payments::payments.gateway.test.unexpected', ['provider' => 'Paystack', 'detail' => __('payments::payments.gateway.test.paystack_mode', ['mode' => $mode])]));
        }
        try {
            $response = $this->api()->get('/balance');
        } catch (ConnectionException) {
            return TestResult::failed(__('payments::payments.gateway.test.unreachable', ['provider' => 'Paystack']));
        }
        if ($response->status() === 401) {
            return TestResult::failed(__('payments::payments.gateway.test.bad_keys', ['provider' => 'Paystack']));
        }
        if (! $response->successful() || ! $response->json('status')) {
            return TestResult::failed(__('payments::payments.gateway.test.unexpected', ['provider' => 'Paystack', 'detail' => (string) ($response->json('message') ?? $response->status())]));
        }

        return TestResult::ok(__('payments::payments.gateway.test.ok', ['provider' => 'Paystack', 'mode' => $mode]));
    }

    public function requestMomo(ChargeRequest $charge): GatewayResponse
    {
        $response = $this->api()->post('/charge', [
            'email' => $this->email($charge),
            'amount' => $charge->amount->amount,
            'currency' => $charge->currency,
            'reference' => $charge->reference,
            'mobile_money' => ['phone' => $charge->phone, 'provider' => config('payments.paystack.networks.'.$charge->network?->value)],
            'metadata' => ['description' => $charge->description],
        ]);

        return $this->chargeResponse($response);
    }

    public function submitOtp(string $reference, ?string $providerReference, string $otp): GatewayResponse
    {
        return $this->chargeResponse($this->api()->post('/charge/submit_otp', ['otp' => $otp, 'reference' => $providerReference ?? $reference]));
    }

    public function status(string $reference, ?string $providerReference): GatewayResponse
    {
        $response = $this->api()->get('/transaction/verify/'.rawurlencode($providerReference ?? $reference));
        $data = (array) $response->json('data', []);
        $details = isset($data['amount']) ? ['amount' => (int) $data['amount'], 'currency' => $data['currency'] ?? null] : [];

        return match ($data['status'] ?? null) {
            'success' => GatewayResponse::succeeded($data['reference'] ?? $reference, $data['gateway_response'] ?? null, $details),
            // For Mobile Money Paystack gives the reason a prompt failed in data.message (docs: Payment Channels).
            'failed', 'reversed' => GatewayResponse::failed($data['message'] ?? $data['gateway_response'] ?? $response->json('message'), $data['reference'] ?? $reference, $details),
            default => GatewayResponse::pending($data['reference'] ?? $reference, null, $details),
        };
    }

    public function checkout(ChargeRequest $charge): GatewayResponse
    {
        $response = $this->api()->post('/transaction/initialize', [
            'email' => $this->email($charge),
            'amount' => $charge->amount->amount,
            'currency' => $charge->currency,
            'reference' => $charge->reference,
            'callback_url' => $charge->returnUrl,
            'channels' => ['card', 'mobile_money'],
            'metadata' => ['description' => $charge->description],
        ]);
        if (! $response->successful() || ! $response->json('data.authorization_url')) {
            return GatewayResponse::failed((string) ($response->json('message') ?? __('payments::payments.gateway.unreachable_send')));
        }

        return new GatewayResponse(GatewayStatus::Pending, $response->json('data.reference') ?? $charge->reference, null, $response->json('data.authorization_url'));
    }

    /**
     * Refund API (paystack.com/docs/payments/refunds, checked 5 Nov 2026). Our reference makes it safe to send
     * twice: Paystack answers 409 "already exists" and the status is fetched by reference instead.
     */
    public function refund(string $reference, ?string $providerReference, Money $amount, string $refundReference): GatewayResponse
    {
        $response = $this->api()->post('/refund', [
            'transaction' => $providerReference ?? $reference,
            'amount' => $amount->amount,
            'reference' => $refundReference,
            'merchant_note' => 'CorralBusiness RMS refund '.$refundReference,
        ]);
        if ($response->status() === 409) {
            return GatewayResponse::pending(null, $response->json('message'));
        }
        if ($response->serverError()) {
            throw new \RuntimeException('Paystack refund: HTTP '.$response->status()); // not sure it arrived: try again
        }
        if (! $response->successful() || ! $response->json('status')) {
            return GatewayResponse::failed((string) ($response->json('message') ?? $response->status()));
        }

        return $this->refundState((array) $response->json('data', []));
    }

    public function refundStatus(string $refundReference, ?string $providerRefundId): GatewayResponse
    {
        $response = $this->api()->get('/refund', ['reference' => $refundReference]);
        if (! $response->successful()) {
            throw new \RuntimeException('Paystack refund status: HTTP '.$response->status());
        }
        $refund = collect((array) $response->json('data', []))->first(fn ($r) => strcasecmp((string) ($r['reference'] ?? ''), $refundReference) === 0)
            ?? collect((array) $response->json('data', []))->first(fn ($r) => $providerRefundId !== null && (string) ($r['id'] ?? '') === $providerRefundId);

        return $refund ? $this->refundState((array) $refund) : new GatewayResponse(GatewayStatus::Expired, null, null);
    }

    /**
     * List Transactions (paystack.com/docs/api/transaction, checked 5 Nov 2026): GET /transaction with from/to,
     * 100 per page. Stops after 50 pages (5,000 payments) so a huge account can never hang the report.
     */
    public function statement(CarbonInterface $from, CarbonInterface $to): array
    {
        $entries = [];
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->api()->get('/transaction', [
                'from' => $from->copy()->utc()->toIso8601ZuluString(),
                'to' => $to->copy()->utc()->toIso8601ZuluString(),
                'perPage' => 100,
                'page' => $page,
            ]);
            if (! $response->successful() || ! $response->json('status')) {
                throw new \RuntimeException('Paystack transaction list: '.($response->json('message') ?? 'HTTP '.$response->status()));
            }
            $data = (array) $response->json('data', []);
            foreach ($data as $t) {
                $entries[] = [
                    'reference' => (string) ($t['reference'] ?? ''),
                    'status' => match ($t['status'] ?? null) {
                        'success' => 'paid',
                        'reversed' => 'reversed',
                        'failed', 'abandoned' => 'failed',
                        default => 'pending',
                    },
                    'amount' => (int) ($t['amount'] ?? 0),
                    'currency' => (string) ($t['currency'] ?? 'GHS'),
                    'paid_at' => $t['paid_at'] ?? $t['paidAt'] ?? null,
                ];
            }
            if (count($data) < 100) {
                break;
            }
        }

        return $entries;
    }

    /** pending / processing → Pending; processed → Succeeded; failed → Failed; needs-attention → NeedsAttention. */
    private function refundState(array $data): GatewayResponse
    {
        $id = isset($data['id']) ? (string) $data['id'] : null;

        return match ($data['status'] ?? null) {
            'processed' => GatewayResponse::succeeded($id, __('payments::payments.refunds.paystack.processed')),
            'failed' => GatewayResponse::failed(__('payments::payments.refunds.paystack.failed'), $id),
            'needs-attention' => new GatewayResponse(GatewayStatus::NeedsAttention, $id, __('payments::payments.refunds.paystack.needs_attention')),
            default => GatewayResponse::pending($id, __('payments::payments.refunds.paystack.pending')),
        };
    }

    private function chargeResponse(Response $response): GatewayResponse
    {
        $data = (array) $response->json('data', []);
        $reference = $data['reference'] ?? null;
        $text = $data['display_text'] ?? $data['message'] ?? null;
        if (! $response->successful() && ! isset($data['status'])) {
            return GatewayResponse::failed((string) ($response->json('message') ?? __('payments::payments.gateway.unreachable_send')), $reference);
        }

        return match ($data['status'] ?? null) {
            'success' => GatewayResponse::succeeded($reference, $data['gateway_response'] ?? null),
            'send_otp' => new GatewayResponse(GatewayStatus::NeedsOtp, $reference, $text),
            'failed' => GatewayResponse::failed($data['gateway_response'] ?? $text ?? $response->json('message'), $reference),
            default => GatewayResponse::pending($reference, $text), // pay_offline, pending, ongoing…
        };
    }

    private function email(ChargeRequest $charge): string
    {
        return $charge->email ?: Str::lower($charge->reference).'@'.config('payments.paystack.fallback_email_domain');
    }

    private function api()
    {
        return $this->http()->baseUrl(config('payments.paystack.base_url'))->withToken((string) $this->value('secret_key'));
    }
}
