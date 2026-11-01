<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\StockTakeStatus;
use App\Modules\Staff\Models\User;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTake extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'number', 'status', 'category', 'started_at', 'started_by_id', 'completed_at', 'completed_by_id', 'variance_value', 'notes'];

    protected $attributes = ['status' => 'open', 'variance_value' => 0];

    protected function casts(): array
    {
        return ['status' => StockTakeStatus::class, 'started_at' => 'datetime', 'completed_at' => 'datetime', 'variance_value' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTakeLine::class)->orderBy('sort_order');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', StockTakeStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === StockTakeStatus::Open;
    }

    public function displayNumber(): string
    {
        return 'ST'.str_pad((string) $this->number, 4, '0', STR_PAD_LEFT);
    }
}
