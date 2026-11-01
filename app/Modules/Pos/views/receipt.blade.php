<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pos::pos.receipt.title') }} #{{ $order->displayNumber() }}</title>
    <style>
        /* 80 mm thermal paper: ~72 mm printable width. */
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; font-family: "Courier New", Courier, monospace; font-size: 12px; color: #000; }
        .receipt { width: 80mm; padding: 4mm; margin: 16px auto; background: #fff; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .big { font-size: 16px; }
        .row { display: flex; justify-content: space-between; gap: 4px; }
        .row .l { flex: 1; }
        .muted { font-size: 11px; }
        .mod { padding-left: 10px; font-size: 11px; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .void { border: 3px double #000; padding: 4px; margin: 6px 0; text-align: center; font-size: 16px; font-weight: bold; }
        .toolbar { position: fixed; top: 0; left: 0; right: 0; display: flex; gap: 8px; justify-content: center; padding: 12px; background: #0f172a; }
        .toolbar a, .toolbar button { min-height: 48px; padding: 0 20px; border: 0; border-radius: 12px; font: 600 16px system-ui, sans-serif; display: inline-flex; align-items: center; cursor: pointer; text-decoration: none; }
        .print { background: #10b981; color: #022c22; }
        .next { background: #1e293b; color: #f1f5f9; }
        body.has-toolbar .receipt { margin-top: 84px; } body.has-toolbar .muted + .receipt { margin-top: 8px; }
        @media print { body { background: #fff; } .receipt { margin: 0; width: 80mm; } .toolbar { display: none; } }
    </style>
</head>
<body class="has-toolbar">
    <div class="toolbar">
        <button type="button" class="print" onclick="window.print()">🖨 {{ __('pos::pos.receipt.print') }}</button>
        @auth @unless ($preview ?? false)<a class="next" href="{{ route('pos.home') }}">{{ __('pos::pos.receipt.new_order') }}</a>@endunless @endauth
    </div>

    @if ($preview ?? false)<div class="center muted" style="margin-top:84px">{{ __('pos::pos.receipt.preview_note') }}</div>@endif
    <div class="receipt">
        @if ($settings->logoUrl())<div class="center"><img src="{{ $settings->logoUrl() }}" alt="" style="max-width:60%;max-height:30mm"></div>@endif
        <div class="center bold big">{{ $tenant->name }}</div>
        <div class="center">{{ $branch->name }}</div>
        @if ($branch->address)<div class="center muted">{{ $branch->address }}</div>@endif
        @if ($branch->phone)<div class="center muted">{{ $branch->phone }}</div>@endif
        @if ($settings->taxNumber)<div class="center muted">{{ __('pos::pos.receipt.tax_number') }}: {{ $settings->taxNumber }}</div>@endif
        @foreach ($settings->headerLines() as $line)<div class="center muted">{{ $line }}</div>@endforeach
        <hr>
        @if ($order->status === \App\Modules\Orders\Enums\OrderStatus::Void)<div class="void">{{ __('pos::pos.receipt.void') }}</div>@endif
        @if ($order->status === \App\Modules\Orders\Enums\OrderStatus::Refunded)<div class="void">{{ __('pos::pos.receipt.refunded') }}</div>@endif
        @if ($reprint ?? false)<div class="center bold">{{ __('pos::pos.receipt.reprint_banner') }}</div>@endif
        <div class="row"><span>{{ __('pos::pos.receipt.order_no') }}</span><span class="bold">#{{ $order->displayNumber() }}</span></div>
        <div class="row"><span>{{ __('pos::pos.receipt.date') }}</span><span>{{ ($order->paid_at ?? $order->opened_at)->format('d/m/Y H:i') }}</span></div>
        <div class="row"><span>{{ __('pos::pos.receipt.type') }}</span><span>{{ $order->type->label() }}@if ($order->table) · {{ __('pos::pos.receipt.table') }} {{ $order->table->name }}@endif</span></div>
        @if ($order->openedBy && $settings->showServer)<div class="row"><span>{{ __('pos::pos.receipt.served_by') }}</span><span>{{ $order->openedBy->name }}</span></div>@endif
        @if ($order->table)<div class="row"><span>{{ __('pos::pos.receipt.guests') }}</span><span>{{ $order->covers }}</span></div>@endif
        @if ($order->customer_name)<div class="row"><span>{{ __('pos::pos.orders.who') }}</span><span>{{ $order->customer_name }}</span></div>@endif
        @if ($order->isDelivery())
            <div class="row"><span>{{ __('pos::pos.delivery.phone') }}</span><span>{{ $order->customer_phone }}</span></div>
            <div>{{ __('pos::pos.delivery.address') }}: {{ $order->delivery_address }}</div>
            @if ($order->delivery_notes)<div class="muted">{{ $order->delivery_notes }}</div>@endif
            @if ($order->rider)<div class="row"><span>{{ __('pos::pos.delivery.rider') }}</span><span>{{ $order->rider->name }}</span></div>@endif
        @endif
        <hr>
        @foreach ($order->items->where('status', '!=', \App\Modules\Orders\Enums\OrderItemStatus::Voided) as $line)
            <div class="row"><span class="l">{{ $line->quantity }} x {{ $line->displayName() }}</span><span>{{ money($line->line_subtotal, false) }}</span></div>
            @foreach ($line->modifiers as $modifier)
                <div class="mod">+ {{ $modifier->displayName() }}@if ($modifier->unit_price->isPositive()) ({{ money($modifier->unit_price, false) }})@endif</div>
            @endforeach
            @if ($line->notes)<div class="mod">* {{ $line->notes }}</div>@endif
        @endforeach
        <hr>
        <div class="row"><span>{{ __('pos::pos.receipt.subtotal') }}</span><span>{{ money($order->subtotal, false) }}</span></div>
        @if ($order->discount_total->isPositive())
            <div class="row"><span>{{ __('pos::pos.receipt.discount') }}@if ($order->discountLabel()) {{ $order->discountLabel() }}@endif@if ($order->discount_reason) ({{ $order->discount_reason }})@endif</span><span>-{{ money($order->discount_total, false) }}</span></div>
        @endif
        @foreach ($order->tax_lines ?? [] as $tax)
            <div class="row"><span>{{ $tax['name'] }} {{ rtrim(rtrim(number_format($tax['rate_basis_points'] / 100, 2), '0'), '.') }}%</span><span>{{ money($tax['amount'], false) }}</span></div>
        @endforeach
        @if ($order->service_charge->isPositive())
            <div class="row"><span>{{ __('pos::pos.receipt.service_charge') }} {{ rtrim(rtrim(number_format($order->service_charge_bp / 100, 2), '0'), '.') }}%</span><span>{{ money($order->service_charge, false) }}</span></div>
        @endif
        @if ($order->delivery_fee->isPositive())
            <div class="row"><span>{{ __('orders::orders.delivery_fee') }}</span><span>{{ money($order->delivery_fee, false) }}</span></div>
        @endif
        <div class="row bold big"><span>{{ __('pos::pos.receipt.total') }}</span><span>{{ config('rms.currency') }} {{ money($order->total, false) }}</span></div>
        <div class="muted center">{{ $order->prices_include_tax ? __('pos::pos.receipt.prices_include_tax') : __('pos::pos.receipt.prices_exclude_tax') }}</div>
        <hr>
        @foreach ($order->payments as $payment)
            <div class="row"><span>{{ $payment->isRefunded() ? __('pos::pos.receipt.refund').' ' : '' }}{{ $payment->methodLabel() }}@if ($payment->reference) {{ $payment->reference }}@endif</span><span>{{ $payment->isRefunded() ? '-' : '' }}{{ money($payment->amount, false) }}</span></div>
            @if ($payment->tip->isPositive())<div class="row muted"><span>{{ __('pos::pos.receipt.tip') }}</span><span>{{ money($payment->tip, false) }}</span></div>@endif
            @if ($payment->tendered && $payment->tendered->greaterThan($payment->amount))
                <div class="row muted"><span>{{ __('pos::pos.receipt.tendered') }}</span><span>{{ money($payment->tendered, false) }}</span></div>
                <div class="row muted"><span>{{ __('pos::pos.receipt.change') }}</span><span>{{ money($payment->change_given, false) }}</span></div>
            @endif
        @endforeach
        @if ($order->payments->isEmpty())<div class="center muted">{{ $order->status->label() }}</div>@endif
        <hr>
        <div class="center">{{ $footer }}</div>
        <div class="center muted">{{ __('rms.product_name') }}</div>
    </div>

    @if ($autoPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endif
</body>
</html>
