<div class="flex h-full items-center justify-center gap-6 p-4" x-data="{ pin: '', timer: null }"
     x-init="$watch('$wire.message', v => { if (v) { clearTimeout(timer); timer = setTimeout(() => { $wire.message = null; $wire.detail = null; }, 8000); } })">
    <div class="w-full max-w-sm rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
        <div class="mb-5 text-center">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-400 text-3xl font-black text-slate-950">⏱</div>
            <h1 class="text-2xl font-bold">{{ __('staff::staff.clock.title') }}</h1>
            <p class="text-sm text-slate-400">{{ __('staff::staff.clock.subtitle') }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums text-slate-300" x-data="{ now: new Date() }" x-init="setInterval(() => now = new Date(), 1000)" x-text="now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })"></p>
        </div>

        @if ($message)
            <div class="mb-4 rounded-2xl bg-emerald-500/15 px-4 py-3 text-center ring-1 ring-emerald-500/40" role="status">
                <p class="text-base font-bold text-emerald-300">{{ $message }}</p>
                @if ($detail)<p class="mt-1 text-sm text-emerald-200/80">{{ $detail }}</p>@endif
            </div>
        @endif
        @if ($error)
            <p class="mb-4 rounded-xl bg-rose-500/15 px-4 py-3 text-center text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>
        @endif

        <div class="mb-4 flex h-14 items-center justify-center gap-3 rounded-2xl bg-slate-800" aria-live="polite">
            <template x-for="i in 6"><span class="inline-block h-4 w-4 rounded-full" :class="pin.length >= i ? 'bg-amber-400' : 'bg-slate-600'"></span></template>
        </div>
        <div class="grid grid-cols-3 gap-3">
            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                <button type="button" @click="if (pin.length < 6) pin += '{{ $digit }}'" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-amber-500">{{ $digit }}</button>
            @endforeach
            <button type="button" @click="pin = ''" class="h-16 rounded-2xl bg-slate-800 text-sm font-semibold text-slate-300 hover:bg-slate-700">{{ __('pos::pos.login.clear') }}</button>
            <button type="button" @click="if (pin.length < 6) pin += '0'" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-amber-500">0</button>
            <button type="button" @click="pin = pin.slice(0, -1)" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700" aria-label="Backspace">⌫</button>
        </div>
        <button type="button" @click="if (pin.length >= 4) { $wire.punch(pin); pin = '' }" :disabled="pin.length < 4" class="mt-4 h-16 w-full rounded-2xl bg-amber-400 text-xl font-bold text-slate-950 hover:bg-amber-300 disabled:opacity-40">{{ __('staff::staff.clock.go') }}</button>
        <a href="{{ route('pos.login') }}" class="mt-4 block text-center text-sm text-slate-500 underline">{{ __('staff::staff.clock.back_to_login') }}</a>
    </div>

    <aside class="hidden w-72 self-stretch rounded-3xl border border-slate-800 bg-slate-900 p-5 md:block">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('staff::staff.clock.who_is_in') }} <span class="text-slate-500">({{ $this->whoIsIn->count() }})</span></h2>
        <ul class="mt-3 space-y-2" wire:poll.30s>
            @forelse ($this->whoIsIn as $entry)
                <li class="flex items-center justify-between rounded-xl bg-slate-800 px-3 py-2 text-sm"><span class="font-semibold">{{ $entry->user?->name }}</span><span class="text-slate-400">{{ __('staff::staff.clock.since', ['time' => $entry->clocked_in_at->format('H:i')]) }}</span></li>
            @empty
                <li class="text-sm text-slate-500">{{ __('staff::staff.clock.nobody_in') }}</li>
            @endforelse
        </ul>
    </aside>
</div>
