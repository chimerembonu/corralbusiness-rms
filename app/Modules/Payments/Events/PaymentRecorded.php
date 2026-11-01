<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\Payment;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentRecorded implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}

    public function outboxEventType(): string
    {
        return 'payments.recorded';
    }

    public function outboxPayload(): array
    {
        return [
            'payment_id' => $this->payment->id,
            'order_id' => $this->payment->order_id,
            'method' => $this->payment->method->value,
            'provider' => $this->payment->provider?->value,
            'tip' => $this->payment->tip->amount,
            'amount' => $this->payment->amount->amount,
            'tendered' => $this->payment->tendered?->amount,
            'change_given' => $this->payment->change_given->amount,
            'reference' => $this->payment->reference,
            'received_by_id' => $this->payment->received_by_id,
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
