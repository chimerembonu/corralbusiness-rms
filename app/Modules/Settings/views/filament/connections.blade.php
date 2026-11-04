@php($c = fn (string $k, array $r = []) => __('settings::settings.connections.'.$k, $r))
<x-filament-panels::page>
    <style>
        .cn-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .cn-card { border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 8px; }
        .cn-head { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
        .cn-name { font-weight: 600; }
        .cn-about { font-size: .85rem; opacity: .8; }
        .cn-chip { font-size: .72rem; padding: 2px 8px; border-radius: 999px; white-space: nowrap; border: 1px solid rgba(128,128,128,.35); }
        .cn-ok { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.5); }
        .cn-bad { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.5); }
        .cn-details { font-size: .8rem; display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; }
        .cn-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .cn-btn { font-size: .85rem; padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(128,128,128,.4); cursor: pointer; }
    </style>
    <div class="cn-grid">
        @foreach ($this->connections() as $conn)
            <div class="cn-card" wire:key="cn-{{ $conn['name'] }}">
                <div class="cn-head">
                    <span class="cn-name">{{ $conn['label'] }}</span>
                    @if (! $conn['configured'])
                        <span class="cn-chip">{{ $c('not_set_up') }}</span>
                    @elseif ($conn['ready'])
                        <span class="cn-chip cn-ok">{{ $c('ready') }}</span>
                    @elseif ($conn['test'])
                        <span class="cn-chip cn-bad">{{ $c('failed') }}</span>
                    @else
                        <span class="cn-chip cn-bad">{{ $c('untested') }}</span>
                    @endif
                </div>
                <span class="cn-about">{{ $conn['about'] }}</span>
                @if ($conn['configured'])
                    <div class="cn-details">
                        @foreach ($conn['details'] as $field => $value)
                            <span class="text-gray-500">{{ $c('fields.'.$field) }}</span><span>{{ $value ?? '—' }}</span>
                        @endforeach
                    </div>
                @endif
                @if ($conn['test'])
                    <span class="text-xs {{ $conn['test']['ok'] ? 'text-success-600' : 'text-warning-600' }}">{{ $conn['test']['message'] }} · {{ $c('tested_at', ['when' => $conn['tested_at']]) }}</span>
                @endif
                <div class="cn-actions">
                    <button type="button" class="cn-btn" wire:click="mountAction('configure', { name: '{{ $conn['name'] }}' })">{{ $conn['configured'] ? $c('edit') : $c('set_up') }}</button>
                    @if ($conn['configured'])
                        <button type="button" class="cn-btn" wire:click="runTest('{{ $conn['name'] }}')">{{ $c('test') }}</button>
                        <button type="button" class="cn-btn" wire:click="mountAction('remove', { name: '{{ $conn['name'] }}' })">{{ $c('remove') }}</button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
