@php($d = $this->data())
@php($s = $d['summary'])
@php($c = fn (string $k) => __('reports::reports.sales.columns.'.$k))
@php($k = fn (string $key) => __('reports::reports.sales.kpi.'.$key))
@php($m = fn (int $p) => money($p))
<x-filament-panels::page>
    @include('reports::filament.partials.filters')
    <div class="rp-kpis">
        @foreach (['gross_sales', 'net_sales', 'tax_total', 'average_order', 'average_per_day', 'discount_total', 'service_charge_total', 'delivery_fee_total', 'tip_total', 'refund_total', 'void_value'] as $key)
            <div class="rp-kpi"><span>{{ $k($key) }}</span><strong>{{ $m($s[$key]) }}</strong></div>
        @endforeach
        <div class="rp-kpi"><span>{{ $k('orders') }}</span><strong>{{ $s['orders'] }}</strong> <small style="opacity:.6">· {{ $s['orders_void'] }} {{ strtolower($k('orders_void')) }} · {{ $s['covers'] }} {{ strtolower($k('covers')) }}</small></div>
    </div>

    @if ($s['orders'] === 0 && $s['orders_void'] === 0)
        <x-filament::section><p class="text-sm text-gray-500">{{ __('reports::reports.empty') }}</p></x-filament::section>
    @else
        <x-filament::section :heading="__('reports::reports.sales.by_day')">
            <table class="rp-table">
                <thead><tr><th>{{ $c('date') }}</th><th class="r">{{ $c('orders') }}</th><th class="r">{{ $c('gross') }}</th><th class="r">{{ $c('net') }}</th><th class="r">{{ $c('tax') }}</th><th class="r">{{ $c('discounts') }}</th></tr></thead>
                <tbody>@foreach ($d['by_day'] as $r)<tr><td>{{ \Carbon\Carbon::parse($r['date'])->translatedFormat('D j M') }}</td><td class="r">{{ $r['orders'] }}</td><td class="r"><strong>{{ $m($r['gross']) }}</strong></td><td class="r">{{ $m($r['net']) }}</td><td class="r">{{ $m($r['tax']) }}</td><td class="r">{{ $m($r['discounts']) }}</td></tr>@endforeach</tbody>
                <tfoot><tr><td>{{ __('reports::reports.total') }}</td><td class="r">{{ $s['orders'] }}</td><td class="r">{{ $m($s['gross_sales']) }}</td><td class="r">{{ $m($s['net_sales']) }}</td><td class="r">{{ $m($s['tax_total']) }}</td><td class="r">{{ $m($s['discount_total']) }}</td></tr></tfoot>
            </table>
        </x-filament::section>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px">
            <x-filament::section :heading="__('reports::reports.sales.by_hour')">
                @php($max = max(1, collect($d['by_hour'])->max('gross') ?? 1))
                <table class="rp-table"><thead><tr><th>{{ $c('hour') }}</th><th class="r">{{ $c('orders') }}</th><th class="r">{{ $c('gross') }}</th></tr></thead>
                    <tbody>@foreach ($d['by_hour'] as $r)<tr><td><span class="rp-bar" style="width:{{ max(4, round($r['gross'] / $max * 80)) }}px"></span>{{ $r['label'] }}</td><td class="r">{{ $r['orders'] }}</td><td class="r">{{ $m($r['gross']) }}</td></tr>@endforeach</tbody></table>
            </x-filament::section>
            <x-filament::section :heading="__('reports::reports.sales.by_type')">
                <table class="rp-table"><thead><tr><th>{{ $c('type') }}</th><th class="r">{{ $c('orders') }}</th><th class="r">{{ $c('gross') }}</th><th class="r">{{ $c('average') }}</th></tr></thead>
                    <tbody>@foreach ($d['by_type'] as $r)<tr><td>{{ $r['label'] }}</td><td class="r">{{ $r['orders'] }}</td><td class="r">{{ $m($r['gross']) }}</td><td class="r">{{ $m($r['average']) }}</td></tr>@endforeach</tbody></table>
            </x-filament::section>
            <x-filament::section :heading="__('reports::reports.sales.by_payment')">
                <table class="rp-table"><thead><tr><th>{{ $c('method') }}</th><th class="r">{{ $c('count') }}</th><th class="r">{{ $c('total') }}</th><th class="r">{{ $c('tips') }}</th><th class="r">{{ $c('refunded') }}</th></tr></thead>
                    <tbody>@foreach ($d['by_payment'] as $r)<tr><td>{{ $r['label'] }}</td><td class="r">{{ $r['count'] }}</td><td class="r">{{ $m($r['total']) }}</td><td class="r">{{ $m($r['tips']) }}</td><td class="r">{{ $m($r['refunded']) }}</td></tr>@endforeach</tbody></table>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('reports::reports.sales.by_staff')">
            <table class="rp-table"><thead><tr><th>{{ $c('staff') }}</th><th>{{ $c('role') }}</th><th class="r">{{ $c('orders') }}</th><th class="r">{{ $c('sales') }}</th><th class="r">{{ $c('average') }}</th><th class="r">{{ $c('voids') }}</th><th class="r">{{ $c('payments') }}</th><th class="r">{{ $c('taken') }}</th><th class="r">{{ $c('tips') }}</th></tr></thead>
                <tbody>@foreach ($d['by_staff'] as $r)<tr><td><strong>{{ $r['name'] }}</strong></td><td>{{ $r['role'] }}</td><td class="r">{{ $r['orders'] }}</td><td class="r">{{ $m($r['sales']) }}</td><td class="r">{{ $m($r['average']) }}</td><td class="r">{{ $r['voids'] }}</td><td class="r">{{ $r['payments'] }}</td><td class="r">{{ $m($r['taken']) }}</td><td class="r">{{ $m($r['tips']) }}</td></tr>@endforeach</tbody></table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
