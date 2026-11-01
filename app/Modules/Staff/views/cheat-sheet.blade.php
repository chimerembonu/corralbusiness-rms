<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('staff::staff.cheat_sheet.title') }} · {{ $tenant?->name }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: {{ $pdf ? 'Helvetica, Arial, sans-serif' : 'system-ui, sans-serif' }}; font-size: 11px; color: #000; margin: 0; padding: {{ $pdf ? '0' : '16px' }}; }
        h1 { font-size: 20px; margin: 0 0 2px; } .muted { color: #555; margin: 0 0 10px; }
        .page { page-break-after: always; }
        .page:last-child { page-break-after: auto; }
        h2 { font-size: 16px; margin: 14px 0 6px; padding: 4px 8px; background: #10b981; color: #fff; }
        h3 { font-size: 12px; margin: 10px 0 3px; }
        ol, ul { margin: 0 0 6px 18px; padding: 0; } li { margin: 2px 0; }
        .links { border: 1px solid #999; padding: 6px 10px; margin: 6px 0 10px; }
        .links b { display: inline-block; min-width: 110px; }
        .warn { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 4px 8px; margin: 6px 0; }
        .print { position: fixed; top: 12px; right: 12px; padding: 10px 18px; border: 0; border-radius: 10px; background: #10b981; font: 600 15px system-ui; cursor: pointer; }
        @media print { .print { display: none; } }
    </style>
</head>
<body>
    @unless ($pdf)<button type="button" class="print" onclick="window.print()">🖨 {{ __('pos::pos.receipt.print') }}</button>@endunless
    @foreach ($sections as $key => $section)
        <div class="page">
            <h1>{{ $tenant?->name }} · {{ __('staff::staff.cheat_sheet.title') }}</h1>
            <p class="muted">{{ $section['for'] }} · {{ $branch?->name }} · {{ __('staff::staff.cheat_sheet.printed', ['date' => $printed]) }}</p>
            <div class="links">
                <div><b>{{ __('staff::staff.cheat_sheet.till_link') }}</b> {{ $base }}/pos</div>
                <div><b>{{ __('staff::staff.cheat_sheet.kitchen_link') }}</b> {{ $base }}/kitchen</div>
                <div><b>{{ __('staff::staff.cheat_sheet.clock_link') }}</b> {{ $base }}/clock</div>
                @if ($key === 'manager')<div><b>{{ __('staff::staff.cheat_sheet.admin_link') }}</b> {{ $base }}/admin</div>@endif
                @if ($tills)<div><b>{{ __('staff::staff.cheat_sheet.tills') }}</b> {{ implode(' · ', $tills) }}</div>@endif
                @if ($key === 'kitchen' && $stations)<div><b>{{ __('staff::staff.cheat_sheet.stations') }}</b> {{ implode(' · ', $stations) }}</div>@endif
            </div>
            <h2>{{ $section['title'] }}</h2>
            @foreach ($section['topics'] as $topic)
                <h3>{{ $topic['heading'] }}</h3>
                <ol>@foreach ($topic['steps'] as $step)<li>{{ $step }}</li>@endforeach</ol>
            @endforeach
            @if (! empty($section['warn']))<p class="warn">{{ $section['warn'] }}</p>@endif
        </div>
    @endforeach
</body>
</html>
