<?php

namespace App\Modules\Payments\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'order_id', 'method', 'amount', 'tendered', 'change_given', 'reference', 'status', 'received_by_id', 'received_at', 'notes'];

    protected $attributes = ['change_given' => 0, 'status' => 'completed'];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => MoneyCast::class,
            'tendered' => MoneyCast::class,
            'change_given' => MoneyCast::class,
            'status' => PaymentStatus::class,
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }
}
