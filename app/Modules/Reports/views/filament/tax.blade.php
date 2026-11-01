@php($d = $this->data())
@php($c = fn (string $k) => __('reports::reports.tax.columns.'.$k))
@php($k = fn (string $key) => __('reports::reports.tax.kpi.'.$key))
@php($m = fn (int $p) => money($p))
<x-filament-panels::page>
    @include('reports::filament.partials.filters')
    <div class="rp-kpis">
        @foreach (['gross', 'taxable_net', 'exempt_net', 'tax_total'] as $key)
            <div class="rp-kpi"><span>{{ $k($key) }}</span><strong>{{ $m($d[$key]) }}</strong></div>
        @endforeach
    </div>
    <x-filament::section :heading="__('reports::reports.tax.components')">
        <p class="text-sm text-gray-500" style="margin-bottom:8px">{{ __('reports::reports.tax.hint') }}</p>
        @if (empty($d['components']))
            <p class="text-sm text-gray-500">{{ __('reports::reports.empty') }}</p>
        @else
            <table class="rp-table"><thead><tr><th>{{ $c('component') }}</th><th>{{ $c('rate') }}</th><th class="r">{{ $c('taxable_net') }}</th><th class="r">{{ $c('tax') }}</th></tr></thead>
                <tbody>@foreach ($d['components'] as $r)<tr><td><strong>{{ $r['name'] }}</strong> <small style="opacity:.6">{{ $r['code'] }}</small></td><td>{{ $r['rate'] }}</td><td class="r">{{ $m($r['taxable_net']) }}</td><td class="r"><strong>{{ $m($r['tax']) }}</strong></td></tr>@endforeach</tbody>
                <tfoot><tr><td colspan="2">{{ __('reports::reports.total') }}</td><td class="r">{{ $m($d['taxable_net']) }}</td><td class="r">{{ $m($d['tax_total']) }}</td></tr></tfoot></table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
