<div class="flex h-full items-center justify-center p-4" x-data="{ pin: '' }">
    <div class="w-full max-w-sm rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-500 text-3xl font-black text-slate-950">C</div>
            <h1 class="text-2xl font-bold">{{ __('pos::pos.login.title') }}</h1>
            <p class="text-sm text-slate-400">{{ __('pos::pos.login.subtitle') }}</p>
        </div>

        @if ($tills->isNotEmpty() && ! $till)
            <p class="mb-2 text-center text-sm font-semibold text-slate-300">{{ __('pos::pos.login.choose_till') }}</p>
            <div class="mb-4 grid grid-cols-2 gap-2">
                @foreach ($tills as $t)
                    <button type="button" wire:click="chooseTill('{{ $t->id }}')" class="h-14 rounded-2xl bg-slate-800 px-3 text-sm font-bold hover:bg-slate-700">{{ $t->name }}</button>
                @endforeach
            </div>
        @elseif ($till)
            <p class="mb-3 text-center text-sm text-slate-400">{{ __('pos::pos.login.this_till', ['till' => $till->name]) }} <button type="button" wire:click="changeTill" class="font-semibold text-emerald-400 underline">{{ __('pos::pos.login.change_till') }}</button></p>
        @endif

        <div class="mb-4 flex h-14 items-center justify-center gap-3 rounded-2xl bg-slate-800 text-3xl tracking-[0.5em]" aria-live="polite">
            <template x-for="i in 6"><span class="inline-block h-4 w-4 rounded-full" :class="pin.length >= i ? 'bg-emerald-400' : 'bg-slate-600'"></span></template>
        </div>

        @if ($error)
            <p class="mb-4 rounded-xl bg-rose-500/15 px-4 py-3 text-center text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>
        @endif

        <div class="grid grid-cols-3 gap-3">
            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                <button type="button" @click="if (pin.length < 6) pin += '{{ $digit }}'" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-emerald-600">{{ $digit }}</button>
            @endforeach
            <button type="button" @click="pin = ''" class="h-16 rounded-2xl bg-slate-800 text-sm font-semibold text-slate-300 hover:bg-slate-700">{{ __('pos::pos.login.clear') }}</button>
            <button type="button" @click="if (pin.length < 6) pin += '0'" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700 active:bg-emerald-600">0</button>
            <button type="button" @click="pin = pin.slice(0, -1)" class="h-16 rounded-2xl bg-slate-800 text-2xl font-semibold hover:bg-slate-700" aria-label="Backspace">⌫</button>
        </div>

        <button type="button" @click="if (pin.length >= 4) { $wire.login(pin); pin = '' }" :disabled="pin.length < 4" class="mt-4 h-16 w-full rounded-2xl bg-emerald-500 text-xl font-bold text-slate-950 disabled:opacity-40 hover:bg-emerald-400">
            {{ __('pos::pos.login.enter') }}
        </button>

        <p class="mt-4 text-center text-xs text-slate-500">{{ __('rms.product_name') }}</p>
    </div>
</div>
