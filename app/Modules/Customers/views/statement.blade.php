<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('customers::customers.fields.statement') }} · {{ $customer->name }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: system-ui, sans-serif; font-size: 12px; color: #000; margin: 0; padding: 16px; }
        h1 { font-size: 18px; margin: 0 0 2px; } .muted { color: #555; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border-bottom: 1px solid #bbb; padding: 6px; text-align: left; } th { background: #eee; } td.r, th.r { text-align: right; }
        .total { font-size: 16px; font-weight: 700; margin-top: 12px; text-align: right; }
        .print { position: fixed; top: 12px; right: 12px; padding: 10px 18px; border: 0; border-radius: 10px; background: #10b981; font: 600 15px system-ui; cursor: pointer; }
        @media print { .print { display: none; } }
    </style>
</head>
<body>
    <button type="button" class="print" onclick="window.print()">🖨 {{ __('pos::pos.receipt.print') }}</button>
    <h1>{{ $tenant?->name }} · {{ __('customers::customers.fields.statement') }}</h1>
    <p class="muted">{{ $customer->name }} · {{ $customer->phone }} · {{ now()->format('d/m/Y H:i') }}</p>
    <table>
        <thead><tr><th>{{ __('customers::customers.account.columns.when') }}</th><th>{{ __('customers::customers.account.columns.type') }}</th><th>{{ __('customers::customers.account.columns.detail') }}</th><th class="r">{{ __('customers::customers.account.columns.amount') }}</th><th class="r">{{ __('customers::customers.account.columns.balance') }}</th></tr></thead>
        <tbody>
            @foreach ($rows as $t)
                <tr><td>{{ $t->happened_at->format('d/m/Y H:i') }}</td><td>{{ $t->type->label() }}</td><td>{{ $t->notes }}{{ $t->reference ? ' · '.$t->reference : '' }}</td><td class="r">{{ $t->amount > 0 ? '+' : '−' }}{{ money($t->money(), false) }}</td><td class="r">{{ money($t->balance_after, false) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <p class="total">{{ __('customers::customers.fields.account_balance') }}: {{ money($customer->balance()) }}</p>
</body>
</html>
