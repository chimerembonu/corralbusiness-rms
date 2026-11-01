<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('printing::printing.job_type.cash_up') }} {{ $session->displayNumber() }}</title>
    @include('pos::partials.paper-css')
</head>
<body class="has-toolbar">
    @php($f = fn (string $k) => __('payments::payments.cash.fields.'.$k))
    @php($m = fn ($p) => $p === null ? '—' : money($p, false))
    <div class="toolbar">
        <button type="button" class="print" onclick="window.print()">🖨 {{ __('pos::pos.receipt.print') }}</button>
        <a class="next" href="{{ route('pos.cash') }}">← {{ __('pos::pos.cash.title') }}</a>
    </div>
    <div class="receipt">
        <div class="center bold big">{{ $session->branch->tenant->name }}</div>
        <div class="center">{{ $session->branch->name }}</div>
        <div class="center bold big">{{ __('printing::printing.job_type.cash_up') }}</div>
        @if ($session->isOpen())<div class="void">{{ __('payments::payments.cash.status.open') }}</div>@endif
        <hr>
        <div class="row"><span>{{ $f('number') }}</span><span>{{ $session->displayNumber() }}</span></div>
        <div class="row"><span>{{ $f('date') }}</span><span>{{ $session->business_date->format('d/m/Y') }}</span></div>
        <div class="row"><span>{{ $f('till') }}</span><span>{{ $session->till?->name ?? __('payments::payments.cash.no_till') }}</span></div>
        <div class="row"><span>{{ $f('cashier') }}</span><span>{{ $session->user?->name }}</span></div>
        <div class="row"><span>{{ $f('opened_at') }}</span><span>{{ $session->opened_at->format('H:i') }}</span></div>
        @if ($session->closed_at)<div class="row"><span>{{ $f('closed_at') }}</span><span>{{ $session->closed_at->format('H:i') }}</span></div>@endif
        <hr>
        <div class="row"><span>{{ $f('opening_float') }}</span><span>{{ $m($session->opening_float) }}</span></div>
        <div class="row"><span>+ {{ $f('cash_sales') }}</span><span>{{ $m($session->cash_sales) }}</span></div>
        <div class="row"><span>+ {{ $f('cash_tips') }}</span><span>{{ $m($session->cash_tips) }}</span></div>
        <div class="row"><span>+ {{ $f('pay_ins') }}</span><span>{{ $m($session->pay_ins) }}</span></div>
        <div class="row"><span>- {{ $f('pay_outs') }}</span><span>{{ $m($session->pay_outs) }}</span></div>
        <div class="row"><span>- {{ $f('cash_refunds') }}</span><span>{{ $m($session->cash_refunds) }}</span></div>
        <div class="row bold"><span>{{ $f('expected') }}</span><span>{{ $m($expected) }}</span></div>
        <div class="row"><span>{{ $f('counted') }}</span><span>{{ $m($session->counted_cash) }}</span></div>
        <div class="row bold big"><span>{{ $f('variance') }}</span><span>{{ $session->variance === null ? '—' : \App\Modules\Printing\Documents\CashReportDocument::signed($session->variance) }}</span></div>
        @if ($session->approvedBy)<div class="row"><span>{{ $f('approved_by') }}</span><span>{{ $session->approvedBy->name }}</span></div>@endif
        @if ($session->movements->isNotEmpty())
            <hr>
            <div class="bold">{{ $f('movements') }}</div>
            @foreach ($session->movements as $movement)
                <div class="row"><span class="l">{{ $movement->happened_at->format('H:i') }} {{ $movement->type->label() }}: {{ $movement->reason }}</span><span>{{ $movement->isPayIn() ? '+' : '-' }}{{ money($movement->amount, false) }}</span></div>
            @endforeach
        @endif
        @if ($session->totals)
            <hr>
            <div class="bold">{{ $f('by_method') }}</div>
            @foreach ($session->totals as $row)
                <div class="row"><span>{{ \App\Modules\Payments\Enums\PaymentMethod::from($row['method'])->label() }} x{{ $row['count'] }}</span><span>{{ money($row['total'], false) }}</span></div>
            @endforeach
        @endif
        @if ($session->counted_breakdown)
            <hr>
            <div class="bold">{{ $f('count_sheet') }}</div>
            @foreach ($session->counted_breakdown as $value => $count)
                <div class="row"><span>{{ money((int) $value, false) }} x {{ $count }}</span><span>{{ money((int) $value * (int) $count, false) }}</span></div>
            @endforeach
        @endif
        @if ($session->notes)<hr><div>{{ $session->notes }}</div>@endif
        <hr>
        <div class="center muted">{{ __('rms.product_name') }}</div>
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endif
</body>
</html>
