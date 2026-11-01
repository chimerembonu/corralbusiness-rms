<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\CashMovement;

class CashMovementRecorded extends CashEvent
{
    public function __construct(public readonly CashMovement $movement, ?string $byUserId = null)
    {
        parent::__construct($movement, $byUserId);
    }

    public function outboxEventType(): string
    {
        return 'cash.movement_recorded';
    }

    protected function extraPayload(): array
    {
        return ['session_id' => $this->movement->cashier_session_id, 'type' => $this->movement->type->value, 'amount' => $this->movement->amount->amount, 'reason' => $this->movement->reason];
    }
}
