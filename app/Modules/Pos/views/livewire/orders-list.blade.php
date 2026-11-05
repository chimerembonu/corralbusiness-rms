<div class="flex h-full">
    <section class="flex min-w-0 flex-1 flex-col">
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-800 p-3">
            <h1 class="text-xl font-bold">{{ __('pos::pos.orders.title') }}</h1>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('pos::pos.orders.search') }}" class="h-12 flex-1 min-w-48 rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
            <select wire:model.live="status" class="h-12 rounded-xl border-0 bg-slate-800 px-3 text-base text-slate-100">
                <option value="">{{ __('pos::pos.orders.all') }}</option>
                @foreach (\App\Modules\Orders\Enums\OrderStatus::cases() as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        @if ($notice)<p class="mx-3 mt-3 rounded-xl bg-emerald-500/15 px-4 py-2 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif
        @if ($error && ! $refund)<p class="mx-3 mt-3 rounded-xl bg-rose-500/15 px-4 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
        <div class="min-h-0 flex-1 overflow-y-auto">
            @if ($orders->isEmpty())
                <p class="p-6 text-center text-slate-500">{{ __('pos::pos.orders.none') }}</p>
            @else
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-slate-950 text-left text-xs uppercase text-slate-500"><tr><th class="px-3 py-2">{{ __('pos::pos.today.number') }}</th><th class="px-2 py-2">{{ __('pos::pos.today.time') }}</th><th class="px-2 py-2">{{ __('pos::pos.orders.who') }}</th><th class="px-2 py-2">{{ __('pos::pos.today.status') }}</th><th class="px-2 py-2 text-right">{{ __('pos::pos.today.total') }}</th></tr></thead>
                    <tbody class="divide-y divide-slate-800">
                        @foreach ($orders as $order)
                            <tr wire:click="open('{{ $order->id }}')" class="cursor-pointer {{ $openId === $order->id ? 'bg-slate-800/60' : 'hover:bg-slate-900' }}">
                                <td class="px-3 py-3 font-semibold">#{{ $order->displayNumber() }}</td>
                                <td class="px-2 py-3 text-slate-400">{{ $order->opened_at->format('d/m H:i') }}</td>
                                <td class="px-2 py-3">{{ $order->table?->name ?? $order->type->label() }}@if ($order->customer_name) <span class="text-slate-400">· {{ $order->customer_name }}</span>@endif @if ($order->is_training)<span class="rounded bg-amber-400 px-1.5 py-0.5 text-[10px] font-black uppercase text-slate-950">{{ __('pos::pos.training.badge') }}</span>@endif</td>
                                <td class="px-2 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-bold {{ match ($order->status->value) { 'paid' => 'bg-emerald-500/20 text-emerald-300', 'void', 'refunded' => 'bg-rose-500/20 text-rose-300', 'merged' => 'bg-sky-500/20 text-sky-300', default => 'bg-amber-500/20 text-amber-300' } }}">{{ $order->status->label() }}</span></td>
                                <td class="px-2 py-3 text-right font-semibold">{{ money($order->total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    @if ($openOrder)
        <aside class="flex w-[40%] min-w-[340px] flex-col border-l border-slate-800 bg-slate-900">
            <div class="flex items-center justify-between px-4 py-3">
                <div>
                    <div class="text-lg font-bold">#{{ $openOrder->displayNumber() }} · {{ $openOrder->table?->name ?? $openOrder->type->label() }}</div>
                    <div class="text-xs text-slate-400">{{ $openOrder->status->label() }} · {{ $openOrder->opened_at->format('d/m/Y H:i') }}@if ($openOrder->customer_name) · {{ $openOrder->customer_name }}@endif</div>
                </div>
                <button type="button" wire:click="open(null)" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
            </div>
            <ul class="min-h-0 flex-1 divide-y divide-slate-800 overflow-y-auto px-4 text-sm">
                @foreach ($openOrder->items as $line)
                    <li class="flex justify-between py-2 {{ $line->isVoided() ? 'line-through opacity-40' : '' }}"><span>{{ $line->quantity }} × {{ $line->displayName() }}</span><span>{{ money($line->line_subtotal) }}</span></li>
                @endforeach
            </ul>
            <div class="border-t border-slate-800 px-4 py-3 text-sm">
                <div class="flex justify-between text-lg font-black"><span>{{ __('pos::pos.order.total') }}</span><span>{{ money($openOrder->total) }}</span></div>
                @foreach ($openOrder->payments as $payment)
                    <div class="mt-1 flex items-center justify-between gap-2 {{ $payment->isRefunded() ? 'text-rose-300/70 line-through' : 'text-emerald-300' }}">
                        <span>{{ $payment->methodLabel() }}@if ($payment->reference) <span class="opacity-60">{{ $payment->reference }}</span>@endif @if ($payment->tip->isPositive())<span class="opacity-60">+ {{ __('orders::orders.tip') }} {{ money($payment->tip, false) }}</span>@endif</span>
                        <span class="flex items-center gap-2">{{ money($payment->amount) }}
                            @if ($canRefund && ! $payment->isRefunded())
                                <button type="button" wire:click="startRefund('{{ $payment->id }}')" class="h-9 rounded-lg bg-rose-500/20 px-2 text-xs font-bold text-rose-300 hover:bg-rose-500/30">{{ __('pos::pos.orders.refund') }}</button>
                            @endif
                        </span>
                    </div>
                    @if ($payment->isRefunded())<div class="text-xs text-rose-300/70">{{ __('pos::pos.orders.refund_note', ['reason' => $payment->refund_reason, 'time' => $payment->refunded_at?->format('H:i')]) }}</div>@endif
                    @if ($providerRefund = $providerRefunds[$payment->id] ?? null)
                        {{-- E2.4: the money going back through the provider, or to be given back by hand --}}
                        <div class="mt-1 rounded-lg px-3 py-2 text-xs {{ $providerRefund->status->needsHand() ? 'bg-amber-500/15 text-amber-200' : ($providerRefund->status->isFinal() ? 'bg-emerald-500/15 text-emerald-200' : 'bg-sky-500/15 text-sky-200') }}" wire:key="provider-refund-{{ $providerRefund->id }}">
                            <span class="font-bold">{{ $providerRefund->status->label() }} · {{ money($providerRefund->amount) }}</span>
                            <span class="block">{{ $providerRefund->message }}</span>
                            @if ($providerRefund->status->needsHand() && $canRefund)
                                <div class="mt-2 flex gap-2">
                                    <input type="text" wire:model="handNotes.{{ $providerRefund->id }}" maxlength="255" placeholder="{{ __('payments::payments.refunds.till.note') }}" class="h-11 min-w-0 flex-1 rounded-lg border-0 bg-slate-800 px-3 text-sm text-slate-100 placeholder:text-slate-500">
                                    <button type="button" wire:click="confirmDoneByHand(@js($providerRefund->id))" class="h-11 shrink-0 rounded-lg bg-amber-400 px-3 text-sm font-bold text-slate-950 hover:bg-amber-300">{{ __('payments::payments.refunds.till.done_button') }}</button>
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="grid grid-cols-2 gap-2 p-3">
                @if ($openOrder->isActive())
                    <a href="{{ route('pos.order', $openOrder) }}" class="flex h-14 items-center justify-center rounded-2xl bg-emerald-500 font-bold text-slate-950 hover:bg-emerald-400">{{ __('pos::pos.orders.open_order') }}</a>
                @else
                    <a href="{{ route('pos.receipt', $openOrder) }}" class="flex h-14 items-center justify-center rounded-2xl bg-slate-800 font-bold hover:bg-slate-700">{{ __('pos::pos.receipt.title') }}</a>
                @endif
                <button type="button" wire:click="reprint('{{ $openOrder->id }}')" class="h-14 rounded-2xl bg-slate-800 font-bold hover:bg-slate-700">{{ __('pos::pos.receipt.reprint') }}</button>
            </div>
        </aside>
    @endif

    @if ($refund)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="refund-modal">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-rose-500/40">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-rose-300">{{ __('pos::pos.orders.refund_title') }}</h2>
                    <button type="button" wire:click="cancelRefund" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <p class="mt-1 text-sm text-slate-400">{{ __('pos::pos.orders.refund_hint') }}</p>
                @if ($error)<p class="mt-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <input type="text" wire:model="refund.reason" wire:keydown.enter="confirmRefund" placeholder="{{ __('pos::pos.orders.refund_reason') }}" class="mt-3 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                <button type="button" wire:click="confirmRefund" class="mt-4 h-14 w-full rounded-2xl bg-rose-500 text-lg font-bold text-slate-950 hover:bg-rose-400">{{ __('pos::pos.order.void_continue') }}</button>
            </div>
        </div>
    @endif

    @include('pos::partials.manager-pin')
</div>
