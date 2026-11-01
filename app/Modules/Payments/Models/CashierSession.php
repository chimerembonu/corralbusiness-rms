<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\CashierSessionStatus;
use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierSession extends BusinessModel
{
    use Auditable;

    protected $fillable = [
        'branch_id', 'number', 'business_date', 'till_id', 'user_id', 'status', 'opening_float', 'opened_at',
        'closed_at', 'closed_by_id', 'approved_by_id', 'cash_sales', 'cash_tips', 'cash_refunds', 'pay_ins', 'pay_outs',
        'expected_cash', 'counted_cash', 'variance', 'counted_breakdown', 'totals', 'notes',
    ];

    protected $attributes = ['status' => 'open', 'cash_sales' => 0, 'cash_tips' => 0, 'cash_refunds' => 0, 'pay_ins' => 0, 'pay_outs' => 0];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => CashierSessionStatus::class,
            'opening_float' => MoneyCast::class,
            'cash_sales' => MoneyCast::class,
            'cash_tips' => MoneyCast::class,
            'cash_refunds' => MoneyCast::class,
            'pay_ins' => MoneyCast::class,
            'pay_outs' => MoneyCast::class,
            'expected_cash' => MoneyCast::class,
            'counted_cash' => MoneyCast::class,
            'variance' => 'integer',
            'counted_breakdown' => 'array',
            'totals' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('happened_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CashierSessionStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === CashierSessionStatus::Open;
    }

    public function displayNumber(): string
    {
        return 'S'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }

    /** "Till 1 – Counter · Kofi" */
    public function label(): string
    {
        return trim(($this->till?->name ?? __('payments::payments.cash.no_till')).' · '.($this->user?->name ?? ''), ' ·');
    }
}
