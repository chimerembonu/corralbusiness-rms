@php($order = $this->order)
@php($due = $order->balanceDue())
@php($suggested = $this->suggested)
<div class="flex h-full" x-data="{ amount: $wire.entangle('amount'), tip: $wire.entangle('tip'), field: 'amount', due: {{ $suggested->amount }}, method: $wire.entangle('method'),
        toP(v) { const n = parseFloat(v || '0'); return isNaN(n) ? 0 : Math.round(n * 100); },
        pesewas() { return this.toP(this.amount); }, tipP() { return this.toP(this.tip); },
        fmt(p) { return '{{ config('rms.currency_symbol') }} ' + (p / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        press(k) { let v = this[this.field]; if (k === '.' && v.includes('.')) return; if (v.includes('.') && v.split('.')[1].length >= 2) return; if (v.length > 9) return; this[this.field] = (v === '0' && k !== '.') ? k : v + k; },
        back() { this[this.field] = this[this.field].slice(0, -1); } }">
    <aside class="flex w-[38%] min-w-[320px] flex-col border-r border-slate-800 bg-slate-900">
        <div class="px-4 py-3">
            <div class="text-lg font-bold">#{{ $order->displayNumber() }} · {{ $order->table ? __('pos::pos.order.table').' '.$order->table->name : $order->type->label() }}</div>
            <div class="text-xs text-slate-400">{{ __('pos::pos.pay.title') }}@if ($ways > 1) · {{ __('pos::pos.pay.in_shares', ['ways' => $ways]) }}@endif</div>
        </div>
        <ul class="min-h-0 flex-1 divide-y divide-slate-800 overflow-y-auto">
            @foreach ($order->items->where('status', '!=', \App\Modules\Orders\Enums\OrderItemStatus::Voided) as $line)
                <li class="flex justify-between px-4 py-2 text-sm">
                    <span>{{ $line->quantity }} × {{ $line->displayName() }}@foreach ($line->modifiers as $m) <span class="text-slate-400">+{{ $m->displayName() }}</span>@endforeach</span>
                    <span class="font-semibold">{{ money($line->line_subtotal) }}</span>
                </li>
            @endforeach
        </ul>
        <div class="border-t border-slate-800 px-4 py-3 text-sm">
            @if ($order->discount_total->isPositive())
                <div class="flex justify-between text-amber-300"><span>{{ __('pos::pos.order.discount') }} {{ $order->discountLabel() }}</span><span>− {{ money($order->discount_total) }}</span></div>
            @endif
            @foreach ($order->tax_lines ?? [] as $tax)
                <div class="flex justify-between text-slate-400"><span>{{ $tax['name'] }}</span><span>{{ money($tax['amount']) }}</span></div>
            @endforeach
            @if ($order->service_charge->isPositive())
                <div class="flex justify-between text-slate-400"><span>{{ __('orders::orders.service_charge') }}</span><span>{{ money($order->service_charge) }}</span></div>
            @endif
            @if ($order->delivery_fee->isPositive())
                <div class="flex justify-between text-slate-400"><span>{{ __('orders::orders.delivery_fee') }}</span><span>{{ money($order->delivery_fee) }}</span></div>
            @endif
            <div class="flex justify-between font-semibold"><span>{{ __('pos::pos.order.total') }}</span><span>{{ money($order->total) }}</span></div>
            @foreach ($order->payments->where('status', 'completed') as $payment)
                <div class="flex justify-between text-emerald-300"><span>{{ $payment->methodLabel() }}@if ($payment->reference) <span class="text-emerald-300/60">{{ $payment->reference }}</span>@endif</span><span>− {{ money($payment->amount) }}</span></div>
            @endforeach
            <div class="mt-1 flex justify-between text-2xl font-black"><span>{{ __('pos::pos.pay.due') }}</span><span>{{ money($due) }}</span></div>
        </div>
        <div class="p-3">
            <a href="{{ route('pos.order', $order) }}" class="flex h-14 items-center justify-center rounded-2xl bg-slate-800 font-semibold hover:bg-slate-700">← {{ __('pos::pos.pay.back') }}</a>
        </div>
    </aside>

    <section class="flex min-w-0 flex-1 flex-col items-center justify-start gap-3 overflow-y-auto p-4">
        <div class="w-full max-w-md">
            @if ($error)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

            <div class="grid grid-cols-3 gap-2">
                @foreach ($methods as $m)
                    <button type="button" wire:click="setMethod('{{ $m->value }}')" class="h-14 rounded-2xl text-base font-bold {{ $method === $m->value ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $m->label() }}</button>
                @endforeach
            </div>

            @if ($method === 'momo')
                <div class="mt-3 grid grid-cols-3 gap-2">
                    @foreach ($providers as $p)
                        <button type="button" wire:click="setProvider('{{ $p->value }}')" class="h-12 rounded-xl text-sm font-bold {{ $provider === $p->value ? 'bg-amber-400 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $p->label() }}</button>
                    @endforeach
                </div>
            @endif
            @if ($method !== 'cash')
                <input type="text" wire:model="reference" placeholder="{{ $method === 'momo' ? __('pos::pos.pay.momo_reference') : __('pos::pos.pay.card_reference') }}" class="mt-3 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
            @endif

            <div class="mt-3 rounded-2xl bg-slate-900 p-4 ring-1" :class="field === 'amount' ? 'ring-emerald-500' : 'ring-slate-800'" @click="field = 'amount'">
                <div class="text-xs uppercase tracking-wide text-slate-400" x-text="method === 'cash' ? '{{ __('pos::pos.pay.tendered') }}' : '{{ __('pos::pos.pay.amount') }}'"></div>
                <div class="h-12 text-4xl font-black tabular-nums" x-text="fmt(pesewas())"></div>
                <template x-if="method === 'cash'">
                    <div class="mt-2 flex justify-between text-lg font-semibold">
                        <span x-text="pesewas() - tipP() >= due ? '{{ __('pos::pos.pay.change') }}' : '{{ __('pos::pos.pay.short') }}'"></span>
                        <span :class="pesewas() - tipP() >= due ? 'text-emerald-400' : 'text-amber-300'" x-text="fmt(Math.abs(pesewas() - tipP() - due))"></span>
                    </div>
                </template>
            </div>

            @if ($this->tipsEnabled)
                <div class="mt-2 flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-2 ring-1" :class="field === 'tip' ? 'ring-emerald-500' : 'ring-slate-800'" @click="field = 'tip'">
                    <span class="text-xs uppercase tracking-wide text-slate-400">{{ __('orders::orders.tip') }}</span>
                    <span class="flex-1 text-right text-xl font-bold tabular-nums" x-text="fmt(tipP())"></span>
                    @foreach ([200, 500, 1000] as $t)
                        <button type="button" wire:click="setTip({{ $t }})" class="h-10 rounded-lg bg-slate-800 px-3 text-sm font-bold hover:bg-slate-700">+{{ money($t, false) }}</button>
                    @endforeach
                    <button type="button" wire:click="setTip(0)" class="h-10 rounded-lg bg-slate-800 px-3 text-sm font-bold hover:bg-slate-700">0</button>
                </div>
            @endif

            <div class="mt-3 grid grid-cols-5 gap-2">
                @foreach ($this->quickAmounts as $i => $quick)
                    <button type="button" @click="field = 'amount'; amount = '{{ \App\Support\Money\Money::of($quick)->toDecimal() }}'" class="h-12 rounded-xl bg-slate-800 text-sm font-bold hover:bg-slate-700">{{ $i === 0 ? ($ways > 1 ? __('pos::pos.pay.share') : __('pos::pos.pay.exact')) : money($quick, false) }}</button>
                @endforeach
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0'] as $key)
                    <button type="button" @click="press('{{ $key }}')" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-emerald-600">{{ $key }}</button>
                @endforeach
                <button type="button" @click="back()" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700" aria-label="Backspace">⌫</button>
            </div>

            <button type="button" wire:click="complete" :disabled="pesewas() <= 0" class="mt-3 h-16 w-full rounded-2xl bg-emerald-500 text-xl font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">{{ __('pos::pos.pay.complete') }}</button>
        </div>
    </section>
</div>
