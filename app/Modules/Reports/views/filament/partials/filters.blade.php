<style>
    .rp-filters { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; margin-bottom: 12px; }
    .rp-filters label { display: block; font-size: .75rem; opacity: .7; margin-bottom: 2px; }
    .rp-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 12px; }
    .rp-kpi { border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 10px 12px; }
    .rp-kpi span { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; opacity: .65; }
    .rp-kpi strong { font-size: 1.25rem; }
    .rp-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
    .rp-table th, .rp-table td { padding: 6px; border-bottom: 1px solid rgba(128,128,128,.25); text-align: left; vertical-align: top; }
    .rp-table th.r, .rp-table td.r { text-align: right; }
    .rp-table tfoot td { font-weight: 700; border-top: 2px solid rgba(128,128,128,.5); }
    .rp-bar { display: inline-block; height: 8px; border-radius: 4px; background: #10b981; vertical-align: middle; margin-right: 6px; }
</style>
<div class="rp-filters">
    <div><label>{{ __('reports::reports.filters.from') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
    <div><label>{{ __('reports::reports.filters.to') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
    @foreach (['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month'] as $preset)
        <x-filament::button color="gray" size="sm" wire:click="preset('{{ $preset }}')">{{ __('reports::reports.filters.'.$preset) }}</x-filament::button>
    @endforeach
</div>
