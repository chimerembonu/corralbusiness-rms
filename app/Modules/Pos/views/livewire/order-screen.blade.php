@php($order = $this->order)
<div class="flex h-full">
    {{-- Order panel --}}
    <aside class="flex w-[38%] min-w-[320px] flex-col border-r border-slate-800 bg-slate-900">
        <div class="flex items-center justify-between px-4 py-3">
            <div>
                <div class="text-lg font-bold">#{{ $order->displayNumber() }} · {{ $order->table ? __('pos::pos.order.table').' '.$order->table->name : $order->type->label() }}</div>
                <div class="text-xs text-slate-400">{{ $order->status->label() }} · {{ $order->opened_at->format('H:i') }}</div>
            </div>
            <button type="button" wire:click="hold" class="h-11 rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.order.hold') }}</button>
        </div>

        @if ($error)<p class="mx-4 mb-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
        @if ($notice)<p class="mx-4 mb-2 rounded-xl bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif

        <ul class="min-h-0 flex-1 divide-y divide-slate-800 overflow-y-auto">
            @forelse ($order->items as $line)
                <li class="px-4 py-2 {{ $line->isVoided() ? 'opacity-40 line-through' : '' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-semibold">{{ $line->displayName() }}</span>
                                @if ($line->isPending())
                                    <span class="rounded-full bg-emerald-500/20 px-2 text-[10px] font-bold uppercase text-emerald-300">{{ __('pos::pos.order.new_badge') }}</span>
                                @elseif (! $line->isVoided())
                                    <span class="rounded-full bg-slate-700 px-2 text-[10px] font-bold uppercase text-slate-300">{{ __('pos::pos.order.sent_badge') }}</span>
                                @endif
                            </div>
                            @foreach ($line->modifiers as $modifier)
                                <div class="text-xs text-slate-400">+ {{ $modifier->displayName() }}@if ($modifier->unit_price->isPositive()) ({{ money($modifier->unit_price) }})@endif</div>
                            @endforeach
                            @if ($line->notes)<div class="text-xs italic text-amber-300">“{{ $line->notes }}”</div>@endif
                        </div>
                        <div class="text-right font-semibold">{{ money($line->line_subtotal) }}</div>
                    </div>
                    @if ($line->isPending())
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" wire:click="changeLineQuantity('{{ $line->id }}', -1)" class="h-11 w-12 rounded-xl bg-slate-800 text-xl font-bold hover:bg-slate-700" aria-label="Less">−</button>
                            <span class="w-8 text-center text-lg font-bold">{{ $line->quantity }}</span>
                            <button type="button" wire:click="changeLineQuantity('{{ $line->id }}', 1)" class="h-11 w-12 rounded-xl bg-slate-800 text-xl font-bold hover:bg-slate-700" aria-label="More">+</button>
                            <button type="button" wire:click="editNote('{{ $line->id }}')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.order.note') }}</button>
                            <button type="button" wire:click="removeLine('{{ $line->id }}')" class="ml-auto h-11 w-12 rounded-xl bg-rose-500/20 text-rose-300 hover:bg-rose-500/30" aria-label="Remove">✕</button>
                        </div>
                    @else
                        <div class="mt-1 text-xs text-slate-500">{{ $line->quantity }} × {{ money($line->unitTotal()) }}</div>
                    @endif
                </li>
            @empty
                <li class="p-6 text-center text-slate-500">{{ __('pos::pos.order.empty') }}</li>
            @endforelse
        </ul>

        <div class="border-t border-slate-800 px-4 py-3 text-sm">
            <div class="flex justify-between text-slate-400"><span>{{ __('pos::pos.order.subtotal') }}</span><span>{{ money($order->subtotal) }}</span></div>
            @if ($order->discount_total->isPositive())
                <div class="flex justify-between text-amber-300"><span>{{ __('pos::pos.order.discount') }}</span><span>− {{ money($order->discount_total) }}</span></div>
            @endif
            @foreach ($order->tax_lines ?? [] as $tax)
                <div class="flex justify-between text-slate-400"><span>{{ $tax['name'] }} {{ rtrim(rtrim(number_format($tax['rate_basis_points'] / 100, 2), '0'), '.') }}%</span><span>{{ money($tax['amount']) }}</span></div>
            @endforeach
            <div class="mt-1 flex justify-between text-2xl font-black"><span>{{ __('pos::pos.order.total') }}</span><span>{{ money($order->total) }}</span></div>
        </div>

        @php($pending = $order->items->where('status', \App\Modules\Orders\Enums\OrderItemStatus::Pending)->count())
        <div class="grid grid-cols-2 gap-2 p-3">
            <button type="button" wire:click="send" @disabled($pending === 0) class="h-16 rounded-2xl bg-sky-500 text-lg font-bold text-slate-950 hover:bg-sky-400 disabled:opacity-40">
                {{ __('pos::pos.order.send') }}@if ($pending) <span class="text-sm font-semibold">({{ __('pos::pos.order.pending_count', ['count' => $pending]) }})</span>@endif
            </button>
            <button type="button" wire:click="pay" @disabled($order->total->isZero()) class="h-16 rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">{{ __('pos::pos.order.pay') }} {{ money($order->total) }}</button>
            <button type="button" wire:click="cancelOrder" wire:confirm="{{ __('pos::pos.order.cancel_confirm') }}" class="col-span-2 h-11 rounded-xl text-sm font-semibold text-rose-300 hover:bg-rose-500/10">{{ __('pos::pos.order.cancel') }}</button>
        </div>
    </aside>

    {{-- Menu --}}
    <section class="flex min-w-0 flex-1 flex-col">
        <nav class="flex shrink-0 gap-2 overflow-x-auto border-b border-slate-800 p-2">
            @foreach ($this->catalog as $category)
                <button type="button" wire:click="selectCategory('{{ $category->id }}')" class="h-12 shrink-0 rounded-xl px-4 text-base font-semibold {{ $this->currentCategory?->id === $category->id ? 'text-slate-950' : 'bg-slate-800 text-slate-200 hover:bg-slate-700' }}" @if ($this->currentCategory?->id === $category->id) style="background: {{ $category->color ?? '#10b981' }}" @endif>
                    {{ $category->name }}
                </button>
            @endforeach
        </nav>
        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                @foreach ($this->currentCategory?->items ?? [] as $item)
                    <button type="button" wire:click="tapItem('{{ $item->id }}')" class="flex min-h-24 flex-col justify-between rounded-2xl bg-slate-900 p-3 text-left ring-1 ring-slate-800 hover:ring-emerald-500 active:bg-slate-800">
                        <span class="line-clamp-2 text-base font-semibold leading-tight">{{ $item->name }}</span>
                        <span class="mt-2 flex items-center justify-between">
                            <span class="text-emerald-400">{{ $item->variants->isNotEmpty() ? __('pos::pos.order.size').'…' : money($item->price) }}</span>
                            @if ($item->tags)<span class="text-[10px] uppercase text-slate-500">{{ implode(' · ', $item->tags) }}</span>@endif
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Item picker: sizes + modifiers --}}
    @if ($picker)
        @php($pickerItem = \App\Modules\Menu\Models\Item::with(['variants' => fn ($q) => $q->active(), 'modifierGroups.modifiers'])->find($picker['item_id']))
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/80 p-2 sm:items-center" wire:key="picker-{{ $picker['item_id'] }}">
            <div class="flex max-h-full w-full max-w-2xl flex-col rounded-3xl bg-slate-900 ring-1 ring-slate-700">
                <div class="flex items-center justify-between px-5 py-4">
                    <h2 class="text-xl font-bold">{{ $picker['name'] }}</h2>
                    <button type="button" wire:click="pickerClose" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5">
                    @foreach ($picker['errors'] as $pickerError)
                        <p class="rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $pickerError }}</p>
                    @endforeach

                    @if ($pickerItem->variants->isNotEmpty())
                        <div>
                            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.order.size') }}</h3>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($pickerItem->variants as $variant)
                                    <button type="button" wire:click="pickerVariant('{{ $variant->id }}')" class="flex h-14 items-center justify-between rounded-xl px-4 font-semibold {{ $picker['variant_id'] === $variant->id ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">
                                        <span>{{ $variant->name }}</span><span>{{ money($variant->price) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @foreach ($pickerItem->modifierGroups->where('is_active', true) as $group)
                        <div>
                            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $group->name }} <span class="font-normal normal-case text-slate-500">· {{ $group->rule() }}</span></h3>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                @foreach ($group->modifiers->where('is_active', true) as $modifier)
                                    @php($qty = $picker['selections'][$group->id][$modifier->id] ?? 0)
                                    <div class="flex h-14 items-stretch overflow-hidden rounded-xl {{ $qty ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800' }} {{ $modifier->is_sold_out ? 'opacity-40' : '' }}">
                                        <button type="button" wire:click="pickerToggle('{{ $group->id }}', '{{ $modifier->id }}')" @disabled($modifier->is_sold_out) class="flex flex-1 items-center justify-between px-3 text-left font-semibold">
                                            <span>{{ $modifier->name }}@if ($qty > 1) × {{ $qty }}@endif</span>
                                            <span class="text-sm">{{ $modifier->is_sold_out ? __('pos::pos.order.sold_out') : ($modifier->price->isPositive() ? '+'.money($modifier->price) : '') }}</span>
                                        </button>
                                        @if ($qty && $group->allow_quantity)
                                            <button type="button" wire:click="pickerDecrease('{{ $group->id }}', '{{ $modifier->id }}')" class="w-12 border-l border-slate-950/20 text-xl font-bold" aria-label="Less">−</button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div>
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.order.note') }}</h3>
                        <input type="text" wire:model="picker.notes" placeholder="{{ __('pos::pos.order.note_placeholder') }}" class="h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500 focus:ring-2 focus:ring-emerald-500">
                    </div>
                </div>
                <div class="flex items-center gap-3 p-4">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="pickerQuantity(-1)" class="h-14 w-14 rounded-xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="Less">−</button>
                        <span class="w-10 text-center text-2xl font-bold">{{ $picker['quantity'] }}</span>
                        <button type="button" wire:click="pickerQuantity(1)" class="h-14 w-14 rounded-xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="More">+</button>
                    </div>
                    <button type="button" wire:click="pickerAdd" class="h-14 flex-1 rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.add') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Line note editor --}}
    @if ($noteLineId)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <h2 class="mb-3 text-lg font-bold">{{ __('pos::pos.order.note') }}</h2>
                <input type="text" wire:model="noteText" wire:keydown.enter="saveNote" placeholder="{{ __('pos::pos.order.note_placeholder') }}" class="h-14 w-full rounded-xl border-0 bg-slate-800 px-4 text-lg text-slate-100 placeholder:text-slate-500 focus:ring-2 focus:ring-emerald-500" autofocus>
                <div class="mt-4 grid grid-cols-2 gap-3">
                    <button type="button" wire:click="closeNote" class="h-14 rounded-2xl bg-slate-800 font-semibold hover:bg-slate-700">{{ __('pos::pos.order.close') }}</button>
                    <button type="button" wire:click="saveNote" class="h-14 rounded-2xl bg-emerald-500 font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.save') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
