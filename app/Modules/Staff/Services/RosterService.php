<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/** Weekly rosters: who works when. Weeks start on Monday. */
class RosterService
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return Collection<int, User> active staff who can work at the branch (owners without a branch included) */
    public function staff(?string $branchId = null): Collection
    {
        $branchId ??= $this->context->branchId();

        return User::query()->active()->with('roles')
            ->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
            ->orderBy('name')->get();
    }

    public static function weekStart(string|CarbonImmutable|null $date = null): CarbonImmutable
    {
        return CarbonImmutable::parse($date ?? 'now')->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /** @return Collection<int, RosterShift> shifts of a week, in time order */
    public function week(CarbonImmutable $weekStart, ?string $branchId = null): Collection
    {
        return RosterShift::query()->with('user')
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->between($weekStart->toDateString(), $weekStart->addDays(6)->toDateString())
            ->orderBy('shift_date')->orderBy('starts_at')->get();
    }

    public function addShift(User $user, string $date, string $startsAt, string $endsAt, ?string $role = null, ?string $notes = null, ?string $branchId = null): RosterShift
    {
        $this->validateTimes($startsAt, $endsAt);
        if ($role !== null && $role !== '' && Role::tryFrom($role) === null) {
            throw new TimeClockException(__('staff::staff.errors.bad_role'));
        }

        $shift = new RosterShift([
            'user_id' => $user->id,
            'shift_date' => $date,
            'starts_at' => RosterShift::hm($startsAt),
            'ends_at' => RosterShift::hm($endsAt),
            'role' => $role ?: null,
            'notes' => filled($notes) ? trim($notes) : null,
        ]);
        $shift->branch_id = $branchId ?? $user->branch_id ?? $this->context->branchId();
        $shift->save();

        return $shift;
    }

    public function updateShift(RosterShift $shift, string $date, string $startsAt, string $endsAt, ?string $role = null, ?string $notes = null): RosterShift
    {
        $this->validateTimes($startsAt, $endsAt);
        $shift->fill([
            'shift_date' => $date,
            'starts_at' => RosterShift::hm($startsAt),
            'ends_at' => RosterShift::hm($endsAt),
            'role' => $role ?: null,
            'notes' => filled($notes) ? trim($notes) : null,
        ])->save();

        return $shift;
    }

    public function deleteShift(RosterShift $shift): void
    {
        $shift->delete();
    }

    /** Copy every shift of one week onto another (skips shifts already there). Returns how many were added. */
    public function copyWeek(CarbonImmutable $from, CarbonImmutable $to, ?string $branchId = null): int
    {
        $branchId ??= $this->context->branchId();
        $existing = $this->week($to, $branchId);
        $added = 0;

        foreach ($this->week($from, $branchId) as $shift) {
            $date = $shift->shift_date->toImmutable()->addDays((int) $from->diffInDays($to));
            $duplicate = $existing->first(fn (RosterShift $s) => $s->user_id === $shift->user_id && $s->shift_date->isSameDay($date) && $s->starts_at === $shift->starts_at);
            if ($duplicate) {
                continue;
            }
            $copy = $shift->replicate(['id']);
            $copy->shift_date = $date->toDateString();
            $copy->branch_id = $branchId;
            $copy->save();
            $added++;
        }

        return $added;
    }

    /** @return array<string, int> user id → scheduled minutes in the date range */
    public function scheduledMinutes(string $from, string $to, ?string $branchId = null): array
    {
        return RosterShift::query()
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->between($from, $to)->get()
            ->groupBy('user_id')
            ->map(fn (BaseCollection $shifts) => $shifts->sum(fn (RosterShift $s) => $s->minutes()))
            ->all();
    }

    /** Today's shifts for one person (for the clock screen greeting). */
    public function todayFor(User $user): Collection
    {
        return $user->rosterShifts()->whereDate('shift_date', now()->toDateString())->orderBy('starts_at')->get();
    }

    private function validateTimes(string $startsAt, string $endsAt): void
    {
        foreach ([$startsAt, $endsAt] as $time) {
            if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
                throw new TimeClockException(__('staff::staff.errors.bad_time'));
            }
        }
    }
}
