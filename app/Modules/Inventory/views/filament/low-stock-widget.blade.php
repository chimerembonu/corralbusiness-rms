@php($rows = $this->rows())
<x-filament-widgets::widget>
    <x-filament::section :heading="__('inventory::inventory.low_stock.title').($rows->isNotEmpty() ? ' ('.$rows->count().')' : '')" icon="heroicon-o-exclamation-triangle">
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">{{ __('inventory::inventory.low_stock.empty') }}</p>
        @else
            <table style="width:100%;font-size:.9rem;border-collapse:collapse">
                <thead><tr style="text-align:left;opacity:.7"><th style="padding:4px">{{ __('inventory::inventory.fields.name') }}</th><th style="padding:4px">{{ __('inventory::inventory.fields.category') }}</th><th style="padding:4px;text-align:right">{{ __('inventory::inventory.fields.on_hand') }}</th><th style="padding:4px;text-align:right">{{ __('inventory::inventory.low_stock.reorder') }}</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr style="border-top:1px solid rgba(128,128,128,.2)"><td style="padding:4px;font-weight:600">{{ $row['name'] }}</td><td style="padding:4px;opacity:.7">{{ $row['kind'] }}</td><td style="padding:4px;text-align:right;color:#dc2626;font-weight:700">{{ $row['qty'] }}</td><td style="padding:4px;text-align:right">{{ $row['reorder'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
