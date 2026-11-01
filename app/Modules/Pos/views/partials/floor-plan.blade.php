{{--
    Floor plan canvas: tables positioned on a COLS × ROWS grid as percentages, so the plan scales
    to any screen. $tables: collection of DiningTable (statuses loaded). $onTap: Livewire method.
    $highlight (optional): table id to outline. $disabled (optional): ids that cannot be tapped.
--}}
@php($cols = \App\Modules\Orders\Models\DiningTable::COLS)
@php($rows = \App\Modules\Orders\Models\DiningTable::ROWS)
@php($disabled = collect($disabled ?? []))
<div class="relative w-full rounded-3xl bg-slate-900/60 ring-1 ring-slate-800" style="aspect-ratio: {{ $cols }} / {{ $rows }}; background-image: linear-gradient(to right, rgb(30 41 59 / .5) 1px, transparent 1px), linear-gradient(to bottom, rgb(30 41 59 / .5) 1px, transparent 1px); background-size: {{ 100 / $cols }}% {{ 100 / $rows }}%;">
    @foreach ($tables as $table)
        @php($status = $table->status())
        @php($order = $table->isMerged() ? $table->mergedInto?->currentOrder : $table->currentOrder)
        @php($colour = match ($status) {
            \App\Modules\Orders\Enums\TableStatus::Free => 'bg-slate-800 ring-slate-700 hover:ring-emerald-500',
            \App\Modules\Orders\Enums\TableStatus::Seated => 'bg-emerald-500/25 ring-emerald-500',
            \App\Modules\Orders\Enums\TableStatus::Ordered => 'bg-amber-400/25 ring-amber-400',
            \App\Modules\Orders\Enums\TableStatus::Merged => 'bg-sky-500/20 ring-sky-500 border-dashed',
        })
        @php($off = $disabled->contains($table->id))
        <button type="button" wire:click="{{ $onTap }}('{{ $table->id }}')" @disabled($off)
                class="absolute flex flex-col items-center justify-center p-1 text-center ring-2 transition {{ $colour }} {{ $table->shape->value === 'round' ? 'rounded-full' : 'rounded-2xl' }} {{ $off ? 'opacity-30' : '' }} {{ isset($highlight) && $highlight === $table->id ? 'outline outline-4 outline-white' : '' }}"
                style="left: {{ $table->pos_x / $cols * 100 }}%; top: {{ $table->pos_y / $rows * 100 }}%; width: {{ $table->width / $cols * 100 }}%; height: {{ $table->height / $rows * 100 }}%; min-width: 48px; min-height: 48px;"
                data-status="{{ $status->value }}" aria-label="{{ $table->name }} · {{ $status->label() }}">
            <span class="text-lg font-black leading-tight sm:text-xl">{{ $table->name }}</span>
            @if ($status === \App\Modules\Orders\Enums\TableStatus::Merged)
                <span class="text-[11px] font-semibold text-sky-300">→ {{ $table->mergedInto?->name }}</span>
            @elseif ($order)
                <span class="text-[11px] font-semibold leading-tight {{ $status === \App\Modules\Orders\Enums\TableStatus::Ordered ? 'text-amber-200' : 'text-emerald-200' }}">#{{ $order->displayNumber() }} · {{ money($order->total) }}</span>
                <span class="text-[10px] text-slate-300">{{ __('pos::pos.home.guests', ['count' => $order->covers]) }}</span>
            @else
                <span class="text-[11px] text-slate-400">{{ __('pos::pos.home.seats', ['count' => $table->totalSeats()]) }}</span>
            @endif
        </button>
    @endforeach
</div>
