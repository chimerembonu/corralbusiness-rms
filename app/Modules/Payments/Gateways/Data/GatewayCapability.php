<?php

namespace App\Modules\Payments\Gateways\Data;

/** What a payment provider can do. The app only asks a provider for things it says it supports. */
enum GatewayCapability: string
{
    /** Send a payment request to the customer's phone; they approve with their MoMo PIN. */
    case MomoPrompt = 'momo_prompt';
    /** The provider's own secure payment page (cards, MoMo) — card numbers never touch our server. */
    case HostedCheckout = 'hosted_checkout';
    /** Ask the provider whether a payment went through (needed: the store server cannot receive calls from the internet). */
    case StatusCheck = 'status_check';
    /** Send money back through the provider. */
    case Refund = 'refund';
    /** List the provider's own record of payments for a period, to match against the tills (E2.5). */
    case Statement = 'statement';
}
