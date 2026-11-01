<?php

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Filament\Pages\RosterPage;
use App\Modules\Staff\Filament\Pages\StaffSalesPage;
use App\Modules\Staff\Filament\Pages\TimesheetsPage;
use App\Modules\Staff\Filament\Resources\Staff\Pages\CreateStaff;
use App\Modules\Staff\Filament\Resources\Staff\Pages\EditStaff;
use App\Modules\Staff\Filament\Resources\Staff\Pages\ListStaff;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Modules\Staff\Services\RosterService;
use App\Modules\Staff\Services\TimeClockService;
use App\Support\Tenancy\Tenancy;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    seedRoles();
    $this->branch = provisionTenant();
    $this->owner = User::factory()->forBranch($this->branch)->withPin('1234')->create(['name' => 'Chimere']);
    $this->owner->assignRole(Role::Owner->value);
    $this->actingAs($this->owner);
});

it('creates a staff member with roles and a PIN, and refuses a PIN already in use', function () {
    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Yaw Asante', 'phone' => '+233240000001', 'job_title' => 'Head waiter', 'roles' => ['waiter', 'cashier'], 'new_pin' => '1234', 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['new_pin']); // the owner already uses 1234

    Livewire::test(CreateStaff::class)
        ->fillForm(['name' => 'Yaw Asante', 'phone' => '+233240000001', 'job_title' => 'Head waiter', 'roles' => ['waiter', 'cashier'], 'new_pin' => '2001', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Tenancy::set($this->branch->tenant, $this->branch);
    $yaw = User::query()->where('name', 'Yaw Asante')->first();
    expect($yaw)->not->toBeNull()
        ->and($yaw->branch_id)->toBe($this->branch->id)
        ->and($yaw->hasPin())->toBeTrue()
        ->and($yaw->getRoleNames()->sort()->values()->all())->toBe(['cashier', 'waiter'])
        ->and(app(PinAuthenticator::class)->attempt('2001', 'till:test')->id)->toBe($yaw->id);

    $this->get('/admin/staff')->assertOk()->assertSee('Yaw Asante')->assertSee('Head waiter');
    Livewire::test(ListStaff::class)->callAction(TestAction::make('resetPin')->table($yaw), ['new_pin' => '7777'])->assertHasNoErrors();
    expect(app(PinAuthenticator::class)->attempt('7777', 'till:test')->id)->toBe($yaw->id);

    Livewire::test(EditStaff::class, ['record' => $yaw->id])
        ->assertFormSet(['roles' => ['cashier', 'waiter']])
        ->fillForm(['roles' => ['waiter'], 'is_active' => false])
        ->call('save')->assertHasNoFormErrors();
    expect($yaw->fresh()->is_active)->toBeFalse()->and($yaw->fresh()->getRoleNames()->all())->toBe(['waiter']);

    Livewire::test(EditStaff::class, ['record' => $this->owner->id])->fillForm(['is_active' => false])->call('save')->assertHasFormErrors(['is_active']);
});

it('keeps staff pages for managers only', function () {
    $waiter = User::factory()->forBranch($this->branch)->create();
    $waiter->assignRole(Role::Waiter->value);
    $this->actingAs($waiter)->get('/admin/staff')->assertForbidden();
    $this->actingAs($waiter)->get('/admin/roster')->assertForbidden();
});

it('builds the weekly roster in the back office and prints it', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $ama = User::factory()->forBranch($this->branch)->create(['name' => 'Ama']);
    $ama->assignRole(Role::Waiter->value);

    Livewire::test(RosterPage::class, ['week' => '2026-11-02'])
        ->assertSee('Week of 2 November 2026')->assertSee('Ama')
        ->callAction('addShift', ['user_id' => $ama->id, 'shift_date' => '2026-11-03', 'starts_at' => '10:00', 'ends_at' => '18:00', 'role' => 'waiter'])
        ->assertHasNoErrors()
        ->assertSee('10:00–18:00')
        ->call('nextWeek')->assertSet('week', '2026-11-09')->assertDontSee('10:00–18:00')
        ->callAction('copyLastWeek')->assertHasNoErrors()->assertSee('10:00–18:00');

    $copy = RosterShift::query()->whereDate('shift_date', '2026-11-10')->first();
    expect($copy)->not->toBeNull();

    Livewire::test(RosterPage::class, ['week' => '2026-11-09'])
        ->callAction('editShift', ['shift_date' => '2026-11-10', 'starts_at' => '11:00', 'ends_at' => '19:00', 'user_id' => $ama->id], ['shift' => $copy->id])
        ->assertHasNoErrors();
    expect($copy->fresh()->timeLabel())->toBe('11:00–19:00');

    $this->get('/staff/roster/print?week=2026-11-09')->assertOk()->assertSee('Ama')->assertSee('11:00–19:00')->assertSee('A4 landscape', false);
});

it('shows timesheets, corrects an entry and exports to Excel', function () {
    Tenancy::set($this->branch->tenant, $this->branch);
    $ama = User::factory()->forBranch($this->branch)->create(['name' => 'Ama']);
    $ama->assignRole(Role::Waiter->value);
    app(RosterService::class)->addShift($ama, '2026-11-02', '09:00', '17:00');
    Carbon::setTestNow('2026-11-02 09:00:00');
    app(TimeClockService::class)->clockIn($ama);
    Carbon::setTestNow('2026-11-02 16:00:00');
    app(TimeClockService::class)->clockOut($ama);
    Carbon::setTestNow('2026-11-04 12:00:00');

    $page = Livewire::test(TimesheetsPage::class, ['from' => '2026-11-02', 'to' => '2026-11-08'])
        ->assertSee('Ama')->assertSee('8h 00m')->assertSee('7h 00m')->assertSee('-1h 00m')
        ->call('toggle', $ama->id)->assertSee('09:00')->assertSee('Clock screen');

    $entry = TimeEntry::query()->first();
    $page->callAction('editEntry', ['user_id' => $ama->id, 'clocked_in_at' => '2026-11-02 09:00', 'clocked_out_at' => '2026-11-02 17:00', 'break_minutes' => 0, 'reason' => 'Forgot to clock out'], ['entry' => $entry->id])
        ->assertHasNoErrors()
        ->assertSee('+0h 00m')->assertSee('Forgot to clock out');
    expect($entry->fresh()->minutes)->toBe(480)->and($entry->fresh()->edited_by_id)->toBe($this->owner->id);

    Excel::fake();
    $page->callAction('export')->assertHasNoErrors();
    Excel::assertDownloaded('timesheets-2026-11-02-to-2026-11-08.xlsx', function ($export) {
        $rows = $export->array();

        return $rows[0][0] === 'Ama' && $rows[0][6] === 8.0 && $rows[1][2] === 'TOTAL' && $rows[1][8] === 8.0;
    });
    Carbon::setTestNow();
});

it('shows sales per staff with an Excel export', function () {
    $this->get('/admin/staff-sales')->assertOk()->assertSee('Sales per staff')->assertSee('No sales in this period');
    Excel::fake();
    Livewire::test(StaffSalesPage::class)->callAction('export')->assertHasNoErrors();
    Excel::assertDownloaded('staff-sales-'.RosterService::weekStart()->toDateString().'-to-'.RosterService::weekStart()->addDays(6)->toDateString().'.xlsx');
});
