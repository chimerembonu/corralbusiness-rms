@php($t = fn (string $key, array $r = []) => __('settings::settings.getting_started.'.$key, $r))
<x-filament-widgets::widget>
    @unless ($hidden)
        @php($steps = $this->steps())
        @php($left = collect($steps)->reject(fn ($s) => $s['done'] || $s['optional'])->count())
        <x-filament::section :heading="$t('title')" :description="$t('help', ['left' => $left])" icon="heroicon-o-rocket-launch">
            <ol style="list-style:none;margin:0;padding:0">
                @foreach ($steps as $step)
                    <li style="display:flex;gap:.75rem;align-items:flex-start;padding:.6rem 0;border-top:1px solid rgba(128,128,128,.2)">
                        <span role="img" aria-label="{{ $step['done'] ? $t('done') : $t('to_do') }}" style="flex:none;width:1.5rem;height:1.5rem;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;{{ $step['done'] ? 'background:#10b981;color:#fff' : 'border:2px solid rgba(128,128,128,.5)' }}">{{ $step['done'] ? '✓' : '' }}</span>
                        <span style="flex:1;min-width:0">
                            <strong style="{{ $step['done'] ? 'opacity:.6;text-decoration:line-through' : '' }}">{{ $t('steps.'.$step['key'].'.name') }}</strong>
                            @if ($step['optional'])<span style="opacity:.6;font-size:.8rem"> · {{ $t('optional') }}</span>@endif
                            @unless ($step['done'])
                                <br><span style="font-size:.85rem;opacity:.75">{{ $t('steps.'.$step['key'].'.how') }}</span>
                            @endunless
                        </span>
                        @unless ($step['done'])
                            <span style="flex:none;display:flex;gap:.5rem;flex-wrap:wrap;justify-content:flex-end">
                                @if ($step['url'])<x-filament::button size="sm" tag="a" :href="$step['url']">{{ $t('steps.'.$step['key'].'.go') }}</x-filament::button>@endif
                                @if ($step['extra'])<x-filament::button size="sm" color="gray" tag="a" :href="$step['extra']">{{ $t('from_excel') }}</x-filament::button>@endif
                            </span>
                        @endunless
                    </li>
                @endforeach
            </ol>
            <div style="margin-top:.75rem">
                <x-filament::link tag="button" wire:click="hide" color="gray" size="sm">{{ $t('hide') }}</x-filament::link>
            </div>
        </x-filament::section>
    @endunless
</x-filament-widgets::widget>
