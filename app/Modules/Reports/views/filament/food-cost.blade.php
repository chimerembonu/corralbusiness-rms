@php($d = $this->data())
@php($c = fn (string $k) => __('reports::reports.food_cost.columns.'.$k))
@php($k = fn (string $key) => __('reports::reports.food_cost.kpis.'.$key))
@php($m = fn (int $p) => money($p))
@php($pct = fn (?float $p) => $p === null ? '—' : $p.'%')
@php($t = $d['totals'])
<x-filament-panels::page>
    @include('reports::filament.partials.filters')
    <div class="rp-kpis">
        <div class="rp-kpi"><span>{{ $k('net') }}</span><strong>{{ $m($t['net']) }}</strong></div>
        <div class="rp-kpi"><span>{{ $k('cost') }}</span><strong>{{ $m($t['cost']) }}</strong></div>
        <div class="rp-kpi"><span>{{ $k('margin') }}</span><strong>{{ $m($t['margin']) }}</strong></div>
        <div class="rp-kpi"><span>{{ $k('food_cost_pct') }}</span><strong>{{ $pct($t['food_cost_pct']) }}</strong></div>
    </div>
    @if ($t['uncosted_net'] > 0)
        <p class="text-sm" style="margin-bottom: 12px; opacity: .8">{{ __('reports::reports.food_cost.uncosted_help', ['count' => $t['uncosted_dishes'], 'amount' => $m($t['uncosted_net'])]) }}</p>
    @endif
    @if (empty($d['dishes']))
        <x-filament::section><p class="text-sm text-gray-500">{{ __('reports::reports.empty') }}</p></x-filament::section>
    @else
        @foreach (['categories' => 'by_category', 'dishes' => 'by_dish'] as $list => $heading)
            <x-filament::section :heading="__('reports::reports.food_cost.'.$heading)">
                <table class="rp-table">
                    <thead><tr><th>{{ $list === 'dishes' ? $c('dish') : $c('category') }}</th>@if ($list === 'dishes')<th>{{ $c('category') }}</th>@endif<th class="r">{{ $c('quantity') }}</th><th class="r">{{ $c('net') }}</th><th class="r">{{ $c('cost') }}</th><th class="r">{{ $c('margin') }}</th><th class="r">{{ $c('food_cost_pct') }}</th></tr></thead>
                    <tbody>
                        @foreach ($d[$list] as $r)
                            <tr>
                                <td>{{ $r['name'] }}@if ($r['costed'] !== 'all') <small style="opacity:.6">· {{ __('reports::reports.food_cost.'.($r['costed'] === 'none' ? 'no_cost' : 'some_cost')) }}</small>@endif</td>
                                @if ($list === 'dishes')<td>{{ $r['category'] }}</td>@endif
                                <td class="r">{{ $r['quantity'] }}</td><td class="r">{{ $m($r['net']) }}</td>
                                <td class="r">{{ $r['costed'] === 'none' ? '—' : $m($r['cost']) }}</td>
                                <td class="r">{{ $r['costed'] === 'none' ? '—' : $m($r['margin']) }}</td>
                                <td class="r"><strong>{{ $pct($r['food_cost_pct']) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($list === 'categories')
                        <tfoot><tr><td>{{ __('reports::reports.total') }}</td><td></td><td class="r">{{ $m($t['net']) }}</td><td class="r">{{ $m($t['cost']) }}</td><td class="r">{{ $m($t['margin']) }}</td><td class="r">{{ $pct($t['food_cost_pct']) }}</td></tr></tfoot>
                    @endif
                </table>
            </x-filament::section>
        @endforeach
    @endif
</x-filament-panels::page>
