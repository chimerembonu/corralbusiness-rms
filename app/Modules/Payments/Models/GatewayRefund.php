<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money going back to a customer through the provider that took it, or to be given back by hand (E2.4). */
class GatewayRefund extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'gateway_transaction_id', 'payment_id', 'gateway', 'reference', 'provider_reference', 'amount', 'status',
        'message', 'reason', 'approved_by_id', 'done_by_hand_by_id', 'done_by_hand_note', 'done_by_hand_method', 'cash_movement_id', 'attempts', 'checks', 'next_check_at', 'completed_at'];

    protected $attributes = ['status' => 'queued', 'attempts' => 0, 'checks' => 0];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => RefundStatus::class,
            'next_check_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(GatewayTransaction::class, 'gateway_transaction_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function doneByHandBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_hand_by_id');
    }

    /** Waiting to be sent, or sent and not finished: the background check moves these along. */
    public function scopeDue(Builder $q): Builder
    {
        return $q->whereIn('status', [RefundStatus::Queued->value, RefundStatus::Sent->value])
            ->where(fn ($w) => $w->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()));
    }

    /** Refunds a person must still give back. */
    public function scopeNeedsHand(Builder $q): Builder
    {
        return $q->whereIn('status', [RefundStatus::Failed->value, RefundStatus::ByHand->value]);
    }
}
