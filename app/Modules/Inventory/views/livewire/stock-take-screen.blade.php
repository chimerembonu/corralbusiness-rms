@php($p = fn (string $k, array $r = []) => __('inventory::inventory.stock_take.phone.'.$k, $r))
@php($q = fn (float $v) => \App\Modules\Inventory\Services\StockService::qty($v))
@php($take = $this->take)
<div class="h-full overflow-y-auto p-3">
    <div class="mx-auto max-w-2xl">
        <div class="mb-3 flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ $p('title') }}@if ($take) <span class="text-base font-normal text-slate-400">{{ $take->displayNumber() }}{{ $take->category ? ' · '.$take->category : '' }}</span>@endif</h1>
            <a href="{{ route('pos.home') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">← {{ $p('back') }}</a>
        </div>
        @if ($notice)<p class="mb-3 rounded-xl bg-emerald-500/15 px-4 py-3 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif
        @if ($error)<p class="mb-3 rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif

        @if (! $take)
            <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                @if ($this->openTakes->isNotEmpty())
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ $p('choose') }}</h2>
                    <div class="grid gap-2">
                        @foreach ($this->openTakes as $t)
                            <button type="button" wire:click="choose('{{ $t->id }}')" class="flex h-14 items-center justify-between rounded-2xl bg-slate-800 px-4 text-left font-bold hover:bg-slate-700"><span>{{ $t->displayNumber() }} · {{ $t->category ?? __('inventory::inventory.stock_take.fields.all') }}</span><span class="text-sm font-normal text-slate-400">{{ $t->started_at->format('d M H:i') }}</span></button>
                        @endforeach
                    </div>
                @else
                    <p class="text-slate-300">{{ $p('none') }}</p>
                @endif
                @can(\App\Modules\Staff\Enums\Permission::InventoryManage->value)
                    <div class="mt-4 grid gap-2">
                        <button type="button" wire:click="start" class="h-14 rounded-2xl bg-emerald-500 font-bold text-slate-950 hover:bg-emerald-400">{{ $p('start_all') }}</button>
                        @foreach ($this->categories as $category)
                            <button type="button" wire:click="start('{{ addslashes($category) }}')" class="h-12 rounded-2xl bg-slate-800 text-sm font-bold hover:bg-slate-700">{{ $p('start_category', ['category' => $category]) }}</button>
                        @endforeach
                    </div>
                @endcan
            </section>
        @else
            <div class="mb-3 flex items-center gap-2">
                <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $p('search') }}" class="h-12 flex-1 rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500">
                <span class="rounded-xl bg-slate-800 px-3 py-2 text-sm font-semibold {{ $this->remaining ? 'text-amber-300' : 'text-emerald-300' }}">{{ $this->remaining ? $p('left', ['count' => $this->remaining]) : $p('all_counted') }}</span>
            </div>
            <ul class="space-y-2">
                @foreach ($this->lines as $line)
                    <li wire:key="l-{{ $line->id }}" class="rounded-2xl bg-slate-900 p-3 ring-1 {{ $line->isCounted() ? 'ring-emerald-500/40' : 'ring-slate-800' }}">
                        <div class="flex items-center justify-between">
                            <div><div class="font-bold">{{ $line->name }}</div><div class="text-xs text-slate-400">{{ __('inventory::inventory.stock_take.fields.expected') }} {{ $q((float) $line->expected) }} {{ $line->unit }}@if ($line->isCounted()) · <span class="{{ $line->difference() < 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ $line->difference() > 0 ? '+' : '' }}{{ $q($line->difference()) }}</span>@else · <span class="text-amber-300">{{ $p('uncounted') }}</span>@endif</div></div>
                            <div class="flex items-center gap-1">
                                <button type="button" wire:click="bump('{{ $line->id }}', -1)" class="h-12 w-12 rounded-xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="Less">−</button>
                                <input type="number" step="0.001" min="0" inputmode="decimal" wire:model="counts.{{ $line->id }}" wire:change="save('{{ $line->id }}')" placeholder="—" class="h-12 w-24 rounded-xl border-0 bg-slate-950 text-center text-lg font-bold text-slate-100 placeholder:text-slate-600">
                                <button type="button" wire:click="bump('{{ $line->id }}', 1)" class="h-12 w-12 rounded-xl bg-slate-800 text-2xl font-bold hover:bg-slate-700" aria-label="More">+</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
            <button type="button" wire:click="finish" class="mt-4 h-14 w-full rounded-2xl bg-amber-400 text-lg font-bold text-slate-950 hover:bg-amber-300">{{ $p('done') }}</button>
        @endif
    </div>
    @include('pos::partials.manager-pin')
</div>
