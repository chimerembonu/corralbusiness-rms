<div class="h-full overflow-y-auto p-3" wire:poll.15s>
    <h1 class="mb-3 text-xl font-bold">🛵 {{ __('pos::pos.delivery.rider_title') }} <span class="text-base font-normal text-slate-400">{{ auth()->user()->name }}</span></h1>
    @if ($error)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

    @if ($orders->isEmpty())
        <p class="rounded-2xl border border-slate-800 p-6 text-center text-slate-500">{{ __('pos::pos.delivery.none_for_rider') }}</p>
    @else
        <div class="space-y-3">
            @foreach ($orders as $order)
                @php($out = $order->delivery_status->value === 'out')
                <article wire:key="ride-{{ $order->id }}" class="rounded-2xl bg-slate-900 p-4 ring-2 {{ $out ? 'ring-sky-500' : 'ring-emerald-500' }}">
                    <div class="flex items-start justify-between">
                        <div class="text-lg font-black">#{{ $order->displayNumber() }}</div>
                        <span class="rounded-full px-2 py-1 text-xs font-bold uppercase {{ $out ? 'bg-sky-500/20 text-sky-300' : 'bg-emerald-500/20 text-emerald-300' }}">{{ $order->delivery_status->label() }}@if ($out && $order->out_at) · {{ $order->out_at->format('H:i') }}@endif</span>
                    </div>
                    <div class="mt-1 text-xl font-bold">{{ $order->customer_name ?: __('pos::pos.delivery.no_name') }}</div>
                    <div class="text-base text-slate-200">{{ $order->delivery_address }}</div>
                    @if ($order->delivery_notes)<div class="mt-1 text-sm italic text-amber-300">{{ $order->delivery_notes }}</div>@endif
                    <ul class="mt-2 text-sm text-slate-400">
                        @foreach ($order->items->where('status', '!=', 'voided') as $line)<li>{{ $line->quantity }} × {{ $line->displayName() }}</li>@endforeach
                    </ul>
                    <div class="mt-2 text-lg font-bold {{ $order->isPaid() ? 'text-emerald-300' : 'text-amber-300' }}">{{ $order->isPaid() ? __('pos::pos.delivery.paid') : __('pos::pos.delivery.to_pay', ['amount' => money($order->balanceDue())]) }}</div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <a href="tel:{{ $order->customer_phone }}" class="flex h-14 items-center justify-center rounded-2xl bg-slate-800 text-base font-bold hover:bg-slate-700">📞 {{ __('pos::pos.delivery.call') }}</a>
                        @if ($out)
                            <button type="button" wire:click="markDelivered('{{ $order->id }}')" class="h-14 rounded-2xl bg-emerald-500 text-base font-bold text-slate-950 hover:bg-emerald-400">✓ {{ __('pos::pos.delivery.delivered') }}</button>
                            <button type="button" wire:click="startProblem('{{ $order->id }}')" class="col-span-2 h-12 rounded-2xl bg-rose-500/20 text-sm font-bold text-rose-300 hover:bg-rose-500/30">{{ __('pos::pos.delivery.problem') }}</button>
                        @else
                            <button type="button" wire:click="markOut('{{ $order->id }}')" class="h-14 rounded-2xl bg-sky-500 text-base font-bold text-slate-950 hover:bg-sky-400">🛵 {{ __('pos::pos.delivery.out') }}</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($problemFor)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="problem">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-rose-500/40">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-rose-300">{{ __('pos::pos.delivery.problem_title') }}</h2>
                    <button type="button" wire:click="startProblem(null)" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <input type="text" wire:model="problemReason" placeholder="{{ __('pos::pos.delivery.problem_reason') }}" class="mt-3 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                <button type="button" wire:click="confirmProblem" class="mt-4 h-14 w-full rounded-2xl bg-rose-500 text-lg font-bold text-slate-950 hover:bg-rose-400">{{ __('pos::pos.order.save') }}</button>
            </div>
        </div>
    @endif
</div>
