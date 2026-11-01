@php($b = fn (string $k, array $r = []) => __('settings::settings.backups.'.$k, $r))
@php($list = $this->backups())
<x-filament-panels::page>
    <style>
        .bk-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
        .bk-table th, .bk-table td { padding: 8px 6px; border-bottom: 1px solid rgba(128,128,128,.25); text-align: left; }
        .bk-table td.r, .bk-table th.r { text-align: right; }
        .bk-actions { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
        .bk-link { text-decoration: underline; cursor: pointer; font-size: .85rem; }
    </style>
    <x-filament::section>
        <p class="text-sm text-gray-500">{{ $b('intro') }} {{ $this->nextRun() }}</p>
        <p class="text-sm text-gray-500" style="margin-top:6px">{{ $b('usb_hint') }}</p>
    </x-filament::section>
    <x-filament::section :heading="$b('list')">
        @if ($list === [])
            <p class="text-sm text-gray-500">{{ $b('empty') }}</p>
        @else
            <table class="bk-table">
                <thead><tr><th>{{ $b('file') }}</th><th>{{ $b('taken') }}</th><th class="r">{{ $b('size') }}</th><th></th></tr></thead>
                <tbody>
                    @foreach ($list as $backup)
                        <tr wire:key="bk-{{ $backup['name'] }}">
                            <td><strong>{{ $backup['name'] }}</strong></td>
                            <td>{{ $backup['created_at']->translatedFormat('D j M Y H:i') }}</td>
                            <td class="r">{{ round($backup['size'] / 1024 / 1024, 2) }} MB</td>
                            <td class="bk-actions">
                                <a class="bk-link" href="{{ route('settings.backups.download', ['file' => $backup['name']]) }}">{{ $b('download') }}</a>
                                @if (auth()->user()->hasRole(\App\Modules\Staff\Enums\Role::Owner->value))
                                    <span class="bk-link" style="color:#dc2626" wire:click="mountAction('restore', { file: '{{ $backup['name'] }}' })">{{ $b('restore') }}</span>
                                @endif
                                <span class="bk-link" wire:click="mountAction('delete', { file: '{{ $backup['name'] }}' })">{{ __('filament-actions::delete.single.label') }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
