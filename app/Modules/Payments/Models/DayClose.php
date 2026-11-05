<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\DayCloseStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Z report: the day's totals frozen at close. */
class DayClose extends BusinessModel
{
    use Auditable;

    /** The report snapshot is large and never changes; keep it out of the activity log. */
    protected array $auditExcept = ['report'];

    protected $fillable = ['branch_id', 'number', 'business_date', 'status', 'closed_at', 'closed_by_id', 'approved_by_id', 'second_approved_by_id', 'reopened_at', 'reopened_by_id', 'reopen_reason', 'report', 'notes'];

    protected $attributes = ['status' => 'closed'];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'status' => DayCloseStatus::class,
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'report' => 'array',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    public function secondApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'second_approved_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by_id');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', DayCloseStatus::Closed->value);
    }

    public function isClosed(): bool
    {
        return $this->status === DayCloseStatus::Closed;
    }

    public function displayNumber(): string
    {
        return 'Z'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }
}
