<x-filament-panels::page>
    @php($s = $this->getSession())
    @php($f = fn (string $k) => __('payments::payments.cash.fields.'.$k))
    @php($m = fn ($p) => $p === null ? '—' : money($p))
    <style>
        .cs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .cs-card { border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 14px 16px; }
        .cs-card h3 { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; opacity: .6; margin: 0 0 8px; }
        .cs-row { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; font-size: .9rem; }
        .cs-row.bold { font-weight: 700; }
        .cs-variance { font-size: 1.5rem; font-weight: 800; }
        table.cs-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
        table.cs-table td, table.cs-table th { padding: 6px 4px; border-bottom: 1px solid rgba(128,128,128,.2); text-align: left; }
        table.cs-table td.r, table.cs-table th.r { text-align: right; }
    </style>
    <div class="cs-grid">
        <div class="cs-card">
            <h3>{{ __('payments::payments.cash.session') }}</h3>
            <div class="cs-row"><span>{{ $f('number') }}</span><span>{{ $s->displayNumber() }}</span></div>
            <div class="cs-row"><span>{{ $f('date') }}</span><span>{{ $s->business_date->format('d M Y') }}</span></div>
            <div class="cs-row"><span>{{ $f('till') }}</span><span>{{ $s->till?->name ?? __('payments::payments.cash.no_till') }}</span></div>
            <div class="cs-row"><span>{{ $f('cashier') }}</span><span>{{ $s->user?->name }}</span></div>
            <div class="cs-row"><span>{{ $f('status') }}</span><span>{{ $s->status->label() }}</span></div>
            <div class="cs-row"><span>{{ $f('opened_at') }}</span><span>{{ $s->opened_at->format('d M H:i') }}</span></div>
            <div class="cs-row"><span>{{ $f('closed_at') }}</span><span>{{ $s->closed_at?->format('d M H:i') ?? '—' }}</span></div>
            <div class="cs-row"><span>{{ $f('closed_by') }}</span><span>{{ $s->closedBy?->name ?? '—' }}</span></div>
            @if ($s->approvedBy)<div class="cs-row"><span>{{ $f('approved_by') }}</span><span>{{ $s->approvedBy->name }}</span></div>@endif
            @if ($s->notes)<div class="cs-row"><span>{{ $f('notes') }}</span><span>{{ $s->notes }}</span></div>@endif
        </div>
        <div class="cs-card">
            <h3>{{ __('pos::pos.cash.title') }}</h3>
            <div class="cs-row"><span>{{ $f('opening_float') }}</span><span>{{ $m($s->opening_float) }}</span></div>
            <div class="cs-row"><span>+ {{ $f('cash_sales') }}</span><span>{{ $m($s->cash_sales) }}</span></div>
            <div class="cs-row"><span>+ {{ $f('cash_tips') }}</span><span>{{ $m($s->cash_tips) }}</span></div>
            <div class="cs-row"><span>+ {{ $f('pay_ins') }}</span><span>{{ $m($s->pay_ins) }}</span></div>
            <div class="cs-row"><span>− {{ $f('pay_outs') }}</span><span>{{ $m($s->pay_outs) }}</span></div>
            <div class="cs-row"><span>− {{ $f('cash_refunds') }}</span><span>{{ $m($s->cash_refunds) }}</span></div>
            <div class="cs-row bold"><span>{{ $f('expected') }}</span><span>{{ $m($s->expected_cash) }}</span></div>
            <div class="cs-row"><span>{{ $f('counted') }}</span><span>{{ $m($s->counted_cash) }}</span></div>
            <div class="cs-row bold"><span>{{ $f('variance') }}</span><span class="cs-variance" style="color: {{ ($s->variance ?? 0) < 0 ? '#dc2626' : '#16a34a' }}">{{ $s->variance === null ? '—' : \App\Modules\Printing\Documents\CashReportDocument::signed($s->variance, true) }}</span></div>
        </div>
        @if ($s->totals)
            <div class="cs-card">
                <h3>{{ $f('by_method') }}</h3>
                @foreach ($s->totals as $row)
                    <div class="cs-row"><span>{{ \App\Modules\Payments\Enums\PaymentMethod::from($row['method'])->label() }} × {{ $row['count'] }}</span><span>{{ money($row['total']) }}</span></div>
                @endforeach
            </div>
        @endif
        @if ($s->counted_breakdown)
            <div class="cs-card">
                <h3>{{ $f('count_sheet') }}</h3>
                @foreach ($s->counted_breakdown as $value => $count)
                    <div class="cs-row"><span>{{ money((int) $value) }} × {{ $count }}</span><span>{{ money((int) $value * (int) $count) }}</span></div>
                @endforeach
            </div>
        @endif
    </div>
    <div class="cs-card" style="margin-top: 12px">
        <h3>{{ $f('movements') }}</h3>
        @if ($s->movements->isEmpty())
            <p style="opacity:.6">—</p>
        @else
            <table class="cs-table">
                <thead><tr><th>{{ $f('time') }}</th><th>{{ __('payments::payments.cash.movement.pay_in') }} / {{ __('payments::payments.cash.movement.pay_out') }}</th><th>{{ $f('reason') }}</th><th>{{ $f('reference') }}</th><th>{{ $f('by') }}</th><th class="r">{{ $f('amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($s->movements as $mv)
                        <tr><td>{{ $mv->happened_at->format('H:i') }}</td><td>{{ $mv->type->label() }}</td><td>{{ $mv->reason }}</td><td>{{ $mv->reference ?? '—' }}</td><td>{{ $mv->user?->name }}</td><td class="r">{{ $mv->isPayIn() ? '+' : '−' }}{{ money($mv->amount) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
