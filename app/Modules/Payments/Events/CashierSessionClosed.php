<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CashierSession;

class CashierSessionClosed extends CashEvent
{
    public function __construct(public readonly CashierSession $session, ?string $byUserId = null)
    {
        parent::__construct($session, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'cash.session_closed';
    }

    protected function extraPayload(): array
    {
        return [
            'expected_cash' => $this->session->expected_cash?->amount,
            'counted_cash' => $this->session->counted_cash?->amount,
            'variance' => $this->session->variance,
        ];
    }
}
