<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CashierSession;

class CashierSessionOpened extends CashEvent
{
    public function __construct(public readonly CashierSession $session, ?string $byUserId = null)
    {
        parent::__construct($session, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'cash.session_opened';
    }

    protected function extraPayload(): array
    {
        return ['till_id' => $this->session->till_id, 'opening_float' => $this->session->opening_float->amount];
    }
}
