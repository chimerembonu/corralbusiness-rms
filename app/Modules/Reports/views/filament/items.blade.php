@php($d = $this->data())
@php($c = fn (string $k) => __('reports::reports.items.columns.'.$k))
@php($m = fn (int $p) => money($p))
<x-filament-panels::page>
    @include('reports::filament.partials.filters')
    @if (empty($d['by_item']))
        <x-filament::section><p class="text-sm text-gray-500">{{ __('reports::reports.empty') }}</p></x-filament::section>
    @else
        <x-filament::section :heading="__('reports::reports.items.by_category')">
            <table class="rp-table"><thead><tr><th>{{ $c('category') }}</th><th class="r">{{ $c('quantity') }}</th><th class="r">{{ $c('gross') }}</th><th class="r">{{ $c('share') }}</th></tr></thead>
                <tbody>@foreach ($d['by_category'] as $r)<tr><td><span class="rp-bar" style="width:{{ max(4, round($r['share'])) }}px"></span>{{ $r['category'] }}</td><td class="r">{{ $r['quantity'] }}</td><td class="r"><strong>{{ $m($r['gross']) }}</strong></td><td class="r">{{ $r['share'] }}%</td></tr>@endforeach</tbody></table>
        </x-filament::section>
        <x-filament::section :heading="__('reports::reports.items.by_item')">
            <table class="rp-table"><thead><tr><th>{{ $c('item') }}</th><th>{{ $c('category') }}</th><th class="r">{{ $c('quantity') }}</th><th class="r">{{ $c('gross') }}</th><th class="r">{{ $c('net') }}</th><th class="r">{{ $c('share') }}</th></tr></thead>
                <tbody>@foreach ($d['by_item'] as $r)<tr><td><strong>{{ $r['name'] }}</strong></td><td>{{ $r['category'] }}</td><td class="r">{{ $r['quantity'] }}</td><td class="r">{{ $m($r['gross']) }}</td><td class="r">{{ $m($r['net']) }}</td><td class="r">{{ $r['share'] }}%</td></tr>@endforeach</tbody></table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
