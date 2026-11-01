<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\CashMovementType;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'cashier_session_id', 'type', 'amount', 'reason', 'reference', 'user_id', 'happened_at'];

    protected function casts(): array
    {
        return ['type' => CashMovementType::class, 'amount' => MoneyCast::class, 'happened_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'cashier_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPayIn(): bool
    {
        return $this->type === CashMovementType::PayIn;
    }
}
