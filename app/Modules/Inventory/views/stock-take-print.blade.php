<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('inventory::inventory.stock_take.sheet.title') }} {{ $take->displayNumber() }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: system-ui, sans-serif; font-size: 12px; color: #000; margin: 0; padding: 16px; }
        h1 { font-size: 18px; margin: 0 0 4px; } .muted { color: #555; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 6px; text-align: left; }
        th { background: #eee; } td.r, th.r { text-align: right; } td.box { width: 110px; }
        .print { position: fixed; top: 12px; right: 12px; padding: 10px 18px; border: 0; border-radius: 10px; background: #10b981; font: 600 15px system-ui; cursor: pointer; }
        @media print { .print { display: none; } }
    </style>
</head>
<body>
    <button type="button" class="print" onclick="window.print()">🖨 {{ __('pos::pos.receipt.print') }}</button>
    <h1>{{ $branch?->name }} · {{ __('inventory::inventory.stock_take.sheet.title') }} {{ $take->displayNumber() }}</h1>
    <p class="muted">{{ $take->category ?? __('inventory::inventory.stock_take.fields.all') }} · {{ __('inventory::inventory.stock_take.sheet.printed', ['time' => now()->format('d/m/Y H:i')]) }} · {{ __('inventory::inventory.stock_take.sheet.signed') }}</p>
    <table>
        <thead><tr><th>#</th><th>{{ __('inventory::inventory.stock_take.fields.name') }}</th><th>{{ __('inventory::inventory.fields.unit') }}</th><th class="r">{{ __('inventory::inventory.stock_take.fields.expected') }}</th><th class="r">{{ __('inventory::inventory.stock_take.fields.counted') }}</th></tr></thead>
        <tbody>
            @foreach ($take->lines as $i => $line)
                <tr><td>{{ $i + 1 }}</td><td><strong>{{ $line->name }}</strong></td><td>{{ $line->unit }}</td><td class="r">{{ \App\Modules\Inventory\Services\StockService::qty((float) $line->expected) }}</td><td class="box r">{{ $line->isCounted() ? \App\Modules\Inventory\Services\StockService::qty((float) $line->counted) : '' }}</td></tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
