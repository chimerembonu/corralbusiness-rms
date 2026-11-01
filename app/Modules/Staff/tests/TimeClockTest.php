<?php

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Enums\TimeEntrySource;
use App\Modules\Staff\Events\ClockedIn;
use App\Modules\Staff\Events\ClockedOut;
use App\Modules\Staff\Exceptions\ManagerPinRequiredException;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\RosterService;
use App\Modules\Staff\Services\TimeClockService;
use App\Support\Outbox\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->ama = User::factory()->forBranch($this->branch)->withPin('2222')->create(['name' => 'Ama']);
    $this->ama->assignRole(Role::Waiter->value);
    $this->manager = User::factory()->forBranch($this->branch)->withPin('2468')->create(['name' => 'Akosua']);
    $this->manager->assignRole(Role::Manager->value);
    $this->clock = app(TimeClockService::class);
    $this->roster = app(RosterService::class);
});

it('clocks in and out, records the minutes and writes the outbox', function () {
    Carbon::setTestNow('2026-11-02 08:58:00');
    $in = $this->clock->clockIn($this->ama, TimeEntrySource::Clock);
    expect($in->isOpen())->toBeTrue()->and($this->ama->openTimeEntry()?->id)->toBe($in->id)
        ->and($this->clock->whoIsIn()->pluck('user_id')->all())->toBe([$this->ama->id]);
    expect(fn () => $this->clock->clockIn($this->ama))->toThrow(TimeClockException::class, 'already clocked in');

    Carbon::setTestNow('2026-11-02 17:28:00');
    expect($in->fresh()->workedMinutes())->toBe(510);
    $out = $this->clock->clockOut($this->ama);
    expect($out->minutes)->toBe(510)->and($out->isOpen())->toBeFalse()->and($this->clock->whoIsIn())->toHaveCount(0)
        ->and(TimeEntry::hoursLabel($out->minutes))->toBe('8h 30m');
    expect(fn () => $this->clock->clockOut($this->ama))->toThrow(TimeClockException::class, 'not clocked in');

    expect(OutboxEvent::query()->whereIn('event_type', ['staff.clocked_in', 'staff.clocked_out'])->count())->toBe(2);
    Carbon::setTestNow();
});

it('toggles with one tap and closes forgotten clock-ins automatically', function () {
    Event::fake([ClockedIn::class, ClockedOut::class]);
    Carbon::setTestNow('2026-11-02 09:00:00');
    expect($this->clock->toggle($this->ama, TimeEntrySource::Pos)['action'])->toBe('in');
    Carbon::setTestNow('2026-11-02 12:00:00');
    expect($this->clock->toggle($this->ama)['action'])->toBe('out');
    Event::assertDispatched(ClockedIn::class);
    Event::assertDispatched(ClockedOut::class);

    Carbon::setTestNow('2026-11-02 18:00:00');
    $this->clock->clockIn($this->ama);
    Carbon::setTestNow('2026-11-03 09:00:00');
    expect($this->clock->autoClockOut())->toBe(0); // 15 h: still plausible
    Carbon::setTestNow('2026-11-03 11:00:00');
    expect($this->clock->autoClockOut())->toBe(1);
    $entry = $this->ama->timeEntries()->latest('clocked_in_at')->first();
    expect($entry->isOpen())->toBeFalse()->and($entry->minutes)->toBe(16 * 60)->and($entry->source)->toBe(TimeEntrySource::Auto)
        ->and($entry->notes)->toContain('forgot to clock out');
    Carbon::setTestNow();
});

it('lets a manager correct an entry with a reason, and audits it', function () {
    Carbon::setTestNow('2026-11-02 09:03:00');
    $entry = $this->clock->clockIn($this->ama);
    Carbon::setTestNow('2026-11-02 17:00:00');
    $this->clock->clockOut($this->ama);

    expect(fn () => $this->clock->adjust($entry, $this->ama, CarbonImmutable::parse('2026-11-02 09:00'), CarbonImmutable::parse('2026-11-02 17:00'), $this->ama, 'late'))->toThrow(ManagerPinRequiredException::class);
    expect(fn () => $this->clock->adjust($entry, $this->ama, CarbonImmutable::parse('2026-11-02 09:00'), CarbonImmutable::parse('2026-11-02 08:00'), $this->manager, 'typo'))->toThrow(TimeClockException::class, 'after clock-in');
    expect(fn () => $this->clock->adjust($entry, $this->ama, CarbonImmutable::parse('2026-11-02 09:00'), null, $this->manager, ''))->toThrow(TimeClockException::class);

    $fixed = $this->clock->adjust($entry, $this->ama, CarbonImmutable::parse('2026-11-02 09:00'), CarbonImmutable::parse('2026-11-02 17:30'), $this->manager, 'Forgot to clock out at 17:30', 30);
    expect($fixed->minutes)->toBe(480)->and($fixed->break_minutes)->toBe(30)->and($fixed->edited_by_id)->toBe($this->manager->id)
        ->and(Activity::query()->where('log_name', 'time_entry')->where('event', 'updated')->count())->toBeGreaterThanOrEqual(1);

    $added = $this->clock->adjust(null, $this->ama, CarbonImmutable::parse('2026-11-01 10:00'), CarbonImmutable::parse('2026-11-01 14:00'), $this->manager, 'Clock screen was down');
    expect($added->source)->toBe(TimeEntrySource::Admin)->and($added->minutes)->toBe(240);
    Carbon::setTestNow();
});

it('builds a timesheet of scheduled versus worked hours', function () {
    $this->roster->addShift($this->ama, '2026-11-02', '09:00', '17:00');
    $this->roster->addShift($this->ama, '2026-11-03', '16:00', '00:00', 'waiter', 'Late'); // overnight: 8 h
    $this->roster->addShift($this->manager, '2026-11-02', '09:00', '18:00');
    expect(RosterShift::query()->where('user_id', $this->ama->id)->get()->sum(fn (RosterShift $s) => $s->minutes()))->toBe(960);

    Carbon::setTestNow('2026-11-02 09:00:00');
    $this->clock->clockIn($this->ama);
    Carbon::setTestNow('2026-11-02 16:30:00');
    $this->clock->clockOut($this->ama);
    Carbon::setTestNow('2026-11-03 16:00:00');
    $this->clock->clockIn($this->ama);
    Carbon::setTestNow('2026-11-03 18:00:00'); // still in: 2 h so far

    $sheet = $this->clock->timesheet('2026-11-02', '2026-11-08');
    $ama = $sheet->firstWhere('user.id', $this->ama->id);
    $akosua = $sheet->firstWhere('user.id', $this->manager->id);

    expect($ama['scheduled_minutes'])->toBe(960)->and($ama['worked_minutes'])->toBe(450 + 120)->and($ama['days'])->toBe(2)->and($ama['open'])->toBeTrue()
        ->and($akosua['scheduled_minutes'])->toBe(540)->and($akosua['worked_minutes'])->toBe(0)->and($akosua['entries'])->toHaveCount(0)
        ->and($this->clock->timesheet('2026-10-01', '2026-10-07'))->toHaveCount(0);
    Carbon::setTestNow();
});

it('manages the weekly roster and copies it forward', function () {
    $monday = RosterService::weekStart('2026-11-04'); // Wed → Mon 2 Nov
    expect($monday->toDateString())->toBe('2026-11-02');

    $shift = $this->roster->addShift($this->ama, '2026-11-02', '09:00', '17:00', 'waiter');
    $this->roster->addShift($this->manager, '2026-11-05', '09:00', '18:00');
    expect(fn () => $this->roster->addShift($this->ama, '2026-11-02', '9am', '17:00'))->toThrow(TimeClockException::class);
    expect(fn () => $this->roster->addShift($this->ama, '2026-11-02', '09:00', '17:00', 'boss'))->toThrow(TimeClockException::class);

    $this->roster->updateShift($shift, '2026-11-02', '10:00', '18:30', null, 'Lunch rush');
    expect($shift->fresh()->timeLabel())->toBe('10:00–18:30')->and($shift->fresh()->role)->toBeNull();

    expect($this->roster->copyWeek($monday, $monday->addWeek()))->toBe(2)
        ->and($this->roster->copyWeek($monday, $monday->addWeek()))->toBe(0) // no duplicates
        ->and($this->roster->week($monday->addWeek())->map(fn (RosterShift $s) => $s->shift_date->toDateString())->all())->toBe(['2026-11-09', '2026-11-12'])
        ->and($this->roster->scheduledMinutes('2026-11-09', '2026-11-15')[$this->ama->id])->toBe(510);

    $this->roster->deleteShift($shift);
    expect($this->roster->week($monday))->toHaveCount(1);
});
