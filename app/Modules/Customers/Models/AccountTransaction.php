<?php

namespace App\Modules\Customers\Models;

use App\Modules\Customers\Enums\AccountTransactionType;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Staff\Models\User;
use App\Support\Models\BusinessModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only ledger of a customer's account. */
class AccountTransaction extends BusinessModel
{
    protected $fillable = ['branch_id', 'customer_id', 'type', 'amount', 'balance_after', 'order_id', 'payment_id', 'reference', 'notes', 'user_id', 'happened_at'];

    protected function casts(): array
    {
        return ['type' => AccountTransactionType::class, 'amount' => 'integer', 'balance_after' => 'integer', 'happened_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function money(): Money
    {
        return Money::of(abs($this->amount));
    }
}
