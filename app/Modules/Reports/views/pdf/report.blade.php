<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #000; }
        h1 { font-size: 16px; margin: 0 0 2px; } h2 { font-size: 12px; margin: 14px 0 4px; }
        .muted { color: #555; margin: 0 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #bbb; padding: 4px 5px; text-align: left; vertical-align: top; }
        th { background: #eee; } td.r, th.r { text-align: right; }
        tr.total td { font-weight: bold; border-top: 2px solid #000; }
    </style>
</head>
<body>
    <h1>{{ $tenant?->name }} · {{ $title }}</h1>
    <p class="muted">{{ $branch?->name }} · {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} · {{ __('reports::reports.generated', ['time' => now()->format('d/m/Y H:i')]) }}</p>
    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table>
            <thead><tr>@foreach ($section['headings'] as $i => $h)<th class="{{ in_array($i, $section['money'], true) ? 'r' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    <tr>@foreach ($row as $i => $cell)<td class="{{ in_array($i, $section['money'], true) || is_numeric($cell) ? 'r' : '' }}">{{ $cell }}</td>@endforeach</tr>
                @empty
                    <tr><td colspan="{{ count($section['headings']) }}" class="muted">—</td></tr>
                @endforelse
                @if ($section['totals'])<tr class="total">@foreach ($section['totals'] as $i => $cell)<td class="{{ in_array($i, $section['money'], true) || is_numeric($cell) ? 'r' : '' }}">{{ $cell }}</td>@endforeach</tr>@endif
            </tbody>
        </table>
    @endforeach
</body>
</html>
