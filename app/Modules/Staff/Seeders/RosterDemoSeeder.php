<?php

namespace App\Modules\Staff\Seeders;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Enums\TimeEntrySource;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\RosterService;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonImmutable;

/** This week's and next week's roster for the demo staff, plus yesterday's clock-ins. Re-runnable. */
class RosterDemoSeeder
{
    /** role → [earlyStart, earlyEnd, lateStart, lateEnd, daysOff (0 = Monday)] */
    private const PATTERNS = [
        'manager' => ['09:00', '18:00', '09:00', '18:00', [6]],
        'cashier' => ['08:00', '16:00', '14:00', '22:00', [1]],
        'waiter' => ['10:00', '18:00', '16:00', '00:00', [2]],
        'kitchen' => ['07:00', '15:00', '13:00', '21:00', [3]],
        'rider' => ['11:00', '21:00', '11:00', '21:00', [0]],
        'inventory' => ['08:00', '16:00', '08:00', '16:00', [5, 6]],
    ];

    /** @return array{shifts: int, entries: int} */
    public function run(): array
    {
        $staff = User::query()->active()->with('roles')->where('branch_id', Tenancy::branchId())->orderBy('name')->get();
        $thisWeek = RosterService::weekStart();
        $shifts = 0;

        foreach ([$thisWeek, $thisWeek->addWeek()] as $week) {
            foreach ($staff as $i => $user) {
                $role = $user->roles->first()?->name;
                $pattern = self::PATTERNS[$role] ?? null;
                if (! $pattern) {
                    continue;
                }
                [$es, $ee, $ls, $le, $off] = $pattern;
                $late = $i % 2 === 1; // every second person takes the late shift
                for ($d = 0; $d < 7; $d++) {
                    if (in_array($d, $off, true)) {
                        continue;
                    }
                    $shift = RosterShift::query()->updateOrCreate(
                        ['user_id' => $user->id, 'shift_date' => $week->addDays($d)->toDateString(), 'starts_at' => $late ? $ls : $es],
                        ['ends_at' => $late ? $le : $ee, 'role' => $role, 'branch_id' => Tenancy::branchId()],
                    );
                    $shifts++;
                }
            }
        }

        // Yesterday everyone rostered worked their shift (a few minutes early or late).
        $entries = 0;
        $yesterday = CarbonImmutable::yesterday();
        foreach (RosterShift::query()->whereDate('shift_date', $yesterday)->get() as $shift) {
            $in = $shift->startsAt()->subMinutes(5 + $entries % 7);
            $out = $shift->endsAt()->addMinutes($entries % 11);
            TimeEntry::query()->updateOrCreate(
                ['user_id' => $shift->user_id, 'clocked_in_at' => $in],
                ['clocked_out_at' => $out, 'minutes' => (int) $in->diffInMinutes($out), 'source' => TimeEntrySource::Clock, 'branch_id' => Tenancy::branchId()],
            );
            $entries++;
        }

        return ['shifts' => $shifts, 'entries' => $entries];
    }

    public static function roleOf(User $user): ?Role
    {
        return Role::tryFrom($user->roles->first()?->name ?? '');
    }
}
