<div class="flex h-full flex-col gap-4 overflow-y-auto p-4" @if ($mode === 'tables') wire:poll.8s @endif>
    @if ($error)
        <p class="rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>
    @endif
    @if (app(\App\Support\Licence\LicenceService::class)->isReadOnly())
        <p class="rounded-xl bg-amber-500/15 px-4 py-3 text-base font-semibold text-amber-200" role="status">{{ __('settings::settings.licence.till_paused') }}</p>
    @endif

    @if ($mode === 'home')
        @can(\App\Modules\Staff\Enums\Permission::OrdersCreate->value)
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <button type="button" wire:click="showTables" class="flex min-h-40 flex-col items-center justify-center rounded-3xl bg-emerald-500 p-6 text-slate-950 hover:bg-emerald-400">
                    <span class="text-3xl font-black">{{ __('pos::pos.home.dine_in') }}</span>
                    <span class="mt-1 text-base font-medium opacity-80">{{ __('pos::pos.home.dine_in_hint') }}</span>
                </button>
                <button type="button" wire:click="startTakeaway" class="flex min-h-40 flex-col items-center justify-center rounded-3xl bg-amber-400 p-6 text-slate-950 hover:bg-amber-300">
                    <span class="text-3xl font-black">{{ __('pos::pos.home.takeaway') }}</span>
                    <span class="mt-1 text-base font-medium opacity-80">{{ __('pos::pos.home.takeaway_hint') }}</span>
                </button>
                <button type="button" wire:click="startDelivery" class="flex min-h-40 flex-col items-center justify-center rounded-3xl bg-sky-500 p-6 text-slate-950 hover:bg-sky-400">
                    <span class="text-3xl font-black">{{ __('pos::pos.home.delivery') }}</span>
                    <span class="mt-1 text-base font-medium opacity-80">{{ __('pos::pos.home.delivery_hint') }}</span>
                </button>
            </div>

            <section>
                <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.home.open_orders') }}</h2>
                @if ($openOrders->isEmpty())
                    <p class="rounded-2xl border border-slate-800 p-4 text-slate-500">{{ __('pos::pos.home.none_open') }}</p>
                @else
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
                        @foreach ($openOrders as $order)
                            <button type="button" wire:click="resume('{{ $order->id }}')" class="flex min-h-24 flex-col justify-between rounded-2xl bg-slate-900 p-3 text-left ring-1 ring-slate-800 hover:ring-emerald-500">
                                <span class="text-lg font-bold">#{{ $order->displayNumber() }} <span class="text-sm font-medium text-slate-400">{{ $order->table?->name ?? $order->type->label() }}</span>@if ($order->isDelivery() && $order->delivery_status) <span class="rounded-full bg-sky-500/20 px-2 text-[10px] font-bold uppercase text-sky-300">{{ $order->delivery_status->label() }}</span>@endif</span>
                                @if ($order->customer_name)<span class="truncate text-sm font-semibold text-amber-300">{{ $order->customer_name }}</span>@endif
                                <span class="text-sm text-slate-400">{{ $order->opened_at->format('H:i') }} · {{ $order->isHeld() ? __('pos::pos.home.held') : $order->status->label() }}</span>
                                <span class="text-base font-semibold text-emerald-400">{{ money($order->total) }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>
        @else
            {{-- Stock counters and other roles that do not take orders: point them at their own buttons above. --}}
            <p class="rounded-2xl border border-slate-800 p-6 text-lg text-slate-300">{{ __('pos::pos.home.no_orders_for_role') }}</p>
        @endcan
    @else
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="showHome" class="h-12 rounded-xl bg-slate-800 px-4 font-semibold hover:bg-slate-700">← {{ __('pos::pos.nav.back') }}</button>
            <h2 class="text-xl font-bold">{{ __('pos::pos.home.choose_table') }}</h2>
            <div class="ml-auto flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-400">
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded-full bg-slate-700"></span>{{ __('orders::orders.table_status.free') }}</span>
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded-full bg-emerald-500"></span>{{ __('orders::orders.table_status.seated') }}</span>
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded-full bg-amber-400"></span>{{ __('orders::orders.table_status.ordered') }}</span>
                <span class="flex items-center gap-1"><span class="h-3 w-3 rounded-full bg-sky-500"></span>{{ __('orders::orders.table_status.merged') }}</span>
            </div>
        </div>

        @if ($plan->count() > 1)
            <nav class="flex gap-2 overflow-x-auto">
                @foreach ($plan as $group)
                    @php($id = $group['section']?->id ?? '')
                    <button type="button" wire:click="selectSection('{{ $id }}')" class="h-12 shrink-0 rounded-xl px-5 text-base font-semibold {{ ($current['section']?->id ?? '') === $id ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 text-slate-200 hover:bg-slate-700' }}">
                        {{ $group['section']?->name ?? __('orders::orders.no_section') }}
                    </button>
                @endforeach
            </nav>
        @endif

        @if (! $current || $current['tables']->isEmpty())
            <p class="rounded-2xl border border-slate-800 p-6 text-center text-slate-500">{{ __('pos::pos.home.no_tables') }}</p>
        @else
            @include('pos::partials.floor-plan', ['tables' => $current['tables'], 'onTap' => 'tapTable'])
        @endif
    @endif

    {{-- Delivery details --}}
    @if ($delivery !== null)
        @include('pos::partials.delivery-form', ['submit' => 'openDelivery', 'close' => 'closeDelivery', 'title' => __('pos::pos.delivery.new_title'), 'button' => __('pos::pos.delivery.start')])
    @endif

    {{-- Guest count prompt --}}
    @if ($coversFor && $coversTable)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold">{{ __('pos::pos.home.guests_title', ['table' => $coversTable->name]) }}</h2>
                    <button type="button" wire:click="closeCovers" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <div class="mt-4 flex items-center justify-center gap-4">
                    <button type="button" wire:click="adjustCovers(-1)" class="h-14 w-14 rounded-2xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="Fewer">−</button>
                    <span class="w-20 text-center text-5xl font-black" data-test="covers">{{ $covers }}</span>
                    <button type="button" wire:click="adjustCovers(1)" class="h-14 w-14 rounded-2xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="More">+</button>
                </div>
                <div class="mt-4 grid grid-cols-4 gap-2">
                    @foreach ([1, 2, 3, 4, 5, 6, 8, 10] as $n)
                        <button type="button" wire:click="setCovers({{ $n }})" class="h-12 rounded-xl text-lg font-bold {{ $covers === $n ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $n }}</button>
                    @endforeach
                </div>
                <button type="button" wire:click="openTable" class="mt-5 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.home.start_order') }}</button>
            </div>
        </div>
    @endif
    @can(\App\Modules\Staff\Enums\Permission::TrainingMode->value)
        <div class="flex justify-end">
            <button type="button" wire:click="toggleTraining" class="h-11 rounded-xl px-4 text-sm font-semibold {{ app(\App\Modules\Pos\Services\TrainingMode::class)->isOn() ? 'bg-amber-400 text-slate-950 hover:bg-amber-300' : 'bg-slate-800 text-slate-400 hover:bg-slate-700' }}">{{ app(\App\Modules\Pos\Services\TrainingMode::class)->isOn() ? __('pos::pos.training.turn_off') : __('pos::pos.training.turn_on') }}</button>
        </div>
    @endcan
    @include('pos::partials.manager-pin')
</div>
