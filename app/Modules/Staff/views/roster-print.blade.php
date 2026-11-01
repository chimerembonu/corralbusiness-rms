<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('staff::staff.roster.title') }} · {{ $start->format('d/m/Y') }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: system-ui, sans-serif; font-size: 12px; color: #000; margin: 0; padding: 16px; }
        h1 { font-size: 18px; margin: 0 0 4px; } .muted { color: #555; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 5px 6px; vertical-align: top; text-align: left; }
        th { background: #eee; }
        .shift { font-weight: 700; } .shift small { font-weight: 400; color: #444; display: block; }
        .print { position: fixed; top: 12px; right: 12px; padding: 10px 18px; border: 0; border-radius: 10px; background: #10b981; font: 600 15px system-ui; cursor: pointer; }
        @media print { .print { display: none; } }
    </style>
</head>
<body>
    <button type="button" class="print" onclick="window.print()">🖨 {{ __('staff::staff.roster.print') }}</button>
    <h1>{{ $branch?->name }} · {{ __('staff::staff.roster.title') }}</h1>
    <p class="muted">{{ __('staff::staff.roster.week_of', ['date' => $start->translatedFormat('j F Y')]) }} · {{ __('staff::staff.roster.printed', ['time' => now()->format('d/m/Y H:i')]) }}</p>
    <table>
        <thead><tr><th>{{ __('staff::staff.roster.staff') }}</th>@foreach ($days as $day)<th>{{ $day->translatedFormat('D j M') }}</th>@endforeach<th>{{ __('staff::staff.roster.hours') }}</th></tr></thead>
        <tbody>
            @foreach ($staff as $person)
                @php($minutes = 0)
                <tr>
                    <td><strong>{{ $person->name }}</strong><br><span class="muted">{{ $person->rolesLabel() }}</span></td>
                    @foreach ($days as $day)
                        <td>
                            @foreach ($shifts->get($person->id.'|'.$day->toDateString(), collect()) as $shift)
                                @php($minutes += $shift->minutes())
                                <div class="shift">{{ $shift->timeLabel() }}@if ($shift->role || $shift->notes)<small>{{ $shift->role ? \App\Modules\Staff\Enums\Role::from($shift->role)->label() : '' }} {{ $shift->notes }}</small>@endif</div>
                            @endforeach
                        </td>
                    @endforeach
                    <td>{{ $minutes ? \App\Modules\Staff\Models\TimeEntry::hoursLabel($minutes) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
