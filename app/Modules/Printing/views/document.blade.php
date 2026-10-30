<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->title ?: __('printing::printing.print') }}</title>
    <style>
        @page { size: {{ $paperWidth }}mm auto; margin: 0; }
        body { margin: 0; background: #e2e8f0; font-family: "Courier New", Courier, monospace; font-size: {{ $paperWidth <= 58 ? '11px' : '12px' }}; color: #000; }
        .doc { width: {{ $paperWidth }}mm; padding: 3mm 4mm; margin: 16px auto; background: #fff; }
        .l { text-align: left; } .c { text-align: center; } .r { text-align: right; }
        .b { font-weight: bold; } .big { font-size: 1.6em; } .tall { font-size: 1.3em; } .wide { letter-spacing: 0.3em; }
        .row { display: flex; justify-content: space-between; gap: 6px; } .row span:first-child { flex: 1; }
        .rule { border: 0; border-top: 1px dashed #000; margin: 4px 0; }
        .feed { height: 1em; }
        .cut { border-top: 2px dotted #94a3b8; margin: 8px 0; }
        .toolbar { position: fixed; top: 0; left: 0; right: 0; display: flex; gap: 8px; justify-content: center; padding: 12px; background: #0f172a; }
        .toolbar button { min-height: 48px; padding: 0 20px; border: 0; border-radius: 12px; font: 600 16px system-ui, sans-serif; background: #10b981; color: #022c22; cursor: pointer; }
        body.has-toolbar .doc { margin-top: 84px; }
        @media print { body { background: #fff; } .doc { margin: 0; } .toolbar { display: none; } .cut { display: none; } }
    </style>
</head>
<body class="has-toolbar">
    <div class="toolbar"><button type="button" onclick="window.print()">🖨 {{ __('printing::printing.print') }}</button></div>
    <div class="doc">
        @foreach ($document->lines() as $line)
            @switch($line['type'])
                @case('text')
                    <div class="{{ substr($line['align'] ?? 'l', 0, 1) }} {{ ($line['bold'] ?? false) ? 'b' : '' }} {{ ($line['size'] ?? 'normal') !== 'normal' ? $line['size'] : '' }}">{{ $line['text'] === '' ? "\u{00A0}" : $line['text'] }}</div>
                    @break
                @case('row')
                    <div class="row {{ ($line['bold'] ?? false) ? 'b' : '' }} {{ ($line['size'] ?? 'normal') !== 'normal' ? $line['size'] : '' }}"><span>{{ $line['left'] }}</span><span>{{ $line['right'] }}</span></div>
                    @break
                @case('rule')
                    <hr class="rule">
                    @break
                @case('feed')
                    @for ($i = 0; $i < ($line['lines'] ?? 1); $i++)<div class="feed"></div>@endfor
                    @break
                @case('cut')
                    <div class="cut"></div>
                    @break
            @endswitch
        @endforeach
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endif
</body>
</html>
