<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentRefunded implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}

    public function outboxEventType(): string
    {
        return 'payments.refunded';
    }

    public function outboxPayload(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'amount' => $this->payment->amount->amount,
            'reason' => $this->payment->refund_reason,
            'refunded_by_id' => $this->payment->refunded_by_id,
        ];
    }

    public function outboxTenantId(): ?string
    {
        return $this->payment->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->payment->branch_id;
    }
}
