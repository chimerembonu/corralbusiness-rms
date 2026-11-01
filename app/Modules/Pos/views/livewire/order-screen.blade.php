@php($order = $this->order)
<div class="flex h-full">
    {{-- Order panel --}}
    <aside class="flex w-[38%] min-w-[320px] flex-col border-r border-slate-800 bg-slate-900">
        <div class="flex items-center justify-between px-4 py-3">
            <div>
                <div class="text-lg font-bold">#{{ $order->displayNumber() }} · {{ $order->table ? __('pos::pos.order.table').' '.$order->table->name : $order->type->label() }}</div>
                <div class="text-xs text-slate-400">{{ $order->status->label() }} · {{ $order->opened_at->format('H:i') }}@if ($order->table) · {{ __('pos::pos.home.guests', ['count' => $order->covers]) }}@endif</div>
                @if ($order->table && $order->table->mergedTables->isNotEmpty())
                    <div class="text-xs font-semibold text-sky-300">{{ __('pos::pos.order.table_sheet.joined_tables', ['tables' => $order->table->mergedTables->pluck('name')->implode(', ')]) }}</div>
                @endif
            </div>
            <div class="flex gap-2">
                @if ($order->table)
                    <button type="button" wire:click="openTableSheet" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.order.table_button') }}</button>
                @endif
                <button type="button" wire:click="openModal('actions')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.order.actions') }}</button>
                <button type="button" wire:click="hold" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.order.hold') }}</button>
            </div>
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
                            @if ($line->hasItemDiscount())<div class="text-xs text-amber-300">− {{ money($line->itemDiscount()) }} {{ $line->item_discount_reason }}</div>@endif
                            @if ((($line->course ?? 1) > 1 || $line->seat) && ! $line->isVoided())
                                <div class="mt-0.5 flex gap-1 text-[10px] font-bold uppercase">
                                    @if (($line->course ?? 1) > 1)<span class="rounded-full px-2 {{ $line->isSent() && ! $line->fired_at ? 'bg-amber-500/20 text-amber-300' : 'bg-sky-500/20 text-sky-300' }}">{{ __('orders::orders.course', ['number' => $line->course]) }}{{ $line->isSent() && ! $line->fired_at ? ' · '.__('pos::pos.order.held') : '' }}</span>@endif
                                    @if ($line->seat)<span class="rounded-full bg-slate-700 px-2 text-slate-300">{{ __('orders::orders.seat', ['number' => $line->seat]) }}</span>@endif
                                </div>
                            @endif
                        </div>
                        <div class="text-right font-semibold">{{ money($line->line_subtotal) }}</div>
                    </div>
                    @if ($line->isPending())
                        <div class="mt-2 flex items-center gap-2">
                            <button type="button" wire:click="changeLineQuantity('{{ $line->id }}', -1)" class="h-11 w-12 rounded-xl bg-slate-800 text-xl font-bold hover:bg-slate-700" aria-label="Less">−</button>
                            <span class="w-8 text-center text-lg font-bold">{{ $line->quantity }}</span>
                            <button type="button" wire:click="changeLineQuantity('{{ $line->id }}', 1)" class="h-11 w-12 rounded-xl bg-slate-800 text-xl font-bold hover:bg-slate-700" aria-label="More">+</button>
                            <button type="button" wire:click="editNote('{{ $line->id }}')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.order.note') }}</button>
                            <button type="button" wire:click="cycleCourse('{{ $line->id }}')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700" title="{{ __('pos::pos.order.course_hint') }}">{{ ($line->course ?? 1) > 1 ? 'C'.$line->course : __('pos::pos.order.course_button') }}</button>
                            @if ($order->table)<button type="button" wire:click="cycleSeat('{{ $line->id }}')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ $line->seat ? 'S'.$line->seat : __('pos::pos.order.seat_button') }}</button>@endif
                            <button type="button" wire:click="startDiscount('{{ $line->id }}')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">%</button>
                            <button type="button" wire:click="removeLine('{{ $line->id }}')" class="ml-auto h-11 w-12 rounded-xl bg-rose-500/20 text-rose-300 hover:bg-rose-500/30" aria-label="Remove">✕</button>
                        </div>
                    @elseif (! $line->isVoided())
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-xs text-slate-500">{{ $line->quantity }} × {{ money($line->unitTotal()) }}</span>
                            @if ($order->table)<button type="button" wire:click="cycleSeat('{{ $line->id }}')" class="ml-auto h-10 rounded-xl bg-slate-800 px-3 text-xs font-semibold hover:bg-slate-700">{{ $line->seat ? 'S'.$line->seat : __('pos::pos.order.seat_button') }}</button>@endif
                            <button type="button" wire:click="startDiscount('{{ $line->id }}')" class="{{ $order->table ? '' : 'ml-auto' }} h-10 rounded-xl bg-slate-800 px-3 text-xs font-semibold hover:bg-slate-700">%</button>
                            <button type="button" wire:click="startVoid('{{ $line->id }}')" class="h-10 rounded-xl bg-rose-500/20 px-3 text-xs font-semibold text-rose-300 hover:bg-rose-500/30">{{ __('pos::pos.order.void') }}</button>
                        </div>
                    @else
                        <div class="mt-1 text-xs text-rose-300/70">{{ __('pos::pos.order.voided', ['reason' => $line->void_reason]) }}</div>
                    @endif
                </li>
            @empty
                <li class="p-6 text-center text-slate-500">{{ __('pos::pos.order.empty') }}</li>
            @endforelse
        </ul>

        <div class="border-t border-slate-800 px-4 py-3 text-sm">
            <div class="flex justify-between text-slate-400"><span>{{ __('pos::pos.order.subtotal') }}</span><span>{{ money($order->subtotal) }}</span></div>
            @if ($order->discount_total->isPositive())
                <div class="flex justify-between text-amber-300"><span>{{ __('pos::pos.order.discount') }}@if ($order->discountLabel()) <span class="text-amber-300/70">{{ $order->discountLabel() }}</span>@endif</span><span>− {{ money($order->discount_total) }}</span></div>
            @endif
            @foreach ($order->tax_lines ?? [] as $tax)
                <div class="flex justify-between text-slate-400"><span>{{ $tax['name'] }} {{ rtrim(rtrim(number_format($tax['rate_basis_points'] / 100, 2), '0'), '.') }}%</span><span>{{ money($tax['amount']) }}</span></div>
            @endforeach
            @if ($order->service_charge->isPositive() || $order->service_charge_waived)
                <div class="flex justify-between text-slate-400"><span>{{ __('orders::orders.service_charge') }} {{ rtrim(rtrim(number_format($order->service_charge_bp / 100, 2), '0'), '.') }}%@if ($order->service_charge_waived) <span class="text-amber-300">({{ __('pos::pos.order.waived') }})</span>@endif</span><span>{{ money($order->service_charge) }}</span></div>
            @endif
            @if ($order->paid_total->isPositive())
                <div class="flex justify-between text-emerald-300"><span>{{ __('pos::pos.pay.paid_so_far') }}</span><span>{{ money($order->paid_total) }}</span></div>
            @endif
            <div class="mt-1 flex justify-between text-2xl font-black"><span>{{ __('pos::pos.order.total') }}</span><span>{{ money($order->total) }}</span></div>
        </div>

        @php($pending = $order->items->where('status', \App\Modules\Orders\Enums\OrderItemStatus::Pending)->count())
        <div class="grid grid-cols-2 gap-2 p-3">
            @foreach ($this->heldCourses as $course)
                <button type="button" wire:click="fireCourse({{ $course }})" class="col-span-2 h-12 rounded-xl bg-amber-400 text-base font-bold text-slate-950 hover:bg-amber-300">🔥 {{ __('pos::pos.order.fire_course', ['number' => $course]) }}</button>
            @endforeach
            <button type="button" wire:click="send" @disabled($pending === 0) class="h-16 rounded-2xl bg-sky-500 text-lg font-bold text-slate-950 hover:bg-sky-400 disabled:opacity-40">
                {{ __('pos::pos.order.send') }}@if ($pending) <span class="text-sm font-semibold">({{ __('pos::pos.order.pending_count', ['count' => $pending]) }})</span>@endif
            </button>
            <button type="button" wire:click="pay" @disabled($order->total->isZero()) class="h-16 rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">{{ __('pos::pos.order.pay') }} {{ money($order->balanceDue()) }}</button>
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
    {{-- Table sheet: move / join / guests --}}
    @if ($tableSheet && $order->table)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/80 p-2 sm:items-center" wire:key="table-sheet-{{ $tableSheet }}">
            <div class="flex max-h-full w-full max-w-4xl flex-col rounded-3xl bg-slate-900 ring-1 ring-slate-700">
                <div class="flex items-center justify-between px-5 py-4">
                    <div>
                        <h2 class="text-xl font-bold">{{ __('pos::pos.order.table_sheet.title', ['table' => $order->table->name]) }}</h2>
                        @if ($tableSheet === 'move')<p class="text-sm text-slate-400">{{ __('pos::pos.order.table_sheet.move_hint') }}</p>@endif
                        @if ($tableSheet === 'merge')<p class="text-sm text-slate-400">{{ __('pos::pos.order.table_sheet.merge_hint') }}</p>@endif
                    </div>
                    <div class="flex gap-2">
                        @if ($tableSheet !== 'menu')
                            <button type="button" wire:click="openTableSheet('menu')" class="h-11 rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">← {{ __('pos::pos.nav.back') }}</button>
                        @endif
                        <button type="button" wire:click="closeTableSheet" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                    </div>
                </div>

                @if ($error)<p class="mx-5 mb-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

                <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-5">
                    @if ($tableSheet === 'menu')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <button type="button" wire:click="openTableSheet('move')" class="flex min-h-28 flex-col items-center justify-center rounded-2xl bg-slate-800 p-4 text-lg font-bold hover:bg-slate-700">{{ __('pos::pos.order.table_sheet.move') }}</button>
                            <button type="button" wire:click="openTableSheet('merge')" class="flex min-h-28 flex-col items-center justify-center rounded-2xl bg-slate-800 p-4 text-lg font-bold hover:bg-slate-700">{{ __('pos::pos.order.table_sheet.merge') }}</button>
                            <button type="button" wire:click="openTableSheet('covers')" class="flex min-h-28 flex-col items-center justify-center rounded-2xl bg-slate-800 p-4 text-lg font-bold hover:bg-slate-700">{{ __('pos::pos.order.table_sheet.guests') }}<span class="text-sm font-medium text-slate-400">{{ __('pos::pos.home.guests', ['count' => $order->covers]) }}</span></button>
                        </div>
                    @elseif ($tableSheet === 'covers')
                        <div class="flex items-center justify-center gap-4 py-4">
                            <button type="button" wire:click="adjustCovers(-1)" class="h-14 w-14 rounded-2xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="Fewer">−</button>
                            <span class="w-20 text-center text-5xl font-black" data-test="covers">{{ $covers }}</span>
                            <button type="button" wire:click="adjustCovers(1)" class="h-14 w-14 rounded-2xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="More">+</button>
                        </div>
                        <button type="button" wire:click="saveCovers" class="h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.save') }}</button>
                    @else
                        @php($plan = $this->floorPlan)
                        @php($currentGroup = $plan->first(fn (array $g) => ($g['section']?->id ?? '') === ($sheetSectionId ?? '')) ?? $plan->first())
                        @if ($plan->count() > 1)
                            <nav class="mb-3 flex gap-2 overflow-x-auto">
                                @foreach ($plan as $group)
                                    @php($id = $group['section']?->id ?? '')
                                    <button type="button" wire:click="selectSheetSection('{{ $id }}')" class="h-11 shrink-0 rounded-xl px-4 text-sm font-semibold {{ ($currentGroup['section']?->id ?? '') === $id ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 text-slate-200 hover:bg-slate-700' }}">{{ $group['section']?->name ?? __('orders::orders.no_section') }}</button>
                                @endforeach
                            </nav>
                        @endif
                        @if ($currentGroup)
                            @php($blocked = $currentGroup['tables']->filter(fn ($t) => $t->id === $order->table_id || $t->isMerged() || ($tableSheet === 'move' && $t->isOccupied()))->pluck('id')->all())
                            @include('pos::partials.floor-plan', ['tables' => $currentGroup['tables'], 'onTap' => $tableSheet === 'move' ? 'moveTo' : 'mergeWith', 'highlight' => $order->table_id, 'disabled' => $blocked])
                        @endif
                    @endif
                </div>
            </div>
        </div>
    @endif
    {{-- Actions sheet --}}
    @if ($modal === 'actions')
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/80 p-2 sm:items-center" wire:key="modal-actions">
            <div class="w-full max-w-2xl rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ __('pos::pos.order.actions') }} · #{{ $order->displayNumber() }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <button type="button" wire:click="startDiscount" class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-slate-800 p-3 text-base font-bold hover:bg-slate-700">{{ __('pos::pos.order.discount') }}@if ($order->hasDiscount())<span class="text-xs font-medium text-amber-300">{{ $order->discountLabel() }}</span>@endif</button>
                    <button type="button" wire:click="openModal('promo')" class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-slate-800 p-3 text-base font-bold hover:bg-slate-700">{{ __('pos::pos.order.promo') }}</button>
                    <button type="button" wire:click="openModal('note')" class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-slate-800 p-3 text-base font-bold hover:bg-slate-700">{{ __('pos::pos.order.order_note') }}@if ($order->notes)<span class="line-clamp-1 text-xs font-medium text-amber-300">{{ $order->notes }}</span>@endif</button>
                    @if ($order->service_charge_bp > 0)
                        <button type="button" wire:click="toggleServiceCharge" class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-slate-800 p-3 text-base font-bold hover:bg-slate-700">{{ $order->service_charge_waived ? __('pos::pos.order.restore_service') : __('pos::pos.order.waive_service') }}<span class="text-xs font-medium text-slate-400">{{ __('pos::pos.order.needs_manager') }}</span></button>
                    @endif
                    @if ($order->activeItems()->count() > 1)
                        <button type="button" wire:click="openModal('split')" class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-slate-800 p-3 text-base font-bold hover:bg-slate-700">{{ __('pos::pos.order.split') }}</button>
                    @endif
                    <a href="{{ route('pos.receipt', $order) }}" class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-slate-800 p-3 text-base font-bold hover:bg-slate-700">{{ __('pos::pos.order.print_bill') }}</a>
                    <button type="button" wire:click="cancelOrder" @if ($order->status !== \App\Modules\Orders\Enums\OrderStatus::Sent) wire:confirm="{{ __('pos::pos.order.cancel_confirm') }}" @endif class="flex min-h-24 flex-col items-center justify-center rounded-2xl bg-rose-500/15 p-3 text-base font-bold text-rose-300 hover:bg-rose-500/25">{{ __('pos::pos.order.cancel') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Void: reason, then manager PIN --}}
    @if ($modal === 'void')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="modal-void">
            <div class="w-full max-w-lg rounded-3xl bg-slate-900 p-5 ring-1 ring-rose-500/40">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-rose-300">{{ $void['line_id'] ? __('pos::pos.order.void_line_title', ['item' => $this->line($void['line_id'])->displayName()]) : __('pos::pos.order.void_order_title') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <p class="mt-1 text-sm text-slate-400">{{ __('pos::pos.order.void_reason_hint') }}</p>
                @if ($error)<p class="mt-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <div class="mt-3 grid grid-cols-2 gap-2">
                    @foreach ($this->settings->voidReasons($order->branch_id) as $reason)
                        <button type="button" wire:click="chooseVoidReason('{{ $reason }}')" class="h-14 rounded-xl px-3 text-sm font-semibold {{ $void['reason'] === $reason ? 'bg-rose-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $reason }}</button>
                    @endforeach
                </div>
                <input type="text" wire:model.live="void.reason" placeholder="{{ __('pos::pos.order.other_reason') }}" class="mt-3 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                <button type="button" wire:click="confirmVoid" class="mt-4 h-14 w-full rounded-2xl bg-rose-500 text-lg font-bold text-slate-950 hover:bg-rose-400">{{ __('pos::pos.order.void_continue') }}</button>
            </div>
        </div>
    @endif

    {{-- Discount --}}
    @if ($modal === 'discount')
        @php($target = $discount['line_id'] ? $this->line($discount['line_id']) : null)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="modal-discount">
            <div class="w-full max-w-lg rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ $target ? __('pos::pos.order.discount_line_title', ['item' => $target->displayName()]) : __('pos::pos.order.discount_order_title') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                @if ($error)<p class="mt-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" wire:click="setDiscountType('percent')" class="h-12 rounded-xl text-base font-bold {{ $discount['type'] === 'percent' ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ __('pos::pos.order.percent_off') }}</button>
                    <button type="button" wire:click="setDiscountType('fixed')" class="h-12 rounded-xl text-base font-bold {{ $discount['type'] === 'fixed' ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ __('pos::pos.order.amount_off') }}</button>
                </div>
                <div class="mt-3 grid grid-cols-4 gap-2">
                    @foreach ($discount['type'] === 'percent' ? ['5', '10', '15', '20', '25', '50', '100'] : ['5', '10', '20', '50'] as $quick)
                        <button type="button" wire:click="setDiscountValue('{{ $quick }}')" class="h-12 rounded-xl text-base font-bold {{ $discount['value'] === $quick ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $quick }}{{ $discount['type'] === 'percent' ? '%' : '' }}</button>
                    @endforeach
                </div>
                <div class="mt-3 flex items-center gap-2">
                    <input type="text" inputmode="decimal" wire:model.live="discount.value" placeholder="{{ $discount['type'] === 'percent' ? '0' : '0.00' }}" class="h-14 flex-1 rounded-xl border-0 bg-slate-800 px-4 text-2xl font-bold text-slate-100 placeholder:text-slate-500">
                    <span class="text-xl font-bold text-slate-400">{{ $discount['type'] === 'percent' ? '%' : config('rms.currency_symbol') }}</span>
                </div>
                <p class="mt-3 text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.order.reason') }}</p>
                <div class="mt-1 grid grid-cols-2 gap-2">
                    @foreach ($this->settings->discountReasons($order->branch_id) as $reason)
                        <button type="button" wire:click="chooseDiscountReason('{{ $reason }}')" class="h-12 rounded-xl px-3 text-sm font-semibold {{ $discount['reason'] === $reason ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $reason }}</button>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-500">{{ __('pos::pos.order.discount_threshold_hint', ['threshold' => rtrim(rtrim(number_format($this->settings->discountThresholdBp($order->branch_id) / 100, 2), '0'), '.')]) }}</p>
                <div class="mt-4 grid grid-cols-3 gap-2">
                    <button type="button" wire:click="removeDiscount({{ $target ? "'".$target->id."'" : 'null' }})" class="h-14 rounded-2xl bg-slate-800 font-semibold hover:bg-slate-700">{{ __('pos::pos.order.remove_discount') }}</button>
                    <button type="button" wire:click="applyDiscount" class="col-span-2 h-14 rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.apply') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Promo code --}}
    @if ($modal === 'promo')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="modal-promo">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ __('pos::pos.order.promo') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                @if ($order->promo_code)<p class="mt-2 text-sm text-emerald-300">{{ __('pos::pos.order.promo_current', ['code' => $order->promo_code]) }}</p>@endif
                @if ($error)<p class="mt-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <input type="text" wire:model="promoCode" wire:keydown.enter="applyPromoCode" autocapitalize="characters" placeholder="{{ __('pos::pos.order.promo_placeholder') }}" class="mt-3 h-14 w-full rounded-xl border-0 bg-slate-800 px-4 text-2xl font-bold uppercase tracking-widest text-slate-100 placeholder:text-slate-500 placeholder:tracking-normal">
                <div class="mt-4 grid grid-cols-3 gap-2">
                    @if ($order->promo_code)<button type="button" wire:click="removeDiscount" class="h-14 rounded-2xl bg-slate-800 font-semibold hover:bg-slate-700">{{ __('pos::pos.order.remove_discount') }}</button>@endif
                    <button type="button" wire:click="applyPromoCode" class="{{ $order->promo_code ? 'col-span-2' : 'col-span-3' }} h-14 rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.apply') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Order note --}}
    @if ($modal === 'note')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="modal-note">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ __('pos::pos.order.order_note') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <textarea wire:model="orderNote" rows="3" placeholder="{{ __('pos::pos.order.order_note_placeholder') }}" class="mt-3 w-full rounded-xl border-0 bg-slate-800 px-4 py-3 text-base text-slate-100 placeholder:text-slate-500"></textarea>
                <button type="button" wire:click="saveOrderNote" class="mt-4 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.save') }}</button>
            </div>
        </div>
    @endif

    {{-- Hold with a name (takeaway) --}}
    @if ($modal === 'hold')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="modal-hold">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ __('pos::pos.order.hold_title') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <p class="mt-1 text-sm text-slate-400">{{ __('pos::pos.order.hold_hint') }}</p>
                <input type="text" wire:model="holdName" wire:keydown.enter="confirmHold" placeholder="{{ __('pos::pos.order.hold_placeholder') }}" class="mt-3 h-14 w-full rounded-xl border-0 bg-slate-800 px-4 text-xl font-semibold text-slate-100 placeholder:text-slate-500">
                <button type="button" wire:click="confirmHold" class="mt-4 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.hold') }}</button>
            </div>
        </div>
    @endif

    {{-- Split bill --}}
    @if ($modal === 'split')
        @php($active = $order->items->where('status', '!=', \App\Modules\Orders\Enums\OrderItemStatus::Voided))
        @php($seatNumbers = $active->pluck('seat')->filter()->unique()->sort()->values())
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/80 p-2 sm:items-center" wire:key="modal-split">
            <div class="flex max-h-full w-full max-w-2xl flex-col rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ __('pos::pos.order.split') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                @if ($error)<p class="mt-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <div class="mt-3 grid grid-cols-3 gap-2">
                    @foreach (['items', 'seats', 'even'] as $mode)
                        <button type="button" wire:click="setSplitMode('{{ $mode }}')" class="h-12 rounded-xl text-sm font-bold {{ $split['mode'] === $mode ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ __('pos::pos.order.split_'.$mode) }}</button>
                    @endforeach
                </div>
                <div class="mt-3 min-h-0 flex-1 overflow-y-auto">
                    @if ($split['mode'] === 'items')
                        <p class="text-sm text-slate-400">{{ __('pos::pos.order.split_items_hint') }}</p>
                        <ul class="mt-2 divide-y divide-slate-800">
                            @foreach ($active as $line)
                                <li>
                                    <button type="button" wire:click="toggleSplitLine('{{ $line->id }}')" class="flex min-h-12 w-full items-center justify-between gap-3 px-2 py-2 text-left {{ in_array($line->id, $split['lines'], true) ? 'bg-emerald-500/15 text-emerald-200' : '' }}">
                                        <span><span class="mr-2 inline-block h-5 w-5 rounded border-2 {{ in_array($line->id, $split['lines'], true) ? 'border-emerald-400 bg-emerald-400' : 'border-slate-600' }}"></span>{{ $line->quantity }} × {{ $line->displayName() }}@if ($line->seat) <span class="text-xs text-slate-500">S{{ $line->seat }}</span>@endif</span>
                                        <span class="font-semibold">{{ money($line->line_total) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <button type="button" wire:click="confirmSplit" @disabled(count($split['lines']) === 0) class="mt-4 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">{{ __('pos::pos.order.split_go', ['count' => count($split['lines'])]) }}</button>
                    @elseif ($split['mode'] === 'seats')
                        @if ($seatNumbers->isEmpty())
                            <p class="rounded-xl border border-slate-800 p-4 text-sm text-slate-400">{{ __('pos::pos.order.split_no_seats') }}</p>
                        @else
                            <p class="text-sm text-slate-400">{{ __('pos::pos.order.split_seats_hint') }}</p>
                            <div class="mt-2 grid grid-cols-4 gap-2">
                                @foreach ($seatNumbers as $seat)
                                    <button type="button" wire:click="toggleSplitSeat({{ $seat }})" class="h-14 rounded-xl text-lg font-bold {{ in_array($seat, $split['seats'], true) ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ __('orders::orders.seat', ['number' => $seat]) }}</button>
                                @endforeach
                            </div>
                            <button type="button" wire:click="confirmSplit" @disabled(count($split['seats']) === 0) class="mt-4 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">{{ __('pos::pos.order.split_seats_go') }}</button>
                        @endif
                    @else
                        <p class="text-sm text-slate-400">{{ __('pos::pos.order.split_even_hint') }}</p>
                        <div class="mt-2 grid grid-cols-4 gap-2">
                            @foreach ([2, 3, 4, 5, 6, 8] as $ways)
                                <button type="button" wire:click="setSplitWays({{ $ways }})" class="h-14 rounded-xl text-lg font-bold {{ $split['ways'] === $ways ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $ways }}</button>
                            @endforeach
                        </div>
                        <p class="mt-3 text-center text-lg">{{ __('pos::pos.order.split_each', ['amount' => money($order->balanceDue()->split($split['ways'])[0])]) }}</p>
                        <button type="button" wire:click="splitEvenly" class="mt-4 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.order.split_even_go') }}</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @include('pos::partials.manager-pin')
</div>
