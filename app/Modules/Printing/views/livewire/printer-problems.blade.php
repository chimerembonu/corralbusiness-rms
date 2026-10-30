<div wire:poll.30s>
    @if ($failures->isNotEmpty())
        @php($last = $failures->first())
        <div class="flex items-center justify-between gap-3 bg-rose-600 px-4 py-2 text-sm text-white" role="alert">
            <div>
                <span class="font-bold">{{ __('printing::printing.banner.title') }}:</span>
                {{ __('printing::printing.banner.failed', ['count' => $failures->count(), 'printer' => $last->printer?->name, 'error' => $last->last_error]) }}
                <span class="opacity-80">{{ __('printing::printing.banner.worker_hint') }}</span>
            </div>
            <button type="button" wire:click="retryAll" class="h-10 shrink-0 rounded-lg bg-white/20 px-3 font-semibold hover:bg-white/30">{{ __('printing::printing.banner.retry_all') }}</button>
        </div>
    @endif
</div>
