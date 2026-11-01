<?php

namespace App\Modules\Customers\Events;

use App\Modules\Customers\Models\AccountTransaction;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

abstract class AccountEvent implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly AccountTransaction $transaction, public readonly ?string $byUserId = null) {}

    public function outboxPayload(): array
    {
        return [
            'transaction_id' => $this->transaction->id,
            'customer_id' => $this->transaction->customer_id,
            'type' => $this->transaction->type->value,
            'amount' => $this->transaction->amount,
            'balance_after' => $this->transaction->balance_after,
            'order_id' => $this->transaction->order_id,
            'payment_id' => $this->transaction->payment_id,
            'by_user_id' => $this->byUserId,
        ];
    }

    public function outboxTenantId(): ?string
    {
        return $this->transaction->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return $this->transaction->branch_id;
    }
}
