@php($c = fn (string $k, array $r = []) => __('customers::customers.pos.'.$k, $r))
@php($customer = $this->customer)
<div class="flex h-full">
    <aside class="flex w-[40%] min-w-[300px] flex-col border-r border-slate-800 bg-slate-900">
        <div class="p-3">
            <div class="flex items-center justify-between"><h1 class="text-xl font-bold">{{ $c('title') }}</h1><a href="{{ route('pos.home') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">← {{ $c('back') }}</a></div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ $c('search') }}" class="mt-3 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
            <button type="button" wire:click="openModal('new')" class="mt-2 h-12 w-full rounded-xl bg-emerald-500 font-bold text-slate-950 hover:bg-emerald-400">+ {{ $c('new') }}</button>
        </div>
        <ul class="min-h-0 flex-1 divide-y divide-slate-800 overflow-y-auto">
            @forelse ($this->matches as $m)
                <li><button type="button" wire:click="select('{{ $m->id }}')" class="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-slate-800 {{ $customerId === $m->id ? 'bg-slate-800' : '' }}"><span><span class="font-bold">{{ $m->name }}</span><br><span class="text-sm text-slate-400">{{ $m->phone }}</span></span>@if ($m->owes())<span class="text-sm font-semibold text-amber-300">{{ money($m->balance()) }}</span>@endif</button></li>
            @empty
                <li class="px-4 py-3 text-sm text-slate-500">{{ trim($search) === '' ? '' : $c('none_found') }}</li>
            @endforelse
        </ul>
    </aside>

    <section class="min-w-0 flex-1 overflow-y-auto p-4">
        @if ($notice)<p class="mb-3 rounded-xl bg-emerald-500/15 px-4 py-3 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif
        @if ($error && ! $modal)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
        @if ($customer)
            <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-2xl font-bold">{{ $customer->name }}</h2>
                        <div class="text-slate-400">{{ $customer->phone }}@if ($customer->email) · {{ $customer->email }}@endif</div>
                        <div class="mt-1 text-sm text-slate-400">{{ $c('visits', ['count' => $customer->orders_count, 'spent' => money($customer->total_spent)]) }}@if ($customer->last_order_at) · {{ $c('last_visit', ['date' => $customer->last_order_at->format('d/m/Y')]) }}@endif @if ($customer->points_balance)· {{ __('customers::customers.loyalty.points', ['points' => $customer->points_balance]) }}@endif</div>
                        @if ($customer->notes)<div class="mt-1 text-sm text-amber-200">{{ $customer->notes }}</div>@endif
                        @foreach ($customer->addresses as $a)<div class="mt-1 text-sm text-slate-300">📍 {{ $a->label() }}</div>@endforeach
                    </div>
                    <div class="text-right">
                        @if ($customer->allow_account)
                            <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('customers::customers.fields.account_balance') }}</div>
                            <div class="text-3xl font-black {{ $customer->owes() ? 'text-amber-300' : 'text-emerald-300' }}">{{ money($customer->balance()) }}</div>
                            @if ($customer->credit_limit)<div class="text-xs text-slate-500">{{ __('customers::customers.fields.credit_limit_short') }} {{ money($customer->credit_limit) }}</div>@endif
                            <button type="button" wire:click="openModal('receive')" class="mt-2 h-12 whitespace-nowrap rounded-xl bg-emerald-500 px-4 font-bold text-slate-950 hover:bg-emerald-400">{{ __('customers::customers.account.receive') }}</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $c('history') }}</h3>
                    <ul class="mt-2 divide-y divide-slate-800 text-sm">
                        @forelse ($this->history as $o)
                            <li class="flex items-center justify-between py-2"><span>#{{ $o->displayNumber() }} <span class="text-slate-400">{{ $o->opened_at->format('d/m H:i') }} · {{ $o->type->label() }} · {{ $o->items->count() }} items</span></span><span class="font-semibold">{{ money($o->total) }}</span></li>
                        @empty
                            <li class="py-2 text-slate-500">{{ $c('no_history') }}</li>
                        @endforelse
                    </ul>
                </section>
                @if ($customer->allow_account)
                    <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('customers::customers.fields.statement') }}</h3>
                        <ul class="mt-2 divide-y divide-slate-800 text-sm">
                            @forelse ($this->statement as $t)
                                <li class="flex items-center justify-between py-2"><span>{{ $t->happened_at->format('d/m H:i') }} <span class="text-slate-400">{{ $t->type->label() }}{{ $t->notes ? ' · '.$t->notes : '' }}</span></span><span class="font-semibold {{ $t->amount > 0 ? 'text-amber-300' : 'text-emerald-300' }}">{{ $t->amount > 0 ? '+' : '−' }}{{ money($t->money()) }}</span></li>
                            @empty
                                <li class="py-2 text-slate-500">—</li>
                            @endforelse
                        </ul>
                    </section>
                @endif
            </div>
        @else
            <p class="text-slate-500">{{ $c('search') }}</p>
        @endif
    </section>

    @if ($modal === 'receive' && $customer)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/85 p-4" wire:key="modal-receive">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-emerald-500/40">
                <div class="flex items-center justify-between"><h2 class="text-lg font-bold">{{ $c('receive') }} · {{ $customer->name }}</h2><button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button></div>
                <div class="mt-3 grid grid-cols-3 gap-2">
                    @foreach ($methods as $m)
                        <button type="button" wire:click="setMethod('{{ $m->value }}')" class="h-12 rounded-xl text-sm font-bold {{ $method === $m->value ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $m->label() }}</button>
                    @endforeach
                </div>
                @if ($method === 'momo')
                    <div class="mt-2 grid grid-cols-3 gap-2">
                        @foreach ($providers as $p)
                            <button type="button" wire:click="setProvider('{{ $p->value }}')" class="h-11 rounded-xl text-sm font-bold {{ $provider === $p->value ? 'bg-amber-400 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ $p->label() }}</button>
                        @endforeach
                    </div>
                @endif
                @if ($method !== 'cash')<input type="text" wire:model="reference" placeholder="{{ __('customers::customers.account.reference') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">@endif
                <label class="mt-3 block"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('customers::customers.account.amount') }} ({{ config('rms.currency') }})</span>
                    <input type="text" inputmode="decimal" wire:model="amount" class="mt-1 h-14 w-full rounded-xl border-0 bg-slate-950 px-4 text-right text-3xl font-black text-slate-100 ring-1 ring-emerald-500"></label>
                @if ($error)<p class="mt-3 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <button type="button" wire:click="receive" class="mt-3 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ $c('receive') }}</button>
            </div>
        </div>
    @endif

    @if ($modal === 'new')
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/85 p-4" wire:key="modal-new">
            <div class="w-full max-w-md rounded-3xl bg-slate-900 p-5 ring-1 ring-slate-700">
                <div class="flex items-center justify-between"><h2 class="text-lg font-bold">{{ $c('new') }}</h2><button type="button" wire:click="closeModal" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button></div>
                <input type="text" wire:model="newCustomer.name" placeholder="{{ __('customers::customers.fields.name') }}" class="mt-3 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                <input type="tel" inputmode="tel" wire:model="newCustomer.phone" placeholder="{{ __('customers::customers.fields.phone') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                <input type="text" wire:model="newCustomer.address" placeholder="{{ __('customers::customers.fields.address') }}" class="mt-2 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                @if ($error)<p class="mt-3 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
                <button type="button" wire:click="create" class="mt-3 h-14 w-full rounded-2xl bg-emerald-500 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ $c('create') }}</button>
            </div>
        </div>
    @endif
</div>
