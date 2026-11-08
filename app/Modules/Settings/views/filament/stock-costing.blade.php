@php($t = fn (string $key, array $r = []) => __('settings::settings.costing.'.$key, $r))
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        @if ($this->canChange())
            <div class="mt-6">
                <x-filament::button type="submit">{{ $t('save') }}</x-filament::button>
            </div>
        @endif
    </form>

    <x-filament::section :heading="$t('fifo_title')">
        <p class="text-sm">{{ $t('fifo') }}</p>
    </x-filament::section>

    <x-filament::section :heading="$t('history')">
        @php($changes = $this->changes())
        @if ($changes->isEmpty())
            <p class="text-sm opacity-70">{{ $t('history_none') }}</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                @foreach ($changes as $change)
                    @php($new = \App\Modules\Inventory\Enums\CostMethod::tryFrom((string) ($change->properties['new'] ?? '')))
                    <li class="py-2">
                        <strong>{{ $change->created_at->timezone(config('app.timezone'))->format('j M Y, H:i') }}</strong>
                        · {{ $new?->label() ?? '—' }}
                        · {{ $change->causer?->name ?? $t('nobody') }}
                        @if ($change->properties['at_setup'] ?? false)
                            · {{ $t('at_setup') }}
                        @endif
                        @if (filled($change->properties['reason'] ?? null))
                            <br><span class="opacity-70">{{ $change->properties['reason'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
