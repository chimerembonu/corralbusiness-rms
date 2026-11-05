@include('reports::filament.partials.filters-style')
<div class="rp-filters">
    <div><label>{{ __('reports::reports.filters.from') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
    <div><label>{{ __('reports::reports.filters.to') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
    @foreach (['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month'] as $preset)
        <x-filament::button color="gray" size="sm" wire:click="preset('{{ $preset }}')">{{ __('reports::reports.filters.'.$preset) }}</x-filament::button>
    @endforeach
</div>
