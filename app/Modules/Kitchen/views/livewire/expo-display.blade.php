<div class="flex h-full flex-col"
     x-data="{ live: false, now: Date.now(),
        init() {
            setInterval(() => this.now = Date.now(), 1000);
            const conn = window.Echo?.connector?.pusher?.connection;
            if (conn) { this.live = conn.state === 'connected'; conn.bind('state_change', s => this.live = s.current === 'connected'); }
        },
        age(sentAt) { const s = Math.max(0, Math.floor((this.now - sentAt) / 1000)); return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); } }">
    <div wire:poll.30s="refresh"></div>
    <template x-if="!live"><div wire:poll.4s="refresh"></div></template>

    <div class="flex items-center justify-between border-b border-slate-800 px-4 py-2 text-sm">
        <div class="flex items-center gap-3">
            <span class="text-lg font-bold">{{ __('kitchen::kitchen.expo') }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="live ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'" x-text="live ? '{{ __('kitchen::kitchen.live') }}' : '{{ __('kitchen::kitchen.polling') }}'"></span>
            <a href="{{ route('kitchen.display') }}" class="flex h-10 items-center rounded-xl bg-slate-800 px-3 text-sm font-bold hover:bg-slate-700">{{ __('kitchen::kitchen.title') }}</a>
        </div>
        <div class="text-slate-400">{{ $orders->count() }} {{ __('kitchen::kitchen.open_count') }}</div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto p-3">
        @if ($orders->isEmpty())
            <div class="flex h-full items-center justify-center text-2xl text-slate-600">{{ __('kitchen::kitchen.empty') }}</div>
        @else
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                @foreach ($orders as $o)
                    @php($waiting = $o['tickets']->filter(fn ($t) => $t->status !== \App\Modules\Kitchen\Enums\TicketStatus::Done))
                    <article wire:key="expo-{{ $o['order_id'] }}" class="flex flex-col overflow-hidden rounded-2xl bg-slate-900 ring-2 {{ $o['ready'] ? 'ring-emerald-500' : 'ring-slate-700' }}">
                        <header class="flex items-center justify-between px-3 py-2 {{ $o['ready'] ? 'bg-emerald-500 text-slate-950' : 'bg-slate-800' }}">
                            <div>
                                <span class="text-lg font-black">#{{ str_pad((string) $o['order_number'], 4, '0', STR_PAD_LEFT) }}</span>
                                <span class="ml-2 text-sm font-semibold">{{ $o['where'] ? __('pos::pos.order.table').' '.$o['where'] : \App\Modules\Orders\Enums\OrderType::from($o['order_type'])->label() }}</span>
                            </div>
                            <span class="font-mono text-base font-bold tabular-nums" x-text="age({{ $o['sent_at']->getTimestampMs() }})">{{ gmdate('i:s', (int) $o['sent_at']->diffInSeconds(now())) }}</span>
                        </header>
                        <div class="space-y-2 px-3 py-2">
                            @foreach ($o['tickets'] as $ticket)
                                @php($status = $ticket->status)
                                <div class="rounded-xl bg-slate-800/60 p-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold uppercase" style="color: {{ $ticket->station?->color ?? '#94a3b8' }}">{{ $ticket->station?->name ?? __('kitchen::kitchen.no_station') }}</span>
                                        <button type="button" @if ($canBump) wire:click="bumpTicket('{{ $ticket->id }}')" @endif class="rounded-full px-2 py-0.5 text-[11px] font-bold uppercase {{ match ($status->value) { 'done' => 'bg-emerald-500/20 text-emerald-300', 'in_progress' => 'bg-sky-500/20 text-sky-300', default => 'bg-amber-500/20 text-amber-300' } }}">{{ $status->label() }}</button>
                                    </div>
                                    <ul class="mt-1 text-sm">
                                        @foreach ($ticket->items as $item)
                                            <li class="{{ $item->is_voided ? 'line-through opacity-40' : ($item->is_held ? 'opacity-50' : '') }}"><span class="text-emerald-300">{{ $item->quantity }}×</span> {{ $item->name }}@if ($item->is_held) <span class="text-[10px] uppercase text-amber-300">{{ __('kitchen::kitchen.hold_course', ['number' => $item->course]) }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                        <footer class="mt-auto p-2">
                            @if ($o['ready'])
                                <button type="button" wire:click="serve('{{ $o['order_id'] }}')" @disabled(! $canBump) class="h-12 w-full rounded-xl bg-emerald-500 font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-40">✓ {{ __('kitchen::kitchen.served') }}</button>
                            @else
                                <div class="flex h-12 items-center justify-center rounded-xl bg-slate-800 text-sm font-semibold text-slate-300">{{ __('kitchen::kitchen.waiting', ['stations' => $waiting->map(fn ($t) => $t->station?->name ?? __('kitchen::kitchen.no_station'))->unique()->implode(', ')]) }}</div>
                            @endif
                        </footer>
                    </article>
                @endforeach
            </div>
        @endif
    </div>
</div>
