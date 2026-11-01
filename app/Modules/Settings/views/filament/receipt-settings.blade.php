<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit">{{ __('settings::settings.receipt.save') }}</x-filament::button>
            <x-filament::button tag="a" color="gray" :href="route('receipt.preview')" target="_blank">{{ __('settings::settings.receipt.preview') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
