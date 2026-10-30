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
    <template x-if="!live"><div wire:poll.4s="refresh"></div></template>

    <div class="flex items-center justify-between border-b border-slate-800 px-4 py-2 text-sm">
        <div class="flex items-center gap-3">
            <span class="text-lg font-bold">{{ __('kitchen::kitchen.title') }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="live ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'" x-text="live ? '{{ __('kitchen::kitchen.live') }}' : '{{ __('kitchen::kitchen.polling') }}'"></span>
            <span class="text-slate-500">{{ __('kitchen::kitchen.keys') }}</span>
        </div>
        <div class="text-slate-400">{{ $tickets->count() }} {{ __('kitchen::kitchen.open_count') }}</div>
    </div>

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
                                <span class="text-sm font-semibold">{{ $ticket->table_name ? __('pos::pos.order.table').' '.$ticket->table_name : \App\Modules\Orders\Enums\OrderType::from($ticket->order_type)->label() }}</span>
                            </div>
                            <span class="font-mono text-lg font-bold tabular-nums" x-text="age({{ $ticket->sent_at->getTimestampMs() }})">{{ gmdate('i:s', $ticket->ageSeconds()) }}</span>
                        </header>
                        <ul class="flex-1 divide-y divide-slate-800 px-3 py-1 text-lg">
                            @foreach ($ticket->items as $item)
                                <li class="py-1.5 {{ $item->is_voided ? 'line-through opacity-40' : '' }}">
                                    <div class="font-bold"><span class="text-emerald-300">{{ $item->quantity }}×</span> {{ $item->name }}</div>
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
