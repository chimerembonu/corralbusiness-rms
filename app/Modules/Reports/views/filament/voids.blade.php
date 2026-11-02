@php($d = $this->data())
@php($c = fn (string $k) => __('reports::reports.voids.columns.'.$k))
@php($m = fn (int $p) => money($p))
<x-filament-panels::page>
    @include('reports::filament.partials.filters')
    <div class="rp-kpis">
        @foreach (['voided_orders', 'voided_lines', 'removed_lines', 'discounts', 'refunds'] as $key)
            <div class="rp-kpi"><span>{{ __('reports::reports.voids.'.$key) }}</span><strong>{{ $m($d['totals'][$key]) }}</strong> <small style="opacity:.6">· {{ count($d[$key]) }}</small></div>
        @endforeach
    </div>
    @foreach (['voided_orders', 'voided_lines', 'removed_lines', 'discounts', 'refunds'] as $key)
        <x-filament::section :heading="__('reports::reports.voids.'.$key)">
            @if (empty($d[$key]))
                <p class="text-sm text-gray-500">—</p>
            @else
                <table class="rp-table"><thead><tr><th>{{ $c('when') }}</th><th>{{ $c('order') }}</th><th>{{ $c('detail') }}</th><th class="r">{{ $c('value') }}</th><th>{{ $c('by') }}</th></tr></thead>
                    <tbody>@foreach ($d[$key] as $r)<tr><td>{{ $r['when'] }}</td><td>{{ $r['order'] }}</td><td>{{ $r['detail'] }}</td><td class="r">{{ $m($r['value']) }}</td><td>{{ $r['by'] }}</td></tr>@endforeach</tbody>
                    <tfoot><tr><td colspan="3">{{ __('reports::reports.total') }}</td><td class="r">{{ $m($d['totals'][$key]) }}</td><td></td></tr></tfoot></table>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
