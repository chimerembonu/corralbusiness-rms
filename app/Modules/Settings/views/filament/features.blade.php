@php($f = fn (string $k, array $r = []) => __('settings::settings.features.'.$k, $r))
<x-filament-panels::page>
    <style>
        .ft-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
        .ft-card { border: 1px solid rgba(128,128,128,.25); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 8px; }
        .ft-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .ft-name { font-weight: 600; }
        .ft-about { font-size: .85rem; opacity: .8; }
        .ft-chip { font-size: .72rem; padding: 2px 8px; border-radius: 999px; white-space: nowrap; border: 1px solid rgba(128,128,128,.35); }
        .ft-on { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.5); }
        .ft-block { font-size: .8rem; color: #b45309; }
        .ft-btn { align-self: flex-start; font-size: .85rem; padding: 6px 12px; border-radius: 8px; border: 1px solid rgba(128,128,128,.4); cursor: pointer; }
        .ft-btn[disabled] { opacity: .45; cursor: not-allowed; }
    </style>
    @unless ($this->canSwitch())
        <p class="text-sm text-gray-500">{{ $f('owner_only') }}</p>
    @endunless
    @foreach ($this->groups() as $group => $features)
        <x-filament::section :heading="$f('groups.'.$group)">
            <div class="ft-grid">
                @foreach ($features as $feature)
                    <div class="ft-card" wire:key="ft-{{ $feature['key'] }}">
                        <div class="ft-head">
                            <span class="ft-name">{{ $feature['name'] }}</span>
                            <span class="ft-chip {{ $feature['on'] ? 'ft-on' : '' }}">
                                @if (! $feature['available']) {{ $f('coming', ['stage' => $feature['stage']]) }} @else {{ $feature['on'] ? $f('on') : $f('off') }} @endif
                            </span>
                        </div>
                        <span class="ft-about">{{ $feature['about'] }}</span>
                        <span class="ft-chip" style="align-self:flex-start">{{ $feature['tier'] }}</span>
                        @if ($feature['available'])
                            @foreach ($feature['blockers'] as $blocker)
                                <span class="ft-block">{{ $blocker }}</span>
                            @endforeach
                            @if ($this->canSwitch())
                                <button type="button" class="ft-btn" wire:click="toggle('{{ $feature['key'] }}')" @disabled(! $feature['switched_on'] && $feature['blockers'] !== [])>
                                    {{ $feature['switched_on'] ? $f('switch_off') : $f('switch_on') }}
                                </button>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
