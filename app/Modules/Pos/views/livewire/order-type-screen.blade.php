<div class="flex h-full flex-col gap-4 overflow-y-auto p-4">
    @if ($error)
        <p class="rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>
    @endif

    @if ($mode === 'home')
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <button type="button" wire:click="showTables" class="flex min-h-40 flex-col items-center justify-center rounded-3xl bg-emerald-500 p-6 text-slate-950 hover:bg-emerald-400">
                <span class="text-3xl font-black">{{ __('pos::pos.home.dine_in') }}</span>
                <span class="mt-1 text-base font-medium opacity-80">{{ __('pos::pos.home.dine_in_hint') }}</span>
            </button>
            <button type="button" wire:click="startTakeaway" class="flex min-h-40 flex-col items-center justify-center rounded-3xl bg-amber-400 p-6 text-slate-950 hover:bg-amber-300">
                <span class="text-3xl font-black">{{ __('pos::pos.home.takeaway') }}</span>
                <span class="mt-1 text-base font-medium opacity-80">{{ __('pos::pos.home.takeaway_hint') }}</span>
            </button>
            <div class="flex min-h-40 flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-700 p-6 text-slate-500">
                <span class="text-3xl font-black">{{ __('pos::pos.home.delivery') }}</span>
                <span class="mt-1 text-base">{{ __('pos::pos.home.delivery_hint') }}</span>
            </div>
        </div>

        <section>
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.home.open_orders') }}</h2>
            @if ($openOrders->isEmpty())
                <p class="rounded-2xl border border-slate-800 p-4 text-slate-500">{{ __('pos::pos.home.none_open') }}</p>
            @else
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
                    @foreach ($openOrders as $order)
                        <button type="button" wire:click="resume('{{ $order->id }}')" class="flex min-h-24 flex-col justify-between rounded-2xl bg-slate-900 p-3 text-left ring-1 ring-slate-800 hover:ring-emerald-500">
                            <span class="text-lg font-bold">#{{ $order->displayNumber() }} <span class="text-sm font-medium text-slate-400">{{ $order->table?->name ?? $order->type->label() }}</span></span>
                            <span class="text-sm text-slate-400">{{ $order->opened_at->format('H:i') }} · {{ $order->status->label() }}</span>
                            <span class="text-base font-semibold text-emerald-400">{{ money($order->total) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <div class="flex items-center gap-3">
            <button type="button" wire:click="showHome" class="h-12 rounded-xl bg-slate-800 px-4 font-semibold hover:bg-slate-700">← {{ __('pos::pos.nav.back') }}</button>
            <h2 class="text-xl font-bold">{{ __('pos::pos.home.choose_table') }}</h2>
        </div>
        @foreach ($tables as $section => $sectionTables)
            <section>
                @if ($section !== '')<h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $section }}</h3>@endif
                <div class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    @foreach ($sectionTables as $table)
                        @php($occupied = $table->currentOrder !== null)
                        <button type="button" wire:click="openTable('{{ $table->id }}')" class="flex min-h-28 flex-col items-center justify-center rounded-2xl p-3 {{ $occupied ? 'bg-amber-500/20 ring-2 ring-amber-400' : 'bg-slate-900 ring-1 ring-slate-800 hover:ring-emerald-500' }}">
                            <span class="text-2xl font-black">{{ $table->name }}</span>
                            <span class="text-xs text-slate-400">{{ __('pos::pos.home.seats', ['count' => $table->seats]) }}</span>
                            @if ($occupied)
                                <span class="mt-1 text-sm font-semibold text-amber-300">#{{ $table->currentOrder->displayNumber() }} · {{ money($table->currentOrder->total) }}</span>
                            @else
                                <span class="mt-1 text-sm font-semibold text-emerald-400">{{ __('pos::pos.home.free') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
