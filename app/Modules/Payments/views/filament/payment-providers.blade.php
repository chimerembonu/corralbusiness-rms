@php($g = fn (string $k, array $r = []) => __('payments::payments.gateway.page.'.$k, $r))
<x-filament-panels::page>
    <style>
        .pp-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        .pp-card { border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 6px; }
        .pp-chip { font-size: .72rem; padding: 2px 8px; border-radius: 999px; border: 1px solid rgba(128,128,128,.35); align-self: flex-start; }
        .pp-ok { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.5); }
        .pp-row { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; align-items: center; margin-bottom: 10px; }
        .pp-select { width: 100%; border-radius: 8px; padding: 6px 8px; border: 1px solid rgba(128,128,128,.4); background: transparent; }
        .pp-table { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .pp-table td, .pp-table th { padding: 6px; border-bottom: 1px solid rgba(128,128,128,.2); text-align: left; }
    </style>

    <x-filament::section :heading="$g('defaults')" :description="$g('defaults_help')">
        <form wire:submit="save">
            @foreach ($this->purposes() as $purpose => $info)
                <div class="pp-row">
                    <label for="pp-{{ $purpose }}"><strong>{{ $info['label'] }}</strong><br>
                        <span class="text-xs text-gray-500">{{ $g('in_use', ['provider' => $info['in_use'] ? __('payments::payments.gateway.names.'.$info['in_use']) : $g('none')]) }}</span>
                    </label>
                    <select id="pp-{{ $purpose }}" class="pp-select" wire:model="defaults.{{ $purpose }}">
                        <option value="">{{ $g('automatic') }}</option>
                        @foreach ($info['options'] as $key => $name)
                            <option value="{{ $key }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach
            <x-filament::button type="submit">{{ $g('save') }}</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section :heading="$g('providers')" :description="$g('providers_help')">
        <div class="pp-grid">
            @foreach ($this->providers() as $p)
                <div class="pp-card" wire:key="pp-{{ $p['key'] }}">
                    <strong>{{ $p['name'] }}</strong>
                    <span class="pp-chip {{ $p['ready'] ? 'pp-ok' : '' }}">{{ $p['ready'] ? $g('ready') : $g('not_ready') }}</span>
                    <span class="text-sm">{{ implode(' · ', $p['capabilities']) }}</span>
                    @if ($p['networks'])<span class="text-xs text-gray-500">{{ implode(', ', $p['networks']) }}</span>@endif
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-sm"><a class="underline" href="{{ \App\Modules\Settings\Filament\Pages\ConnectionsPage::getUrl() }}">{{ $g('to_connections') }}</a></p>
    </x-filament::section>

    <x-filament::section :heading="$g('attention')" :description="$g('attention_help')">
        @php($attention = $this->attention())
        @if ($attention->isEmpty())
            <p class="text-sm text-gray-500">{{ $g('attention_none') }}</p>
        @else
            <table class="pp-table">
                <thead><tr><th>{{ $g('when') }}</th><th>{{ $g('order') }}</th><th>{{ $g('amount') }}</th><th>{{ $g('provider') }}</th><th>{{ $g('reference') }}</th><th>{{ $g('why') }}</th></tr></thead>
                <tbody>
                    @foreach ($attention as $t)
                        <tr>
                            <td>{{ $t->created_at->format('d/m H:i') }}</td>
                            <td>#{{ $t->order?->displayNumber() }}</td>
                            <td>{{ money($t->amount->add($t->tip)) }}</td>
                            <td>{{ __('payments::payments.gateway.names.'.$t->gateway) }}</td>
                            <td>{{ $t->provider_reference ?? $t->reference }}</td>
                            <td>{{ $t->message }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
