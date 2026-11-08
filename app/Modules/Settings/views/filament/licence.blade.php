@php($l = fn (string $k, array $r = []) => __('settings::settings.licence.'.$k, $r))
@php($s = $this->summary())
<x-filament-panels::page>
    <style>
        .lc-grid { display: grid; gap: 4px 16px; grid-template-columns: auto 1fr; font-size: .9rem; }
        .lc-label { opacity: .65; }
        .lc-chip { display: inline-block; font-size: .75rem; padding: 2px 10px; border-radius: 999px; border: 1px solid rgba(128,128,128,.35); }
        .lc-ok { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.5); }
        .lc-warn { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.5); }
        .lc-list { display: flex; flex-wrap: wrap; gap: 6px; }
        .lc-code { width: 100%; min-height: 6rem; font-family: ui-monospace, monospace; font-size: .8rem; border-radius: 8px; border: 1px solid rgba(128,128,128,.4); padding: 8px; background: transparent; }
        .lc-btn { font-size: .9rem; padding: 8px 16px; border-radius: 8px; border: 1px solid rgba(128,128,128,.4); cursor: pointer; min-height: 44px; }
    </style>

    <x-filament::section :heading="$l('your_licence')">
        @if (! $s['licence'])
            <p class="text-sm">{{ $l('none_help') }}</p>
        @else
            <div class="lc-grid">
                <span class="lc-label">{{ $l('status_label') }}</span>
                <span><span class="lc-chip {{ $s['status_ok'] ? 'lc-ok' : 'lc-warn' }}">{{ $s['status_label'] }}</span></span>
                <span class="lc-label">{{ $l('restaurant') }}</span><span>{{ $s['licence']->restaurant }}</span>
                <span class="lc-label">{{ $l('plan') }}</span><span>{{ $s['plan'] }}</span>
                <span class="lc-label">{{ $l('branches') }}</span><span>{{ $l('branches_value', ['used' => $s['branches_used'], 'allowed' => $s['licence']->branches]) }}</span>
                <span class="lc-label">{{ $l('valid_until') }}</span><span>{{ $s['licence']->expires->translatedFormat('j F Y') }}</span>
                <span class="lc-label">{{ $l('grace_until') }}</span><span>{{ $s['read_only_from'] }}</span>
                <span class="lc-label">{{ $l('licence_number') }}</span><span>{{ $s['licence']->id }}</span>
            </div>
        @endif
        @if ($s['warning'])
            <p style="margin-top:.75rem;font-size:.9rem;font-weight:600;color:#b45309;" role="alert">{{ $s['warning'] }}</p>
        @endif
        @if ($s['clock_behind'])
            <p style="margin-top:.75rem;font-size:.9rem;font-weight:600;color:#b45309;" role="alert">{{ $l('clock_behind', ['date' => $s['clock_behind']]) }}</p>
        @endif
        <p style="margin-top:.75rem;font-size:.8rem;opacity:.65;">{{ $l('restaurant_id', ['id' => $s['restaurant_id']]) }}</p>
    </x-filament::section>

    @if ($s['licence'])
        <x-filament::section :heading="$l('included')">
            <div class="lc-list">
                @forelse ($s['included'] as $name)
                    <span class="lc-chip">{{ $name }}</span>
                @empty
                    <span class="text-sm">{{ $l('included_none') }}</span>
                @endforelse
            </div>
            <p style="margin-top:.75rem;font-size:.8rem;opacity:.65;">{{ $l('included_help') }}</p>
        </x-filament::section>
    @endif

    <x-filament::section :heading="$l('enter_code')">
        @if ($this->canInstall())
            <form wire:submit="install" style="display:flex;flex-direction:column;gap:12px;">
                <textarea wire:model="code" class="lc-code" aria-label="{{ $l('code') }}" placeholder="CBL1...."></textarea>
                @error('code') <span class="text-sm text-danger-600">{{ $message }}</span> @enderror
                <button type="submit" class="lc-btn" style="align-self:flex-start">{{ $l('install') }}</button>
            </form>
            <p style="margin-top:.75rem;font-size:.8rem;opacity:.65;">{{ $l('enter_code_help') }}</p>
        @else
            <p class="text-sm text-gray-500">{{ $l('owner_only') }}</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
