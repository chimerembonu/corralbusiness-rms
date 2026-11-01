@php($days = $this->days())
@php($shifts = $this->shifts())
@php($staff = $this->staff())
<x-filament-panels::page>
    <style>
        .ro-nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .ro-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .85rem; }
        .ro-table th, .ro-table td { border-bottom: 1px solid rgba(128,128,128,.25); padding: 6px; vertical-align: top; }
        .ro-table th { text-align: left; font-weight: 600; }
        .ro-table th.today, .ro-table td.today { background: rgba(16,185,129,.07); }
        .ro-name { white-space: nowrap; font-weight: 600; }
        .ro-role { display: block; font-size: .72rem; opacity: .6; font-weight: 400; }
        .ro-shift { display: block; width: 100%; text-align: left; border-radius: 8px; padding: 4px 8px; margin-bottom: 4px; background: rgba(16,185,129,.18); border: 1px solid rgba(16,185,129,.35); font-weight: 600; cursor: pointer; }
        .ro-shift small { display: block; font-weight: 400; opacity: .75; }
        .ro-add { display: block; width: 100%; text-align: center; border-radius: 8px; padding: 2px; opacity: .35; cursor: pointer; } .ro-add:hover { opacity: 1; background: rgba(128,128,128,.15); }
        .ro-total { white-space: nowrap; text-align: right; font-weight: 600; }
    </style>

    <div class="ro-nav">
        <x-filament::button color="gray" wire:click="previousWeek">{{ __('staff::staff.roster.previous') }}</x-filament::button>
        <x-filament::button color="gray" wire:click="thisWeek">{{ __('staff::staff.roster.this_week') }}</x-filament::button>
        <x-filament::button color="gray" wire:click="nextWeek">{{ __('staff::staff.roster.next') }}</x-filament::button>
    </div>

    <x-filament::section>
        <div style="overflow-x:auto">
            <table class="ro-table">
                <thead>
                    <tr>
                        <th>{{ __('staff::staff.roster.staff') }}</th>
                        @foreach ($days as $day)
                            <th class="{{ $day->isToday() ? 'today' : '' }}">{{ $day->translatedFormat('D') }}<span class="ro-role">{{ $day->translatedFormat('j M') }}</span></th>
                        @endforeach
                        <th class="ro-total">{{ __('staff::staff.roster.hours') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($staff as $person)
                        @php($minutes = 0)
                        <tr wire:key="row-{{ $person->id }}">
                            <td class="ro-name">{{ $person->name }}<span class="ro-role">{{ $person->rolesLabel() }}</span></td>
                            @foreach ($days as $day)
                                @php($cell = $shifts->get($person->id.'|'.$day->toDateString(), collect()))
                                <td class="{{ $day->isToday() ? 'today' : '' }}">
                                    @foreach ($cell as $shift)
                                        @php($minutes += $shift->minutes())
                                        <button type="button" class="ro-shift" wire:click="mountAction('editShift', { shift: '{{ $shift->id }}' })">{{ $shift->timeLabel() }}@if ($shift->role || $shift->notes)<small>{{ $shift->role ? \App\Modules\Staff\Enums\Role::from($shift->role)->label() : '' }}{{ $shift->role && $shift->notes ? ' · ' : '' }}{{ $shift->notes }}</small>@endif</button>
                                    @endforeach
                                    <button type="button" class="ro-add" wire:click="mountAction('addShift', { user: '{{ $person->id }}', date: '{{ $day->toDateString() }}' })" title="{{ __('staff::staff.roster.add_shift') }}">+</button>
                                </td>
                            @endforeach
                            <td class="ro-total">{{ $minutes ? \App\Modules\Staff\Models\TimeEntry::hoursLabel($minutes) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($shifts->isEmpty())<p class="mt-3 text-sm text-gray-500">{{ __('staff::staff.roster.empty') }}</p>@endif
    </x-filament::section>
</x-filament-panels::page>
