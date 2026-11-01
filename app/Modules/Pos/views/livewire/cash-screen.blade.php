@php($c = fn (string $k, array $r = []) => __('pos::pos.cash.'.$k, $r))
@php($f = fn (string $k) => __('payments::payments.cash.fields.'.$k))
@php($session = $this->session)
@php($report = $this->report)
@php($dayClose = $this->dayClose)
@php($signed = fn (?int $p) => \App\Modules\Printing\Documents\CashReportDocument::signed($p, true))
<div class="h-full overflow-y-auto p-4">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ $c('title') }} <span class="text-base font-normal text-slate-400">{{ \Carbon\Carbon::parse($date)->translatedFormat('l j F Y') }}</span></h1>
        <a href="{{ route('pos.home') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">← {{ __('pos::pos.nav.new_order') }}</a>
    </div>

    @if ($notice)<p class="mb-3 rounded-xl bg-emerald-500/15 px-4 py-3 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif
    @if ($error)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- This till --}}
        <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $c('this_till') }}</h2>
            <div class="mt-1 text-xl font-bold">{{ $this->till?->name ?? $session?->till?->name ?? __('payments::payments.cash.no_till') }}</div>

            @if ($session)
                <div class="mt-1 text-sm text-slate-400">{{ $session->displayNumber() }} · {{ $f('opened_at') }} {{ $session->opened_at->format('H:i') }} · {{ $session->user?->name }}</div>
                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt class="text-slate-400">{{ $f('opening_float') }}</dt><dd class="text-right font-semibold">{{ money($session->opening_float) }}</dd>
                    <dt class="text-slate-400">{{ $f('cash_sales') }}</dt><dd class="text-right font-semibold">{{ money($session->cash_sales) }}</dd>
                    <dt class="text-slate-400">{{ $f('cash_tips') }}</dt><dd class="text-right font-semibold">{{ money($session->cash_tips) }}</dd>
                    <dt class="text-slate-400">{{ $f('pay_ins') }}</dt><dd class="text-right font-semibold">{{ money($session->pay_ins) }}</dd>
                    <dt class="text-slate-400">{{ $f('pay_outs') }}</dt><dd class="text-right font-semibold">{{ money($session->pay_outs) }}</dd>
                    <dt class="text-slate-400">{{ $f('cash_refunds') }}</dt><dd class="text-right font-semibold">{{ money($session->cash_refunds) }}</dd>
                    @if ($this->seesExpected)
                        <dt class="text-emerald-300">{{ $f('expected') }}</dt><dd class="text-right text-lg font-black text-emerald-300">{{ money($this->expected) }}</dd>
                    @endif
                </dl>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    @can(\App\Modules\Staff\Enums\Permission::CashDrawer->value)
                        <button type="button" wire:click="openModal('pay_in')" class="h-14 rounded-2xl bg-slate-800 text-base font-bold hover:bg-slate-700">+ {{ $c('pay_in') }}</button>
                        <button type="button" wire:click="openModal('pay_out')" class="h-14 rounded-2xl bg-slate-800 text-base font-bold hover:bg-slate-700">− {{ $c('pay_out') }}</button>
                    @endcan
                    <a href="{{ route('pos.cash.session', $session) }}" class="flex h-14 items-center justify-center rounded-2xl bg-slate-800 text-base font-bold hover:bg-slate-700">{{ $c('slip') }}</a>
                    <button type="button" wire:click="openModal('cash_up')" class="h-14 rounded-2xl bg-amber-400 text-base font-bold text-slate-950 hover:bg-amber-300">{{ $c('cash_up') }}</button>
                </div>
            @else
                <p class="mt-2 text-slate-300">{{ $c('not_open') }}</p>
                <p class="text-sm text-slate-500">{{ $c('not_open_hint') }}</p>
                @can(\App\Modules\Staff\Enums\Permission::PaymentsTake->value)
                    <button type="button" wire:click="openModal('open')" class="mt-4 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ $c('open_till') }}</button>
                @endcan
            @endif
        </section>

        {{-- Today / day close --}}
        <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $c('today') }}</h2>
            @if ($report)
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt class="text-slate-400">{{ __('pos::pos.today.gross') }}</dt><dd class="text-right text-lg font-black text-emerald-400">{{ money($report['gross_sales']) }}</dd>
                    @foreach ($report['by_payment_method'] as $row)
                        <dt class="text-slate-400">{{ \App\Modules\Payments\Enums\PaymentMethod::from($row['method'])->label() }} × {{ $row['count'] }}</dt><dd class="text-right font-semibold">{{ money($row['total']) }}</dd>
                    @endforeach
                    <dt class="text-slate-400">{{ $f('expected') }}</dt><dd class="text-right font-semibold">{{ money($report['cash']['expected']) }}</dd>
                    <dt class="text-slate-400">{{ $f('counted') }}</dt><dd class="text-right font-semibold">{{ money($report['cash']['counted']) }}</dd>
                    <dt class="text-slate-400">{{ $f('variance') }}</dt><dd class="text-right font-semibold {{ $report['cash']['variance'] < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ $signed($report['cash']['variance']) }}</dd>
                </dl>
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <a href="{{ route('pos.cash.report', ['date' => $date]) }}" class="flex h-12 items-center justify-center rounded-xl bg-slate-800 text-sm font-bold hover:bg-slate-700">{{ $c('view_x') }}</a>
                    <button type="button" wire:click="printX" class="h-12 rounded-xl bg-slate-800 text-sm font-bold hover:bg-slate-700">{{ $c('print_x') }}</button>
                </div>
            @endif

            <div class="mt-4 border-t border-slate-800 pt-4">
                @if ($dayClose)
                    <p class="font-semibold text-emerald-300">{{ $c('closed_at', ['number' => $dayClose->displayNumber(), 'time' => $dayClose->closed_at->format('H:i'), 'name' => $dayClose->closedBy?->name]) }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <a href="{{ route('pos.cash.report', ['date' => $date]) }}" class="flex h-12 items-center justify-center rounded-xl bg-slate-800 text-sm font-bold hover:bg-slate-700">{{ $c('view_z') }}</a>
                        <button type="button" wire:click="openModal('reopen')" class="h-12 rounded-xl bg-rose-500/20 text-sm font-bold text-rose-200 hover:bg-rose-500/30">{{ $c('reopen') }}</button>
                    </div>
                @else
                    @if ($this->blockers)
                        <ul class="space-y-1 text-sm text-amber-300">
                            @foreach ($this->blockers as $blocker)<li>⚠ {{ $blocker }}</li>@endforeach
                        </ul>
                    @else
                        <p class="text-sm text-slate-400">{{ $c('ready_to_close') }}</p>
                    @endif
                    <button type="button" wire:click="openModal('close_day')" @disabled((bool) $this->blockers) class="mt-3 h-14 w-full rounded-2xl bg-rose-500 text-lg font-bold text-white hover:bg-rose-400 disabled:opacity-40">{{ $c('close_day') }}</button>
                    <p class="mt-1 text-xs text-slate-500">{{ $c('close_day_hint') }}</p>
                @endif
            </div>
        </section>
    </div>

    {{-- Tills today --}}
    <section class="mt-4 rounded-2xl bg-slate-900 ring-1 ring-slate-800">
        <h2 class="px-4 pt-4 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $c('sessions') }}</h2>
        <table class="mt-2 w-full text-sm">
            <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-2">{{ $f('number') }}</th><th class="px-2 py-2">{{ $f('till') }}</th><th class="px-2 py-2">{{ $f('cashier') }}</th><th class="px-2 py-2">{{ $f('status') }}</th><th class="px-2 py-2 text-right">{{ $f('opening_float') }}</th><th class="px-2 py-2 text-right">{{ $f('cash_sales') }}</th><th class="px-2 py-2 text-right">{{ $f('variance') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-800">
                @forelse ($this->sessions as $row)
                    <tr>
                        <td class="px-4 py-2 font-semibold">{{ $row->displayNumber() }}</td>
                        <td class="px-2 py-2">{{ $row->till?->name ?? __('payments::payments.cash.no_till') }}</td>
                        <td class="px-2 py-2">{{ $row->user?->name }}</td>
                        <td class="px-2 py-2">{{ $row->status->label() }} <span class="text-slate-500">{{ $row->opened_at->format('H:i') }}@if ($row->closed_at)–{{ $row->closed_at->format('H:i') }}@endif</span></td>
                        <td class="px-2 py-2 text-right">{{ money($row->opening_float) }}</td>
                        <td class="px-2 py-2 text-right">{{ money($row->cash_sales) }}</td>
                        <td class="px-2 py-2 text-right font-semibold {{ ($row->variance ?? 0) < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ $row->variance === null ? '—' : $signed($row->variance) }}</td>
                        <td class="px-2 py-2 text-right whitespace-nowrap">
                            <a href="{{ route('pos.cash.session', $row) }}" class="inline-flex h-10 items-center rounded-lg bg-slate-800 px-3 font-semibold hover:bg-slate-700">{{ $c('slip') }}</a>
                            @unless ($row->isOpen())<button type="button" wire:click="reprintCashUp('{{ $row->id }}')" class="inline-flex h-10 items-center rounded-lg bg-slate-800 px-3 font-semibold hover:bg-slate-700">{{ $c('reprint') }}</button>@endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-3 text-slate-500">{{ $c('no_sessions') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Modals --}}
    @if ($modal === 'open' || $modal === 'pay_in' || $modal === 'pay_out')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/85 p-4" wire:key="modal-{{ $modal }}"
             x-data="{ amount: $wire.entangle('amount'),
                fmt(p) { return '{{ config('rms.currency_symbol') }} ' + (p / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                pesewas() { const n = parseFloat(this.amount || '0'); return isNaN(n) ? 0 : Math.round(n * 100); },
                press(k) { let v = this.amount; if (k === '.' && v.includes('.')) return; if (v.includes('.') && v.split('.')[1].length >= 2) return; if (v.length > 9) return; this.amount = (v === '0' && k !== '.') ? k : v + k; },
                back() { this.amount = this.amount.slice(0, -1); } }">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-start justify-between">
                    <h2 class="text-lg font-bold">{{ $modal === 'open' ? $c('open_till') : ($modal === 'pay_in' ? $c('pay_in') : $c('pay_out')) }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>

                @if ($modal === 'open' && ! $this->till)
                    <p class="mt-2 text-sm text-slate-400">{{ $c('choose_till') }}</p>
                    <div class="mt-2 grid grid-cols-2 gap-2">
                        @foreach ($this->tills as $t)
                            <button type="button" wire:click="$set('tillId', '{{ $t->id }}')" class="h-12 rounded-xl text-sm font-bold {{ $tillId === $t->id ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $t->name }}</button>
                        @endforeach
                    </div>
                @endif

                @if ($modal !== 'open')
                    <p class="mt-2 text-sm text-slate-400">{{ $c('reason') }}</p>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($modal === 'pay_in' ? $payInReasons : $payOutReasons as $r)
                            <button type="button" wire:click="setReason('{{ addslashes($r) }}')" class="h-11 rounded-xl px-3 text-sm font-semibold {{ $reason === $r ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $r }}</button>
                        @endforeach
                    </div>
                    <input type="text" wire:model="reason" placeholder="{{ $c('reason') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                    <input type="text" wire:model="reference" placeholder="{{ $c('reference') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                @endif

                <div class="mt-3 rounded-2xl bg-slate-950 p-4 ring-1 ring-emerald-500">
                    <div class="text-xs uppercase tracking-wide text-slate-400">{{ $modal === 'open' ? $c('float') : $c('amount') }}</div>
                    <div class="h-12 text-4xl font-black tabular-nums" x-text="fmt(pesewas())"></div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2">
                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '0'] as $key)
                        <button type="button" @click="press('{{ $key }}')" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-emerald-600">{{ $key }}</button>
                    @endforeach
                    <button type="button" @click="back()" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700" aria-label="Backspace">⌫</button>
                </div>
                @if ($error)<p class="mt-3 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <button type="button" wire:click="{{ $modal === 'open' ? 'openTill' : 'recordMovement' }}" class="mt-3 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ $modal === 'open' ? $c('open_till') : ($modal === 'pay_in' ? $c('pay_in') : $c('pay_out')) }}</button>
            </div>
        </div>
    @endif

    @if ($modal === 'cash_up')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/85 p-4" wire:key="modal-cash-up"
             x-data="{ count: $wire.entangle('count'), amount: $wire.entangle('amount'),
                fmt(p) { return '{{ config('rms.currency_symbol') }} ' + (p / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                total() { let t = 0; for (const [v, c] of Object.entries(this.count || {})) { t += parseInt(v) * (parseInt(c) || 0); } return t; },
                bump(v, d) { const n = Math.max(0, (parseInt(this.count[v]) || 0) + d); this.count[v] = n === 0 ? '' : String(n); } }">
            <div class="w-full max-w-2xl rounded-3xl bg-slate-900 p-5 ring-1 ring-amber-400/50">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-amber-300">{{ $c('cash_up') }}</h2>
                        <p class="text-sm text-slate-400">{{ $this->seesExpected ? $c('cash_up_hint') : $c('expected_hidden') }}</p>
                    </div>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                <p class="mt-3 text-xs uppercase tracking-wide text-slate-400">{{ $c('count_sheet') }}</p>
                <div class="mt-1 grid grid-cols-2 gap-2 md:grid-cols-3">
                    @foreach ($denominations as $value)
                        <div class="flex items-center gap-1 rounded-xl bg-slate-800 p-1">
                            <span class="w-16 shrink-0 pl-2 text-sm font-bold">{{ money($value, false) }}</span>
                            <button type="button" @click="bump({{ $value }}, -1)" class="h-11 w-11 rounded-lg bg-slate-700 text-xl font-bold hover:bg-slate-600">−</button>
                            <input type="number" min="0" inputmode="numeric" x-model="count[{{ $value }}]" class="h-11 w-full min-w-0 rounded-lg border-0 bg-slate-950 text-center text-lg font-bold text-slate-100" placeholder="0">
                            <button type="button" @click="bump({{ $value }}, 1)" class="h-11 w-11 rounded-lg bg-slate-700 text-xl font-bold hover:bg-slate-600">+</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex items-center justify-between rounded-2xl bg-slate-950 px-4 py-3 ring-1 ring-amber-400">
                    <span class="text-sm uppercase tracking-wide text-slate-400">{{ $f('counted') }}</span>
                    <span class="text-3xl font-black tabular-nums" x-text="fmt(total())"></span>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <span class="text-sm text-slate-400">{{ $c('or_total') }}</span>
                    <input type="text" inputmode="decimal" x-model="amount" x-show="total() === 0" placeholder="0.00" class="h-12 w-40 rounded-xl border-0 bg-slate-800 px-4 text-right text-lg font-bold text-slate-100 placeholder:text-slate-500">
                </div>
                <input type="text" wire:model="notes" placeholder="{{ $c('notes') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                @if ($error)<p class="mt-3 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <button type="button" wire:click="cashUp" class="mt-3 h-14 w-full rounded-2xl bg-amber-400 text-lg font-bold text-slate-950 hover:bg-amber-300">{{ $c('confirm_cash_up') }}</button>
            </div>
        </div>
    @endif

    @if ($modal === 'close_day' || $modal === 'reopen')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/85 p-4" wire:key="modal-{{ $modal }}">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-rose-400/50">
                <div class="flex items-start justify-between">
                    <h2 class="text-lg font-bold text-rose-200">{{ $modal === 'reopen' ? $c('reopen') : $c('close_day') }}</h2>
                    <button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
                </div>
                @if ($modal === 'reopen')
                    <p class="mt-2 text-sm text-slate-400">{{ $c('reopen_reason') }}</p>
                    <input type="text" wire:model="reason" placeholder="{{ $c('reason') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                @else
                    <p class="mt-2 text-sm text-slate-400">{{ $c('close_day_hint') }}</p>
                    <input type="text" wire:model="notes" placeholder="{{ $c('notes') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                @endif
                @if ($error)<p class="mt-3 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <button type="button" wire:click="{{ $modal === 'reopen' ? 'reopenDay' : 'closeDay' }}" class="mt-3 h-14 w-full rounded-2xl bg-rose-500 text-lg font-bold text-white hover:bg-rose-400">{{ $modal === 'reopen' ? $c('reopen') : $c('close_day') }}</button>
            </div>
        </div>
    @endif

    @include('pos::partials.manager-pin')
</div>
