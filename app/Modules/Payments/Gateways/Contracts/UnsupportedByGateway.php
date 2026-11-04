<?php

namespace App\Modules\Payments\Gateways\Contracts;

use App\Modules\Payments\Gateways\Data\GatewayCapability;
use RuntimeException;

class UnsupportedByGateway extends RuntimeException
{
    public static function capability(string $gateway, GatewayCapability $capability): self
    {
        return new self(__('payments::payments.gateway.errors.unsupported', ['provider' => __("payments::payments.gateway.names.{$gateway}"), 'what' => __('payments::payments.gateway.capabilities.'.$capability->value)]));
    }
}
