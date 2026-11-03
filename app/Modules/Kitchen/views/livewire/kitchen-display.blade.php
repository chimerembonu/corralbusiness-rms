<div class="flex h-full flex-col"
     x-data="{ live: false, now: Date.now(),
        init() {
            setInterval(() => this.now = Date.now(), 1000);
            const conn = window.Echo?.connector?.pusher?.connection;
            if (conn) { this.live = conn.state === 'connected'; conn.bind('state_change', s => this.live = s.current === 'connected'); }
        },
        age(sentAt) { const s = Math.max(0, Math.floor((this.now - sentAt) / 1000)); return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); } }"
     @keydown.window="if (/^[1-9]$/.test($event.key)) $wire.bumpIndex(parseInt($event.key)); else if ($event.key === 'Backspace' || $event.key.toLowerCase() === 'r') $wire.recall(null)">

    {{-- Polling: slow keep-alive always; fast polling only while the live socket is down --}}
    <div wire:poll.30s="refresh"></div>
    @if ($error)<p class="mx-4 mt-2 rounded-xl bg-rose-500/15 px-4 py-3 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
    <template x-if="!live"><div wire:poll.4s="refresh"></div></template>

    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 px-4 py-2 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-lg font-bold">{{ __('kitchen::kitchen.title') }}</span>
            @if ($stations->isNotEmpty())
                <nav class="flex gap-1">
                    <button type="button" wire:click="selectStation('')" class="h-10 rounded-xl px-3 text-sm font-bold {{ $station === null ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}">{{ __('kitchen::kitchen.all_stations') }}</button>
                    @foreach ($stations as $s)
                        <button type="button" wire:click="selectStation('{{ $s->code }}')" class="h-10 rounded-xl px-3 text-sm font-bold {{ $station?->id === $s->id ? 'text-slate-950' : 'bg-slate-800 hover:bg-slate-700' }}" @if ($station?->id === $s->id) style="background: {{ $s->color ?? '#10b981' }}" @endif>{{ $s->name }}</button>
                    @endforeach
                    <a href="{{ route('kitchen.expo') }}" class="flex h-10 items-center rounded-xl bg-slate-800 px-3 text-sm font-bold hover:bg-slate-700">{{ __('kitchen::kitchen.expo') }}</a>
                </nav>
            @endif
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="live ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'" x-text="live ? '{{ __('kitchen::kitchen.live') }}' : '{{ __('kitchen::kitchen.polling') }}'"></span>
            <span class="text-slate-500">{{ __('kitchen::kitchen.keys') }}</span>
        </div>
        <div class="flex items-center gap-2 text-slate-400">
            <span>{{ $tickets->count() }} {{ __('kitchen::kitchen.open_count') }}</span>
            <button type="button" wire:click="toggleAllDay" class="h-10 rounded-xl px-3 text-sm font-bold {{ $showAllDay ? 'bg-slate-700 text-slate-100' : 'bg-slate-800 hover:bg-slate-700' }}">{{ __('kitchen::kitchen.all_day') }}</button>
        </div>
    </div>

    <div class="flex min-h-0 flex-1">
    @if ($showAllDay)
        <aside class="w-56 shrink-0 overflow-y-auto border-r border-slate-800 bg-slate-900 p-3">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('kitchen::kitchen.all_day') }}@if ($station) · {{ $station->name }}@endif</h2>
            @forelse ($allDay as $row)
                <div class="flex items-center justify-between py-1 text-base"><span class="truncate">{{ $row['name'] }}</span><span class="ml-2 shrink-0 rounded-lg bg-slate-800 px-2 font-black text-emerald-300">{{ $row['quantity'] }}</span></div>
            @empty
                <p class="text-sm text-slate-500">{{ __('kitchen::kitchen.all_day_empty') }}</p>
            @endforelse
        </aside>
    @endif
    <div class="min-h-0 flex-1 overflow-y-auto p-3">
        @if ($tickets->isEmpty())
            <div class="flex h-full items-center justify-center text-2xl text-slate-600">{{ __('kitchen::kitchen.empty') }}</div>
        @else
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                @foreach ($tickets as $index => $ticket)
                    @php($bucket = $ticket->ageBucket())
                    <article wire:key="ticket-{{ $ticket->id }}" class="flex flex-col overflow-hidden rounded-2xl bg-slate-900 ring-2 {{ ['fresh' => 'ring-emerald-500', 'warn' => 'ring-amber-400', 'late' => 'ring-rose-500'][$bucket] }}">
                        <header class="flex items-center justify-between px-3 py-2 text-slate-950 {{ ['fresh' => 'bg-emerald-500', 'warn' => 'bg-amber-400', 'late' => 'bg-rose-500'][$bucket] }}">
                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-950/20 text-sm font-bold">{{ $index + 1 }}</span>
                                <span class="text-lg font-black">#{{ $ticket->displayNumber() }}</span>
                                <span class="text-sm font-semibold">{{ $ticket->table_name ? __('pos::pos.order.table').' '.$ticket->table_name : \App\Modules\Orders\Enums\OrderType::from($ticket->order_type)->label() }}@if ($ticket->is_training) <span class="rounded bg-amber-400 px-1.5 py-0.5 text-[10px] font-black uppercase text-slate-950">{{ __('pos::pos.training.badge') }}</span>@endif</span>
                                @if ($station === null && $ticket->station)<span class="rounded-full bg-slate-950/25 px-2 text-xs font-bold uppercase">{{ $ticket->station->name }}</span>@endif
                            </div>
                            <span class="font-mono text-lg font-bold tabular-nums" x-text="age({{ $ticket->sent_at->getTimestampMs() }})">{{ gmdate('i:s', $ticket->ageSeconds()) }}</span>
                        </header>
                        <ul class="flex-1 divide-y divide-slate-800 px-3 py-1 text-lg">
                            @foreach ($ticket->items as $item)
                                <li class="py-1.5 {{ $item->is_voided ? 'line-through opacity-40' : ($item->is_held ? 'opacity-50' : '') }}">
                                    <div class="font-bold"><span class="text-emerald-300">{{ $item->quantity }}×</span> {{ $item->name }}
                                        @if ($item->is_held)<span class="ml-1 rounded-full bg-amber-500/20 px-2 text-[11px] font-bold uppercase text-amber-300">{{ __('kitchen::kitchen.hold_course', ['number' => $item->course]) }}</span>
                                        @elseif (($item->course ?? 1) > 1)<span class="ml-1 rounded-full bg-sky-500/20 px-2 text-[11px] font-bold uppercase text-sky-300">{{ __('kitchen::kitchen.course', ['number' => $item->course]) }}</span>@endif
                                    </div>
                                    @foreach ($item->modifiers ?? [] as $modifier)
                                        <div class="pl-6 text-sm text-slate-300">+ {{ $modifier }}</div>
                                    @endforeach
                                    @if ($item->notes)<div class="pl-6 text-sm font-semibold text-amber-300">“{{ $item->notes }}”</div>@endif
                                </li>
                            @endforeach
                            @if ($ticket->notes)<li class="py-1.5 text-sm font-semibold text-amber-300">{{ $ticket->notes }}</li>@endif
                        </ul>
                        @if ($canBump)
                            <footer class="grid grid-cols-2 gap-1 p-2">
                                @if ($ticket->status === \App\Modules\Kitchen\Enums\TicketStatus::New)
                                    <button type="button" wire:click="start('{{ $ticket->id }}')" class="h-12 rounded-xl bg-sky-500 font-bold text-slate-950 hover:bg-sky-400">{{ __('kitchen::kitchen.start') }}</button>
                                @else
                                    <span class="flex h-12 items-center justify-center rounded-xl bg-slate-800 text-sm font-semibold text-sky-300">{{ __('kitchen::kitchen.status.in_progress') }}</span>
                                @endif
                                <button type="button" wire:click="done('{{ $ticket->id }}')" class="h-12 rounded-xl bg-emerald-500 font-bold text-slate-950 hover:bg-emerald-400">{{ __('kitchen::kitchen.done') }}</button>
                            </footer>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </div>

    </div>

    @if ($done->isNotEmpty())
        <div class="flex shrink-0 items-center gap-2 overflow-x-auto border-t border-slate-800 bg-slate-900 px-3 py-2">
            <span class="shrink-0 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('kitchen::kitchen.recent') }}</span>
            @foreach ($done as $ticket)
                <button type="button" wire:click="recall('{{ $ticket->id }}')" @disabled(! $canBump) class="flex h-11 shrink-0 items-center gap-2 rounded-xl bg-slate-800 px-3 text-sm font-semibold hover:bg-slate-700">
                    #{{ $ticket->displayNumber() }} <span class="text-slate-400">{{ $ticket->done_at?->format('H:i') }}</span> <span class="text-emerald-400">↩ {{ __('kitchen::kitchen.recall') }}</span>
                </button>
            @endforeach
        </div>
    @endif
</div>
