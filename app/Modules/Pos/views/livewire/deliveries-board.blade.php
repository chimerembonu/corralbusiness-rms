<div class="h-full overflow-y-auto p-4" wire:poll.10s>
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">🛵 {{ __('pos::pos.delivery.board_title') }}</h1>
        <a href="{{ route('pos.home') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">← {{ __('pos::pos.nav.new_order') }}</a>
    </div>
    @if ($notice)<p class="mb-3 rounded-xl bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif
    @if ($error)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

    @if ($orders->isEmpty())
        <p class="rounded-2xl border border-slate-800 p-6 text-center text-slate-500">{{ __('pos::pos.delivery.none') }}</p>
    @else
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($orders as $order)
                @php($status = $order->delivery_status)
                @php($ring = match ($status?->value) { 'out' => 'ring-sky-500', 'assigned' => 'ring-emerald-500', 'failed' => 'ring-rose-500', 'delivered' => 'ring-slate-700 opacity-60', default => 'ring-amber-400' })
                <article wire:key="delivery-{{ $order->id }}" class="flex flex-col rounded-2xl bg-slate-900 p-4 ring-2 {{ $ring }}">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="text-lg font-black">#{{ $order->displayNumber() }} <span class="text-sm font-medium text-slate-400">{{ $order->opened_at->format('H:i') }}</span></div>
                            <div class="font-semibold">{{ $order->customer_name ?: __('pos::pos.delivery.no_name') }} · <a href="tel:{{ $order->customer_phone }}" class="text-sky-300">{{ $order->customer_phone }}</a></div>
                            <div class="text-sm text-slate-300">{{ $order->delivery_address }}</div>
                            @if ($order->delivery_notes)<div class="text-xs italic text-amber-300">{{ $order->delivery_notes }}</div>@endif
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-1 text-xs font-bold uppercase {{ match ($status?->value) { 'out' => 'bg-sky-500/20 text-sky-300', 'assigned' => 'bg-emerald-500/20 text-emerald-300', 'failed' => 'bg-rose-500/20 text-rose-300', 'delivered' => 'bg-slate-700 text-slate-300', default => 'bg-amber-500/20 text-amber-300' } }}">{{ $status?->label() }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-slate-400">{{ __('pos::pos.delivery.items', ['count' => $order->items->where('status', '!=', 'voided')->sum('quantity')]) }} · {{ $order->status->label() }}</span>
                        <span class="font-semibold {{ $order->isPaid() ? 'text-emerald-300' : 'text-amber-300' }}">{{ $order->isPaid() ? __('pos::pos.delivery.paid') : __('pos::pos.delivery.to_pay', ['amount' => money($order->balanceDue())]) }}</span>
                    </div>
                    @if ($order->delivery_failure)<div class="mt-1 text-xs text-rose-300">{{ $order->delivery_failure }}</div>@endif
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($status?->isOpen())
                            <span class="text-sm text-slate-400">{{ __('pos::pos.delivery.rider') }}: <strong class="text-slate-100">{{ $order->rider?->name ?? '—' }}</strong></span>
                            <button type="button" wire:click="startAssign('{{ $assigning === $order->id ? '' : $order->id }}')" class="h-11 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.delivery.assign') }}</button>
                            @if ($status->value === 'assigned')<button type="button" wire:click="markOut('{{ $order->id }}')" class="h-11 rounded-xl bg-sky-500 px-3 text-sm font-bold text-slate-950 hover:bg-sky-400">{{ __('pos::pos.delivery.out') }}</button>@endif
                            @if ($status->value === 'out')<button type="button" wire:click="markDelivered('{{ $order->id }}')" class="h-11 rounded-xl bg-emerald-500 px-3 text-sm font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.delivery.delivered') }}</button>@endif
                        @elseif ($status?->value === 'failed')
                            <button type="button" wire:click="reopen('{{ $order->id }}')" class="h-11 rounded-xl bg-amber-400 px-3 text-sm font-bold text-slate-950 hover:bg-amber-300">{{ __('pos::pos.delivery.reopen') }}</button>
                        @endif
                        <a href="{{ $order->isActive() ? route('pos.order', $order) : route('pos.receipt', $order) }}" class="ml-auto flex h-11 items-center rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.today.view') }}</a>
                    </div>
                    @if ($assigning === $order->id)
                        <div class="mt-2 grid grid-cols-2 gap-2 rounded-xl bg-slate-800 p-2">
                            @forelse ($riders as $rider)
                                <button type="button" wire:click="assign('{{ $order->id }}', '{{ $rider->id }}')" class="h-12 rounded-xl px-3 text-sm font-bold {{ $order->rider_id === $rider->id ? 'bg-emerald-500 text-slate-950' : 'bg-slate-700 hover:bg-slate-600' }}">{{ $rider->name }}</button>
                            @empty
                                <p class="col-span-2 text-sm text-slate-400">{{ __('pos::pos.delivery.no_riders') }}</p>
                            @endforelse
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
