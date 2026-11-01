<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\TimeEntrySource;
use App\Modules\Staff\Events\ClockedIn;
use App\Modules\Staff\Events\ClockedOut;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as BaseCollection;

/** Clock in / clock out, timesheets, and manager corrections. */
class TimeClockService
{
    /** A shift longer than this is assumed forgotten and closed automatically. */
    public const MAX_SHIFT_HOURS = 16;

    public function __construct(private readonly TenantContext $context, private readonly RosterService $roster) {}

    public function openEntry(User $user): ?TimeEntry
    {
        return $user->openTimeEntry();
    }

    public function clockIn(User $user, TimeEntrySource $source = TimeEntrySource::Clock, ?string $tillId = null, ?CarbonInterface $at = null): TimeEntry
    {
        if ($user->openTimeEntry()) {
            throw new TimeClockException(__('staff::staff.errors.already_in', ['name' => $user->name]));
        }

        $entry = new TimeEntry([
            'user_id' => $user->id,
            'clocked_in_at' => $at ?? now(),
            'source' => $source,
            'till_id' => $tillId,
        ]);
        $entry->branch_id = $user->branch_id ?? $this->context->branchId();
        $entry->save();

        ClockedIn::dispatch($entry);

        return $entry;
    }

    public function clockOut(User $user, ?CarbonInterface $at = null): TimeEntry
    {
        $entry = $user->openTimeEntry();
        if (! $entry) {
            throw new TimeClockException(__('staff::staff.errors.not_in', ['name' => $user->name]));
        }

        return $this->close($entry, $at ?? now());
    }

    /**
     * Tap once: in if out, out if in.
     *
     * @return array{action: string, entry: TimeEntry}
     */
    public function toggle(User $user, TimeEntrySource $source = TimeEntrySource::Clock, ?string $tillId = null): array
    {
        return $user->openTimeEntry()
            ? ['action' => 'out', 'entry' => $this->clockOut($user)]
            : ['action' => 'in', 'entry' => $this->clockIn($user, $source, $tillId)];
    }

    /** @return Collection<int, TimeEntry> everyone at work right now */
    public function whoIsIn(?string $branchId = null): Collection
    {
        return TimeEntry::query()->open()->with('user')
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->orderBy('clocked_in_at')->get();
    }

    /** Close entries left open for too long (scheduler, and before every timesheet). Returns how many. */
    public function autoClockOut(?string $branchId = null, int $maxHours = self::MAX_SHIFT_HOURS): int
    {
        $stale = TimeEntry::query()->open()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('clocked_in_at', '<=', now()->subHours($maxHours))->get();

        foreach ($stale as $entry) {
            $this->close($entry, $entry->clocked_in_at->copy()->addHours($maxHours), TimeEntrySource::Auto, __('staff::staff.auto_clock_out_note', ['hours' => $maxHours]));
        }

        return $stale->count();
    }

    /** A manager corrects or adds an entry. Needs the staff-manage permission; the change and reason are audited. */
    public function adjust(?TimeEntry $entry, User $user, CarbonInterface $in, ?CarbonInterface $out, User $by, string $reason, int $breakMinutes = 0): TimeEntry
    {
        if (! $by->can(Permission::StaffManage->value)) {
            throw new ManagerPinRequiredException(Permission::StaffManage->value);
        }
        if (blank($reason)) {
            throw new TimeClockException(__('orders::orders.errors.reason_required'));
        }
        if ($out && $out->lessThanOrEqualTo($in)) {
            throw new TimeClockException(__('staff::staff.errors.out_before_in'));
        }
        if (! $out && $user->openTimeEntry()?->isNot($entry)) {
            throw new TimeClockException(__('staff::staff.errors.already_in', ['name' => $user->name]));
        }

        $entry ??= new TimeEntry(['user_id' => $user->id]);
        $entry->branch_id ??= $user->branch_id ?? $this->context->branchId();
        $entry->fill([
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
            'break_minutes' => max(0, $breakMinutes),
            'minutes' => $out ? max(0, (int) $in->diffInMinutes($out) - max(0, $breakMinutes)) : 0,
            'source' => $entry->exists ? $entry->source : TimeEntrySource::Admin,
            'edited_by_id' => $by->id,
            'edit_reason' => trim($reason),
        ]);
        $entry->save();

        return $entry;
    }

    public function delete(TimeEntry $entry, User $by, string $reason): void
    {
        if (! $by->can(Permission::StaffManage->value)) {
            throw new ManagerPinRequiredException(Permission::StaffManage->value);
        }
        $entry->forceFill(['edited_by_id' => $by->id, 'edit_reason' => trim($reason)])->save();
        $entry->delete();
    }

    /** @return Collection<int, TimeEntry> */
    public function entries(string $from, string $to, ?string $userId = null, ?string $branchId = null): Collection
    {
        return TimeEntry::query()->with(['user', 'till', 'editedBy'])
            ->where('branch_id', $branchId ?? $this->context->branchId())
            ->between($from, $to)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('clocked_in_at')->get();
    }

    /**
     * Hours per person for a date range: scheduled (roster) vs worked (clock), with the entries.
     *
     * @return BaseCollection<int, array{user: User, days: int, scheduled_minutes: int, worked_minutes: int, entries: Collection<int, TimeEntry>, open: bool}>
     */
    public function timesheet(string $from, string $to, ?string $branchId = null): BaseCollection
    {
        $branchId ??= $this->context->branchId();
        $entries = $this->entries($from, $to, null, $branchId)->groupBy('user_id');
        $scheduled = $this->roster->scheduledMinutes($from, $to, $branchId);

        return $this->roster->staff($branchId)->map(function (User $user) use ($entries, $scheduled) {
            $own = $entries->get($user->id, new Collection);

            return [
                'user' => $user,
                'days' => $own->map(fn (TimeEntry $e) => $e->clocked_in_at->toDateString())->unique()->count(),
                'scheduled_minutes' => $scheduled[$user->id] ?? 0,
                'worked_minutes' => $own->sum(fn (TimeEntry $e) => $e->workedMinutes()),
                'entries' => $own,
                'open' => $own->contains(fn (TimeEntry $e) => $e->isOpen()),
            ];
        })->filter(fn (array $row) => $row['entries']->isNotEmpty() || $row['scheduled_minutes'] > 0)->values();
    }

    private function close(TimeEntry $entry, CarbonInterface $at, ?TimeEntrySource $source = null, ?string $note = null): TimeEntry
    {
        $at = Carbon::instance($at);
        if ($at->lessThan($entry->clocked_in_at)) {
            $at = $entry->clocked_in_at->copy();
        }

        $entry->forceFill([
            'clocked_out_at' => $at,
            'minutes' => max(0, (int) $entry->clocked_in_at->diffInMinutes($at) - $entry->break_minutes),
            'source' => $source ?? $entry->source,
            'notes' => $note ?? $entry->notes,
        ])->save();

        ClockedOut::dispatch($entry);

        return $entry;
    }
}
