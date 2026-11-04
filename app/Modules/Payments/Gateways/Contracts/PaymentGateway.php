<?php

namespace App\Modules\Payments\Gateways\Contracts;

use App\Modules\Payments\Enums\MomoProvider;
use App\Modules\Payments\Gateways\Data\ChargeRequest;
use App\Modules\Payments\Gateways\Data\GatewayCapability;
use App\Modules\Payments\Gateways\Data\GatewayResponse;
use App\Support\Connections\ConnectionTester;
use App\Support\Money\Money;

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

    /** Send money back. */
    public function refund(string $reference, ?string $providerReference, Money $amount): GatewayResponse;
}
