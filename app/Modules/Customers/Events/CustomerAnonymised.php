<?php

namespace App\Modules\Customers\Events;

use App\Modules\Customers\Models\Customer;
use App\Support\Outbox\ShouldRecordToOutbox;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer's personal details were removed (E9.3, E9.6 review F1). Recorded in the outbox so that, once the
 * cloud link exists, the cloud copy of this customer is cleaned too. Carries the id only.
 */
class CustomerAnonymised implements ShouldRecordToOutbox
{
    use Dispatchable;

    public function __construct(public readonly Customer $customer) {}

    public function outboxEventType(): string
    {
        return 'customers.anonymised';
    }

    public function outboxPayload(): array
    {
        return ['customer_id' => $this->customer->id, 'anonymised_at' => $this->customer->anonymised_at?->toIso8601String()];
    }

    public function outboxTenantId(): ?string
    {
        return $this->customer->tenant_id;
    }

    public function outboxBranchId(): ?string
    {
        return null;
    }
}
