@php($order = $this->order)
@php($due = $order->balanceDue())
<div class="flex h-full" x-data="{ amount: $wire.entangle('tendered'), due: {{ $due->amount }},
        pesewas() { const n = parseFloat(this.amount || '0'); return isNaN(n) ? 0 : Math.round(n * 100); },
        fmt(p) { return '{{ config('rms.currency_symbol') }} ' + (p / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        press(k) { if (k === '.' && this.amount.includes('.')) return; if (this.amount.includes('.') && this.amount.split('.')[1].length >= 2) return; if (this.amount.length > 9) return; this.amount = (this.amount === '0' && k !== '.') ? k : this.amount + k; } }">
    <aside class="flex w-[38%] min-w-[320px] flex-col border-r border-slate-800 bg-slate-900">
        <div class="px-4 py-3">
            <div class="text-lg font-bold">#{{ $order->displayNumber() }} · {{ $order->table ? __('pos::pos.order.table').' '.$order->table->name : $order->type->label() }}</div>
            <div class="text-xs text-slate-400">{{ __('pos::pos.pay.title') }}</div>
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
            @foreach ($order->tax_lines ?? [] as $tax)
                <div class="flex justify-between text-slate-400"><span>{{ $tax['name'] }}</span><span>{{ money($tax['amount']) }}</span></div>
            @endforeach
            @if ($order->paid_total->isPositive())
                <div class="flex justify-between text-emerald-300"><span>{{ __('pos::pos.pay.paid_so_far') }}</span><span>{{ money($order->paid_total) }}</span></div>
            @endif
            <div class="mt-1 flex justify-between text-2xl font-black"><span>{{ __('pos::pos.pay.due') }}</span><span>{{ money($due) }}</span></div>
        </div>
        <div class="p-3">
            <a href="{{ route('pos.order', $order) }}" class="flex h-14 items-center justify-center rounded-2xl bg-slate-800 font-semibold hover:bg-slate-700">← {{ __('pos::pos.pay.back') }}</a>
        </div>
    </aside>

    <section class="flex min-w-0 flex-1 flex-col items-center justify-center gap-4 p-4">
        <div class="w-full max-w-md">
            @if ($error)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

            <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.pay.tendered') }}</div>
                <div class="h-12 text-4xl font-black tabular-nums" x-text="fmt(pesewas())"></div>
                <div class="mt-2 flex justify-between text-lg font-semibold">
                    <span x-text="pesewas() >= due ? '{{ __('pos::pos.pay.change') }}' : '{{ __('pos::pos.pay.short') }}'"></span>
                    <span :class="pesewas() >= due ? 'text-emerald-400' : 'text-amber-300'" x-text="fmt(Math.abs(pesewas() - due))"></span>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-5 gap-2">
                @foreach ($this->quickAmounts as $i => $amount)
                    <button type="button" @click="amount = '{{ \App\Support\Money\Money::of($amount)->toDecimal() }}'" class="h-12 rounded-xl bg-slate-800 text-sm font-bold hover:bg-slate-700">{{ $i === 0 ? __('pos::pos.pay.exact') : money($amount, false) }}</button>
                @endforeach
            </div>

            <div class="mt-3 grid grid-cols-3 gap-2">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0'] as $key)
                    <button type="button" @click="press('{{ $key }}')" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-emerald-600">{{ $key }}</button>
                @endforeach
                <button type="button" @click="amount = amount.slice(0, -1)" @click.dblclick="amount = ''" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700" aria-label="Backspace">⌫</button>
            </div>

            <button type="button" wire:click="complete" :disabled="pesewas() <= 0" class="mt-3 h-16 w-full rounded-2xl bg-emerald-500 text-xl font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">{{ __('pos::pos.pay.complete') }}</button>
            <p class="mt-3 text-center text-xs text-slate-500">{{ __('pos::pos.pay.other_methods') }}</p>
        </div>
    </section>
</div>
