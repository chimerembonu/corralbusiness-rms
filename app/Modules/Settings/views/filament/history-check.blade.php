<x-filament-panels::page>
    @php($status = $this->status())
    <x-filament::section>
        @if ($status['checked_at'] === null)
            <p>{{ __('settings::settings.history.never_checked') }}</p>
        @elseif ($status['problems'] === [])
            <p class="font-semibold text-success-600 dark:text-success-400">✓ {{ __('settings::settings.history.all_good', ['count' => $status['checked']]) }}</p>
        @else
            <p class="font-semibold text-danger-600 dark:text-danger-400">{{ trans_choice('settings::settings.history.problems_found', count($status['problems']), ['count' => count($status['problems'])]) }}</p>
            <ul class="mt-2 list-disc ps-6 text-sm">
                @foreach (array_slice($status['problems'], 0, 50) as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
            <p class="mt-3 text-sm">{{ __('settings::settings.history.what_to_do') }}</p>
        @endif
        @if ($status['checked_at'])
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('settings::settings.history.last_checked', ['when' => $status['checked_at']]) }}</p>
        @endif
        <p class="mt-4 text-sm">{{ __('settings::settings.history.current_code', ['code' => $status['code']]) }}</p>
    </x-filament::section>
</x-filament-panels::page>
