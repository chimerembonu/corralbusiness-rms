<?php

namespace App\Modules\Staff\Models;

use App\Support\Audit\Auditable;
use App\Support\Models\BusinessModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterShift extends BusinessModel
{
    use Auditable;

    protected $fillable = ['branch_id', 'user_id', 'shift_date', 'starts_at', 'ends_at', 'role', 'notes'];

    protected function casts(): array
    {
        return ['shift_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereDate('shift_date', '>=', $from)->whereDate('shift_date', '<=', $to);
    }

    /** "09:00" from a stored time. */
    public static function hm(string $time): string
    {
        return substr($time, 0, 5);
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->shift_date->toDateString().' '.self::hm($this->starts_at));
    }

    /** The end moment; a shift ending at or before its start finishes the next day. */
    public function endsAt(): CarbonImmutable
    {
        $end = CarbonImmutable::parse($this->shift_date->toDateString().' '.self::hm($this->ends_at));

        return $end->lessThanOrEqualTo($this->startsAt()) ? $end->addDay() : $end;
    }

    public function minutes(): int
    {
        return (int) $this->startsAt()->diffInMinutes($this->endsAt());
    }

    /** "09:00–17:00" */
    public function timeLabel(): string
    {
        return self::hm($this->starts_at).'–'.self::hm($this->ends_at);
    }
}
