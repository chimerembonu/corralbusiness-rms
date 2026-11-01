<x-filament-panels::page>
    @php($z = $this->record)
    @php($r = $z->report)
    @php($f = fn (string $k) => __('payments::payments.day.fields.'.$k))
    @php($c = fn (string $k) => __('payments::payments.cash.fields.'.$k))
    @php($m = fn (int $p) => money($p))
    @php($signed = fn (?int $p) => \App\Modules\Printing\Documents\CashReportDocument::signed($p, true))
    <style>
        .cs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
        .cs-card { border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 14px 16px; }
        .cs-card h3 { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; opacity: .6; margin: 0 0 8px; }
        .cs-row { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; font-size: .9rem; }
        .cs-row.bold { font-weight: 700; }
        .cs-big { font-size: 1.5rem; font-weight: 800; }
    </style>
    @if (! $z->isClosed())
        <div class="cs-card" style="border-color:#f59e0b">
            <strong>{{ __('payments::payments.day.status.reopened') }}</strong> · {{ $z->reopened_at?->format('d M H:i') }} · {{ $z->reopenedBy?->name }} · {{ $z->reopen_reason }}
        </div>
    @endif
    <div class="cs-grid">
        <div class="cs-card">
            <h3>{{ __('payments::payments.day.z_report') }}</h3>
            <div class="cs-row"><span>{{ $f('number') }}</span><span>{{ $z->displayNumber() }}</span></div>
            <div class="cs-row"><span>{{ $f('date') }}</span><span>{{ $z->business_date->format('d M Y') }}</span></div>
            <div class="cs-row"><span>{{ $f('closed_at') }}</span><span>{{ $z->closed_at->format('d M H:i') }}</span></div>
            <div class="cs-row"><span>{{ $f('closed_by') }}</span><span>{{ $z->closedBy?->name }}</span></div>
            <div class="cs-row"><span>{{ $f('approved_by') }}</span><span>{{ $z->approvedBy?->name }}</span></div>
            @if ($z->notes)<div class="cs-row"><span>{{ $f('notes') }}</span><span>{{ $z->notes }}</span></div>@endif
        </div>
        <div class="cs-card">
            <h3>{{ __('pos::pos.today.title') }}</h3>
            <div class="cs-row bold"><span>{{ __('pos::pos.today.gross') }}</span><span class="cs-big">{{ $m($r['gross_sales']) }}</span></div>
            <div class="cs-row"><span>{{ __('pos::pos.today.net') }}</span><span>{{ $m($r['net_sales']) }}</span></div>
            <div class="cs-row"><span>{{ __('pos::pos.today.tax') }}</span><span>{{ $m($r['tax_total']) }}</span></div>
            @foreach ($r['tax_lines'] as $tax)<div class="cs-row" style="opacity:.7"><span>&nbsp;&nbsp;{{ $tax['name'] }}</span><span>{{ $m($tax['amount']) }}</span></div>@endforeach
            <div class="cs-row"><span>{{ __('pos::pos.today.discounts') }}</span><span>{{ $m($r['discount_total']) }}</span></div>
            <div class="cs-row"><span>{{ __('orders::orders.service_charge') }}</span><span>{{ $m($r['service_charge_total']) }}</span></div>
            <div class="cs-row"><span>{{ __('orders::orders.delivery_fee') }}</span><span>{{ $m($r['delivery_fee_total']) }}</span></div>
            <div class="cs-row"><span>{{ __('orders::orders.tip') }}</span><span>{{ $m($r['tip_total']) }}</span></div>
            <div class="cs-row"><span>{{ __('pos::pos.today.refunds') }}</span><span>{{ $m($r['refund_total']) }}</span></div>
            <div class="cs-row"><span>{{ __('pos::pos.today.orders') }}</span><span>{{ $r['orders_paid'] }} · {{ $r['orders_void'] }} {{ strtolower(__('pos::pos.today.void')) }}</span></div>
        </div>
        <div class="cs-card">
            <h3>{{ __('pos::pos.today.by_method') }}</h3>
            @foreach ($r['by_payment_method'] as $row)
                <div class="cs-row"><span>{{ \App\Modules\Payments\Enums\PaymentMethod::from($row['method'])->label() }} × {{ $row['count'] }}</span><span>{{ $m($row['total']) }}</span></div>
            @endforeach
            <h3 style="margin-top:12px">{{ __('pos::pos.today.by_type') }}</h3>
            @foreach ($r['by_type'] as $row)
                <div class="cs-row"><span>{{ \App\Modules\Orders\Enums\OrderType::from($row['type'])->label() }} × {{ $row['count'] }}</span><span>{{ $m($row['total']) }}</span></div>
            @endforeach
        </div>
        <div class="cs-card">
            <h3>{{ __('pos::pos.cash.title') }}</h3>
            @php($cash = $r['cash'])
            <div class="cs-row"><span>{{ $c('opening_float') }}</span><span>{{ $m($cash['opening_float']) }}</span></div>
            <div class="cs-row"><span>+ {{ $c('cash_sales') }}</span><span>{{ $m($cash['cash_sales']) }}</span></div>
            <div class="cs-row"><span>+ {{ $c('cash_tips') }}</span><span>{{ $m($cash['cash_tips']) }}</span></div>
            <div class="cs-row"><span>+ {{ $c('pay_ins') }}</span><span>{{ $m($cash['pay_ins']) }}</span></div>
            <div class="cs-row"><span>− {{ $c('pay_outs') }}</span><span>{{ $m($cash['pay_outs']) }}</span></div>
            <div class="cs-row"><span>− {{ $c('cash_refunds') }}</span><span>{{ $m($cash['cash_refunds']) }}</span></div>
            <div class="cs-row bold"><span>{{ $c('expected') }}</span><span>{{ $m($cash['expected']) }}</span></div>
            <div class="cs-row"><span>{{ $c('counted') }}</span><span>{{ $m($cash['counted']) }}</span></div>
            <div class="cs-row bold"><span>{{ $c('variance') }}</span><span style="color: {{ $cash['variance'] < 0 ? '#dc2626' : '#16a34a' }}">{{ $signed($cash['variance']) }}</span></div>
        </div>
        <div class="cs-card">
            <h3>{{ __('payments::payments.cash.sessions') }}</h3>
            @foreach ($r['sessions'] as $s)
                <div class="cs-row"><span>{{ $s['number'] }} {{ $s['till'] }} · {{ $s['cashier'] }}</span><span>{{ $s['counted'] === null ? __('payments::payments.cash.status.open') : $signed($s['variance']) }}</span></div>
            @endforeach
        </div>
        <div class="cs-card">
            <h3>{{ __('pos::pos.today.top_items') }}</h3>
            @foreach ($r['top_items'] as $row)
                <div class="cs-row"><span>{{ $row['name'] }} × {{ $row['quantity'] }}</span><span>{{ $m($row['total']) }}</span></div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
