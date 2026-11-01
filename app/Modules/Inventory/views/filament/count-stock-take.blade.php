@php($f = fn (string $k) => __('inventory::inventory.stock_take.fields.'.$k))
@php($q = fn (float $v) => \App\Modules\Inventory\Services\StockService::qty($v))
@php($open = $this->record->isOpen())
<x-filament-panels::page>
    <style>
        .st-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
        .st-table th, .st-table td { padding: 6px; border-bottom: 1px solid rgba(128,128,128,.25); text-align: left; vertical-align: middle; }
        .st-table th.r, .st-table td.r { text-align: right; }
        .st-table input { width: 7rem; text-align: right; font-weight: 700; }
        .st-neg { color: #dc2626; } .st-pos { color: #16a34a; }
        .st-cat { font-size: .72rem; opacity: .6; }
    </style>
    <x-filament::section>
        <x-filament::input.wrapper class="mb-3 max-w-sm">
            <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('inventory::inventory.stock_take.phone.search') }}" />
        </x-filament::input.wrapper>
        <table class="st-table">
            <thead><tr><th>{{ $f('name') }}</th><th class="r">{{ $f('expected') }}</th><th class="r">{{ $f('counted') }}</th><th class="r">{{ $f('difference') }}</th><th>{{ $f('counted_by') }}</th></tr></thead>
            <tbody>
                @foreach ($this->lines() as $line)
                    <tr wire:key="line-{{ $line->id }}">
                        <td><strong>{{ $line->name }}</strong> <span class="st-cat">{{ $line->unit }}</span></td>
                        <td class="r">{{ $q((float) $line->expected) }}</td>
                        <td class="r">
                            @if ($open)
                                <x-filament::input.wrapper style="display:inline-block">
                                    <x-filament::input type="number" step="0.001" min="0" inputmode="decimal" wire:model="counts.{{ $line->id }}" wire:change="saveCount('{{ $line->id }}')" placeholder="—" />
                                </x-filament::input.wrapper>
                            @else
                                {{ $line->isCounted() ? $q((float) $line->counted) : '—' }}
                            @endif
                        </td>
                        <td class="r {{ $line->difference() < 0 ? 'st-neg' : ($line->difference() > 0 ? 'st-pos' : '') }}">{{ $line->isCounted() ? ($line->difference() > 0 ? '+' : '').$q($line->difference()) : '—' }}</td>
                        <td>{{ $line->countedBy?->name }} <span class="st-cat">{{ $line->counted_at?->format('d M H:i') }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
