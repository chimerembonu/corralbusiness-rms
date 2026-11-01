<?php

namespace App\Modules\Staff\Models;

use App\Modules\Pos\Models\Till;
use App\Modules\Staff\Enums\TimeEntrySource;
use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'user_id', 'clocked_in_at', 'clocked_out_at', 'minutes', 'break_minutes', 'source', 'till_id', 'photo_path', 'edited_by_id', 'edit_reason', 'notes'];

    protected $attributes = ['minutes' => 0, 'break_minutes' => 0, 'source' => 'clock'];

    protected function casts(): array
    {
        return ['clocked_in_at' => 'datetime', 'clocked_out_at' => 'datetime', 'source' => TimeEntrySource::class, 'minutes' => 'integer', 'break_minutes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clocked_out_at');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('clocked_in_at', [$from.' 00:00:00', $to.' 23:59:59']);
    }

    public function isOpen(): bool
    {
        return $this->clocked_out_at === null;
    }

    /** Minutes worked so far (live for an open entry). */
    public function workedMinutes(): int
    {
        if (! $this->isOpen()) {
            return $this->minutes;
        }

        return max(0, (int) $this->clocked_in_at->diffInMinutes(now()) - $this->break_minutes);
    }

    public static function hoursLabel(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
