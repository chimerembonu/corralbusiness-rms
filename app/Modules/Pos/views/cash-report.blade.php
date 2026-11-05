<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($title = $close ? __('payments::payments.day.z_report').' '.$close->displayNumber() : __('payments::payments.day.x_report'))
    <title>{{ $title }} · {{ \Carbon\Carbon::parse($report['date'])->format('d/m/Y') }}</title>
    @include('pos::partials.paper-css')
</head>
<body class="has-toolbar">
    @php($m = fn (int $p) => money($p, false))
    @php($signed = fn (?int $p) => \App\Modules\Printing\Documents\CashReportDocument::signed($p))
    <div class="toolbar">
        <button type="button" class="print" onclick="window.print()">🖨 {{ __('pos::pos.receipt.print') }}</button>
        <a class="next" href="{{ route('pos.cash') }}">← {{ __('pos::pos.cash.title') }}</a>
    </div>
    <div class="receipt">
        <div class="center bold big">{{ $tenant?->name }}</div>
        <div class="center">{{ $branch?->name }}</div>
        <div class="center bold big">{{ $title }}</div>
        @if ($close && ! $close->isClosed())<div class="void">{{ __('payments::payments.day.status.reopened') }}</div>@endif
        <hr>
        <div class="row"><span>{{ __('pos::pos.receipt.date') }}</span><span>{{ \Carbon\Carbon::parse($report['date'])->format('d/m/Y') }}</span></div>
        <div class="row"><span>{{ __('pos::pos.cash.printed_at') }}</span><span>{{ \Carbon\Carbon::parse($report['generated_at'])->format('d/m/Y H:i') }}</span></div>
        @if ($close)
            <div class="row"><span>{{ __('payments::payments.day.fields.closed_by') }}</span><span>{{ $close->closedBy?->name }}</span></div>
            @if ($close->second_approved_by_id)
                <div class="row"><span>{{ __('payments::payments.day.fields.second_approved_by') }}</span><span>{{ $close->secondApprovedBy?->name }}</span></div>
            @endif
        @endif
        <hr>
        <div class="row bold"><span>{{ __('pos::pos.today.gross') }}</span><span>{{ $m($report['gross_sales']) }}</span></div>
        <div class="row"><span>{{ __('pos::pos.today.net') }}</span><span>{{ $m($report['net_sales']) }}</span></div>
        <div class="row"><span>{{ __('pos::pos.today.tax') }}</span><span>{{ $m($report['tax_total']) }}</span></div>
        @foreach ($report['tax_lines'] as $tax)
            <div class="row mod"><span>{{ $tax['name'] }}</span><span>{{ $m($tax['amount']) }}</span></div>
        @endforeach
        <div class="row"><span>{{ __('pos::pos.today.discounts') }}</span><span>{{ $m($report['discount_total']) }}</span></div>
        <div class="row"><span>{{ __('orders::orders.service_charge') }}</span><span>{{ $m($report['service_charge_total']) }}</span></div>
        <div class="row"><span>{{ __('orders::orders.delivery_fee') }}</span><span>{{ $m($report['delivery_fee_total']) }}</span></div>
        <div class="row"><span>{{ __('orders::orders.tip') }}</span><span>{{ $m($report['tip_total']) }}</span></div>
        <div class="row"><span>{{ __('pos::pos.today.refunds') }}</span><span>{{ $m($report['refund_total']) }}</span></div>
        <hr>
        <div class="row"><span>{{ __('pos::pos.today.orders') }}</span><span>{{ $report['orders_paid'] }}</span></div>
        <div class="row"><span>{{ __('pos::pos.today.open') }}</span><span>{{ $report['orders_open'] }}</span></div>
        <div class="row"><span>{{ __('pos::pos.today.void') }}</span><span>{{ $report['orders_void'] }}</span></div>
        <div class="row"><span>{{ __('pos::pos.today.average') }}</span><span>{{ $m($report['average_order']) }}</span></div>
        <hr>
        <div class="bold">{{ __('pos::pos.today.by_type') }}</div>
        @foreach ($report['by_type'] as $row)
            <div class="row"><span>{{ \App\Modules\Orders\Enums\OrderType::from($row['type'])->label() }} x{{ $row['count'] }}</span><span>{{ $m($row['total']) }}</span></div>
        @endforeach
        <hr>
        <div class="bold">{{ __('pos::pos.today.by_method') }}</div>
        @foreach ($report['by_payment_method'] as $row)
            <div class="row"><span>{{ \App\Modules\Payments\Enums\PaymentMethod::from($row['method'])->label() }} x{{ $row['count'] }}</span><span>{{ $m($row['total']) }}</span></div>
        @endforeach
        <hr>
        @php($cash = $report['cash'])
        <div class="bold">{{ __('pos::pos.cash.title') }}</div>
        <div class="row"><span>{{ __('payments::payments.cash.fields.opening_float') }}</span><span>{{ $m($cash['opening_float']) }}</span></div>
        <div class="row"><span>+ {{ __('payments::payments.cash.fields.cash_sales') }}</span><span>{{ $m($cash['cash_sales']) }}</span></div>
        <div class="row"><span>+ {{ __('payments::payments.cash.fields.cash_tips') }}</span><span>{{ $m($cash['cash_tips']) }}</span></div>
        <div class="row"><span>+ {{ __('payments::payments.cash.fields.pay_ins') }}</span><span>{{ $m($cash['pay_ins']) }}</span></div>
        <div class="row"><span>- {{ __('payments::payments.cash.fields.pay_outs') }}</span><span>{{ $m($cash['pay_outs']) }}</span></div>
        <div class="row"><span>- {{ __('payments::payments.cash.fields.cash_refunds') }}</span><span>{{ $m($cash['cash_refunds']) }}</span></div>
        <div class="row bold"><span>{{ __('payments::payments.cash.fields.expected') }}</span><span>{{ $m($cash['expected']) }}</span></div>
        <div class="row"><span>{{ __('payments::payments.cash.fields.counted') }}</span><span>{{ $m($cash['counted']) }}</span></div>
        <div class="row"><span>{{ __('payments::payments.cash.fields.variance') }}</span><span>{{ $signed($cash['variance']) }}</span></div>
        <hr>
        <div class="bold">{{ __('payments::payments.cash.sessions') }}</div>
        @foreach ($report['sessions'] as $s)
            <div class="row"><span class="l">{{ $s['number'] }} {{ $s['till'] }} · {{ $s['cashier'] }}</span><span>{{ $s['counted'] === null ? __('payments::payments.cash.status.open') : $signed($s['variance']) }}</span></div>
        @endforeach
        <hr>
        <div class="bold">{{ __('pos::pos.today.top_items') }}</div>
        @foreach ($report['top_items'] as $row)
            <div class="row"><span class="l">{{ $row['name'] }} x{{ $row['quantity'] }}</span><span>{{ $m($row['total']) }}</span></div>
        @endforeach
        @if (! empty($report['history_code']))
            <hr>
            <div class="row"><span>{{ __('settings::settings.history.z_label') }}</span><span>{{ $report['history_code'] }}</span></div>
        @endif
        <hr>
        <div class="center muted">{{ __('rms.product_name') }}</div>
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
    @endif
</body>
</html>
