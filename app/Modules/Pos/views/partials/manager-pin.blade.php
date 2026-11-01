{{-- Manager PIN keypad modal. Expects $approval (array|null) on the component. --}}
@if ($approval)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 p-4" x-data="{ pin: '' }" wire:key="approval">
        <div class="w-full max-w-sm rounded-3xl bg-slate-900 p-5 ring-1 ring-amber-400/50">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-lg font-bold text-amber-300">{{ __('pos::pos.approval.title') }}</h2>
                    <p class="text-sm text-slate-300">{{ $approval['title'] }}</p>
                </div>
                <button type="button" wire:click="cancelApproval" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
            </div>
            <div class="my-4 flex h-14 items-center justify-center gap-3 rounded-2xl bg-slate-800" aria-live="polite">
                <template x-for="i in 6"><span class="inline-block h-4 w-4 rounded-full" :class="pin.length >= i ? 'bg-amber-400' : 'bg-slate-600'"></span></template>
            </div>
            @if ($approval['error'])<p class="mb-3 rounded-xl bg-rose-500/15 px-3 py-2 text-center text-sm font-semibold text-rose-300" role="alert">{{ $approval['error'] }}</p>@endif
            <div class="grid grid-cols-3 gap-2">
                @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                    <button type="button" @click="if (pin.length < 6) pin += '{{ $digit }}'" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700">{{ $digit }}</button>
                @endforeach
                <button type="button" @click="pin = ''" class="h-14 rounded-2xl bg-slate-800 text-sm font-semibold text-slate-300 hover:bg-slate-700">{{ __('pos::pos.login.clear') }}</button>
                <button type="button" @click="if (pin.length < 6) pin += '0'" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700">0</button>
                <button type="button" @click="pin = pin.slice(0, -1)" class="h-14 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700" aria-label="Backspace">⌫</button>
            </div>
            <button type="button" @click="if (pin.length >= 4) { $wire.approve(pin); pin = '' }" :disabled="pin.length < 4" class="mt-3 h-14 w-full rounded-2xl bg-amber-400 text-lg font-bold text-slate-950 hover:bg-amber-300 disabled:opacity-40">{{ __('pos::pos.approval.approve') }}</button>
        </div>
    </div>
@endif
