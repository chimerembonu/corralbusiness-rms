@php($stations = $this->getStations())
@php($map = $this->getMap())
@php($default = $stations->firstWhere('is_default', true))
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('kitchen::kitchen.routing_hint') }}
        @if ($default)<strong>{{ __('kitchen::kitchen.default_station') }}: {{ $default->name }}.</strong>@endif</p>

    <x-filament::section :heading="__('kitchen::kitchen.categories')">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($this->getCategories() as $category)
                    <tr wire:key="cat-{{ $category->id }}">
                        <td class="py-2 pr-4 font-medium">{{ $category->name }}</td>
                        <td class="py-2">
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:change="setRoute('category', '{{ $category->id }}', $event.target.value)">
                                    <option value="" @selected(! isset($map['category:'.$category->id]))>{{ $default ? __('kitchen::kitchen.default_station').' ('.$default->name.')' : __('kitchen::kitchen.no_station') }}</option>
                                    @foreach ($stations as $s)
                                        <option value="{{ $s->id }}" @selected(($map['category:'.$category->id] ?? null) === $s->id)>{{ $s->name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section :heading="__('kitchen::kitchen.item_overrides')">
        <x-filament::input.wrapper class="mb-3 max-w-sm">
            <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('menu::menu.fields.name') }}" />
        </x-filament::input.wrapper>
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($this->getItems() as $item)
                    <tr wire:key="item-{{ $item->id }}">
                        <td class="py-2 pr-4"><span class="font-medium">{{ $item->name }}</span> <span class="text-gray-500">· {{ $item->category?->name }}</span></td>
                        <td class="py-2">
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:change="setRoute('item', '{{ $item->id }}', $event.target.value)">
                                    <option value="" @selected(! isset($map['item:'.$item->id]))>{{ __('kitchen::kitchen.follows_category') }}</option>
                                    @foreach ($stations as $s)
                                        <option value="{{ $s->id }}" @selected(($map['item:'.$item->id] ?? null) === $s->id)>{{ $s->name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
