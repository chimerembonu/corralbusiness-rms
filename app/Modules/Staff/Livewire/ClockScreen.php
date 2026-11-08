<?php

namespace App\Modules\Staff\Livewire;

use App\Modules\Pos\Services\CurrentTill;
use App\Modules\Staff\Enums\Permission;
use App\Modules\Staff\Enums\TimeEntrySource;
use App\Modules\Staff\Exceptions\InvalidPinException;
use App\Modules\Staff\Exceptions\PinLockedException;
use App\Modules\Staff\Exceptions\TimeClockException;
use App\Modules\Staff\Models\RosterShift;
use App\Modules\Staff\Models\TimeEntry;
use App\Modules\Staff\Services\PinAuthenticator;
use App\Modules\Staff\Services\RosterService;
use App\Modules\Staff\Services\TimeClockService;
use App\Support\Errors\FriendlyError;
use App\Support\Livewire\ShowsFriendlyErrors;
use App\Support\Tenancy\StoreTenantResolver;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** The clock-in screen: no login, just a PIN. One tap clocks you in, the next clocks you out. */
#[Layout('pos::layouts.app', ['title' => 'Clock in / out', 'chrome' => false])]
class ClockScreen extends Component
{
    use ShowsFriendlyErrors;

    public ?string $message = null;

    public ?string $detail = null;

    public ?string $error = null;

    public function mount(StoreTenantResolver $store): void
    {
        if ($tenant = $store->tenant()) {
            Tenancy::set($tenant, $store->branch());
        }
    }

    /** @return Collection<int, TimeEntry> */
    #[Computed]
    public function whoIsIn(): Collection
    {
        return Tenancy::branch() ? app(TimeClockService::class)->whoIsIn() : new Collection;
    }

    public function punch(#[\SensitiveParameter] string $pin, PinAuthenticator $auth, TimeClockService $clock, RosterService $roster, StoreTenantResolver $store): void
    {
        $this->message = $this->detail = $this->error = null;
        $tenant = $store->tenant();
        if (! $tenant) {
            $this->error = __('pos::pos.login.no_store');

            return;
        }
        Tenancy::set($tenant, $store->branch());

        try {
            $user = $auth->attempt($pin, 'clock:'.request()->ip(), $tenant);
            if (! $user->can(Permission::StaffClock->value)) {
                $this->error = __('pos::pos.login.no_access');

                return;
            }
            $result = $clock->toggle($user, TimeEntrySource::Clock, app(CurrentTill::class)->id());
        } catch (PinLockedException|InvalidPinException|TimeClockException $e) {
            $this->error = FriendlyError::message($e);

            return;
        }

        $entry = $result['entry'];
        if ($result['action'] === 'in') {
            $this->message = __('staff::staff.clock.clocked_in', ['name' => $user->name, 'time' => $entry->clocked_in_at->format('H:i')]);
            $shifts = $roster->todayFor($user);
            $this->detail = $shifts->isEmpty()
                ? __('staff::staff.clock.no_shift_today')
                : __('staff::staff.clock.shift_today', ['shifts' => $shifts->map(fn (RosterShift $s) => $s->timeLabel())->join(', ')]);
        } else {
            $this->message = __('staff::staff.clock.clocked_out', ['name' => $user->name, 'time' => $entry->clocked_out_at->format('H:i'), 'hours' => TimeEntry::hoursLabel($entry->minutes)]);
        }
        unset($this->whoIsIn);
    }

    public function render()
    {
        return view('staff::livewire.clock-screen');
    }
}
