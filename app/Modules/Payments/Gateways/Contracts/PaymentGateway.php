<?php

namespace App\Modules\Payments\Gateways\Contracts;

use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Support\Connections\ConnectionTester;
use App\Support\Money\Money;
use Carbon\CarbonInterface;

/**
 * The rulebook every payment provider follows (Complete Edition E2). The rest of the app only ever talks
 * to this interface, so adding a provider is: one class implementing it + one entry in config/payments.php
 * + its fields in config/connections.php. See docs/ADDING-A-PAYMENT-PROVIDER.md.
 *
 * Rules: amounts are pesewas; no card number, expiry or CVV is ever sent to or stored by us (cards only
 * on the provider's hosted page); never throw for a declined payment — return a Failed response; only
 * throw for "you asked for something I do not support".
 */
interface PaymentGateway extends ConnectionTester
{
    /** Short id, e.g. "paystack" — the key in config/payments.php. */
    public function key(): string;

    /** @return array<int, GatewayCapability> */
    public function capabilities(): array;

    public function supports(GatewayCapability $capability): bool;

    /** @return array<int, MomoProvider> networks this provider can prompt */
    public function networks(): array;

    /** Load the restaurant's details (decrypted by ConnectionService). Returns the same instance. */
    public function configure(array $values): static;

    /** A fresh reference in the format this provider accepts (PaySwitch wants 12 digits, for example). */
    public function newReference(): string;

    /** Send a payment request to the customer's phone. */
    public function requestMomo(ChargeRequest $charge): GatewayResponse;

    /** Some networks answer with "type the code from your phone" — pass it on. */
    public function submitOtp(string $reference, ?string $providerReference, string $otp): GatewayResponse;

    /** Did it go through? Called every few seconds by the till and every minute in the background. */
    public function status(string $reference, ?string $providerReference): GatewayResponse;

    /** Start the provider's own secure payment page; the response carries checkoutUrl. */
    public function checkout(ChargeRequest $charge): GatewayResponse;

    /**
     * Send money back for a payment. $refundReference is ours and unique. The same refund may be asked for again
     * (after a time-out, say): the adapter must make sure the provider never refunds the payment twice — e.g. by
     * passing our reference if the provider honours one, or by first looking for a refund already made for this
     * payment and answering with its state instead of sending another (Paystack: test report 9 Nov 2026, H3).
     * Return Succeeded (money sent back), Pending (accepted or maybe accepted: refundStatus() will tell), Failed
     * (refused, with the reason) or NeedsAttention (the provider needs a person). Throw only if nothing can have
     * been sent (e.g. the provider could not be reached at all): it is tried again.
     */
    public function refund(string $reference, ?string $providerReference, Money $amount, string $refundReference): GatewayResponse;

    /**
     * How is that refund going? Same answers as refund(), plus Expired when the provider has no refund with
     * this reference (it never arrived): it is then sent again (a few times at most). $paymentReference is the
     * refunded payment's reference at the provider, for providers that list refunds per payment.
     * Throw if the provider could not be reached.
     */
    public function refundStatus(string $refundReference, ?string $providerRefundId, ?string $paymentReference = null): GatewayResponse;

    /**
     * The provider's own record of payments between two moments (only with the Statement capability), for the
     * payment matching report. Each entry: reference, status ('paid' | 'reversed' | 'failed' | 'pending'),
     * amount (pesewas), currency, paid_at (ISO 8601 or null). Throw if the provider could not be reached.
     *
     * @return array<int, array{reference: string, status: string, amount: int, currency: string, paid_at: ?string}>
     */
    public function statement(CarbonInterface $from, CarbonInterface $to): array;
}
