<?php

namespace App\Modules\Payments\Listeners;

use App\Modules\Payments\Events\PaymentRefunded;
use App\Modules\Payments\Gateways\GatewayRefundService;

/** A refunded payment that came through a payment provider: send the money back the same way (E2.4). */
class RefundThroughProvider
{
    public function __construct(private readonly GatewayRefundService $refunds) {}

    public function handle(PaymentRefunded $event): void
    {
        $this->refunds->startFor($event->payment);
    }
}
