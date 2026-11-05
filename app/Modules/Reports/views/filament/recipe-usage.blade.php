@php($d = $this->data())
@php($c = fn (string $k) => __('reports::reports.usage.columns.'.$k))
@php($m = fn (int $p) => money($p))
@php($q = fn (float $n) => \App\Modules\Inventory\Services\StockService::qty($n))
<x-filament-panels::page>
    @include('reports::filament.partials.filters-style')
    @if ($this->takes() === [])
        <x-filament::section><p class="text-sm text-gray-500">{{ __('reports::reports.usage.none') }}</p></x-filament::section>
    @else
        <div class="rp-filters">
            <div style="min-width: 280px">
                <label>{{ __('reports::reports.usage.take') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="take">
                        @foreach ($this->takes() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>
        @if ($d)
            <div class="rp-kpis">
                @foreach (['recipe_value', 'waste_value', 'missing_value'] as $key)
                    <div class="rp-kpi"><span>{{ __('reports::reports.usage.kpis.'.$key) }}</span><strong>{{ $m($d['totals'][$key]) }}</strong></div>
                @endforeach
            </div>
            <x-filament::section :heading="$d['take']->displayNumber()">
                @if ($d['rows'] === [])
                    <p class="text-sm text-gray-500">{{ __('reports::reports.usage.nothing') }}</p>
                @else
                    <table class="rp-table">
                        <thead><tr><th>{{ $c('ingredient') }}</th><th>{{ $c('since') }}</th><th class="r">{{ $c('purchased') }}</th><th class="r">{{ $c('recipe') }}</th><th class="r">{{ $c('waste') }}</th><th class="r">{{ $c('missing') }}</th><th class="r">{{ $c('actual') }}</th><th class="r">{{ $c('missing_pct') }}</th><th class="r">{{ $c('missing_value') }}</th></tr></thead>
                        <tbody>
                            @foreach ($d['rows'] as $r)
                                <tr>
                                    <td>{{ $r['name'] }}</td><td>{{ $r['since'] ?? __('reports::reports.usage.first_count') }}</td>
                                    <td class="r">{{ $q($r['purchased']) }} {{ $r['unit'] }}</td>
                                    <td class="r">{{ $q($r['recipe']) }} {{ $r['unit'] }}</td>
                                    <td class="r">{{ $q($r['waste']) }} {{ $r['unit'] }}</td>
                                    <td class="r" style="{{ $r['missing'] > 0 ? 'color:#e11d48;font-weight:700' : '' }}">{{ $q($r['missing']) }} {{ $r['unit'] }}</td>
                                    <td class="r">{{ $q($r['actual']) }} {{ $r['unit'] }}</td>
                                    <td class="r">{{ $r['missing_pct'] === null ? '—' : $r['missing_pct'].'%' }}</td>
                                    <td class="r"><strong>{{ $m($r['missing_value']) }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><td colspan="8">{{ __('reports::reports.total') }}</td><td class="r">{{ $m($d['totals']['missing_value']) }}</td></tr></tfoot>
                    </table>
                @endif
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
