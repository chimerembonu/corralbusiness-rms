<?php

namespace App\Modules\Payments\Gateways\Data;

enum GatewayStatus: string
{
    case Pending = 'pending';           // waiting for the customer to approve on their phone
    case NeedsOtp = 'needs_otp';        // the network wants a one-time code (e.g. Telecel voucher)
    case Succeeded = 'succeeded';       // money received
    case Failed = 'failed';             // declined, wrong PIN, not enough money…
    case Expired = 'expired';           // nobody approved in time
    case Cancelled = 'cancelled';       // the cashier gave up waiting (we keep checking in case it still arrives)
    case NeedsAttention = 'needs_attention'; // money arrived but could not be attached to the order: check the reconciliation report

    public function isFinal(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Expired, self::NeedsAttention], true);
    }

    public function label(): string
    {
        return __('payments::payments.gateway.status.'.$this->value);
    }
}
