{{-- Delivery details form. Binds to $delivery (name, phone, address, notes, fee). $submit / $close: component methods. --}}
<div class="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/80 p-4" wire:key="delivery-form">
    <div class="w-full max-w-lg rounded-3xl bg-slate-900 p-5 ring-1 ring-sky-500/40">
        <div class="flex items-center justify-between">
            <h2 class="text-xl font-bold text-sky-300">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="h-11 w-11 rounded-xl bg-slate-800 text-xl hover:bg-slate-700" aria-label="{{ __('pos::pos.order.close') }}">✕</button>
        </div>
        @if ($error)<p class="mt-2 rounded-xl bg-rose-500/15 px-3 py-2 text-sm font-semibold text-rose-300" role="alert">{{ $error }}</p>@endif
        @if ($notice ?? null)<p class="mt-2 rounded-xl bg-emerald-500/15 px-3 py-2 text-sm font-semibold text-emerald-300" role="status">{{ $notice }}</p>@endif
        <div class="mt-3 grid grid-cols-2 gap-3">
            <label class="block"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.delivery.name') }}</span>
                <input type="text" wire:model="delivery.name" class="mt-1 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100"></label>
            <label class="block"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.delivery.phone') }} *</span>
                <span class="mt-1 flex gap-2"><input type="tel" inputmode="tel" wire:model="delivery.phone" wire:keydown.enter="lookupDeliveryCustomer" class="h-12 w-full min-w-0 rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100">
                <button type="button" wire:click="lookupDeliveryCustomer" class="h-12 shrink-0 rounded-xl bg-slate-700 px-4 text-sm font-bold hover:bg-slate-600">{{ __('customers::customers.pos.find') }}</button></span></label>
            @if (count($deliveryAddresses ?? []) > 1)
                <div class="col-span-2"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('customers::customers.pos.saved_addresses') }}</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($deliveryAddresses as $i => $a)
                            <button type="button" wire:click="useDeliveryAddress({{ $i }})" class="h-11 rounded-xl bg-slate-800 px-3 text-left text-sm font-semibold hover:bg-slate-700 {{ ($delivery['address'] ?? '') === $a['address'] ? 'ring-1 ring-sky-400' : '' }}">{{ $a['label'] }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
            <label class="col-span-2 block"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.delivery.address') }} *</span>
                <textarea wire:model="delivery.address" rows="2" placeholder="{{ __('pos::pos.delivery.address_placeholder') }}" class="mt-1 w-full rounded-xl border-0 bg-slate-800 px-4 py-3 text-base text-slate-100 placeholder:text-slate-500"></textarea></label>
            <label class="block"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.delivery.notes') }}</span>
                <input type="text" wire:model="delivery.notes" placeholder="{{ __('pos::pos.delivery.notes_placeholder') }}" class="mt-1 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base text-slate-100 placeholder:text-slate-500"></label>
            <label class="block"><span class="text-xs uppercase tracking-wide text-slate-400">{{ __('orders::orders.delivery_fee') }} ({{ config('rms.currency') }})</span>
                <input type="text" inputmode="decimal" wire:model="delivery.fee" class="mt-1 h-12 w-full rounded-xl border-0 bg-slate-800 px-4 text-base font-bold text-slate-100"></label>
        </div>
        <button type="button" wire:click="{{ $submit }}" class="mt-4 h-14 w-full rounded-2xl bg-sky-500 text-lg font-bold text-slate-950 hover:bg-sky-400">{{ $button }}</button>
    </div>
</div>
