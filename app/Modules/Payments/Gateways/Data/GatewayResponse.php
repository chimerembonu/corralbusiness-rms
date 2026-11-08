<?php

namespace App\Modules\Payments\Gateways\Data;

/** What the provider said, translated into the app's words. 'details' is a small, safe summary — never keys or card data. */
final class GatewayResponse
{
    public function __construct(
        public readonly GatewayStatus $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
        public readonly ?string $checkoutUrl = null,
        public readonly array $details = [],
    ) {}

    public static function pending(?string $providerReference = null, ?string $message = null, array $details = []): self
    {
        return new self(GatewayStatus::Pending, $providerReference, $message, null, $details);
    }

    public static function succeeded(?string $providerReference = null, ?string $message = null, array $details = []): self
    {
        return new self(GatewayStatus::Succeeded, $providerReference, $message, null, $details);
    }

    public static function failed(?string $message = null, ?string $providerReference = null, array $details = []): self
    {
        return new self(GatewayStatus::Failed, $providerReference, $message, null, $details);
    }
}
