@php($i = fn (string $k, array $r = []) => __('settings::settings.import.'.$k, $r))
<x-filament-panels::page>
    <style>
        .im-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
        .im-table th, .im-table td { padding: 6px; border-bottom: 1px solid rgba(128,128,128,.25); text-align: left; vertical-align: top; }
        .im-pill { display: inline-block; border-radius: 999px; padding: 1px 8px; font-size: .72rem; font-weight: 600; }
        .im-new { background: rgba(16,185,129,.2); color: #047857; } .im-update { background: rgba(59,130,246,.2); color: #1d4ed8; }
        .im-error { background: rgba(220,38,38,.2); color: #b91c1c; } .im-skip { background: rgba(128,128,128,.2); }
    </style>
    <x-filament::section>
        <p class="text-sm text-gray-500" style="margin-bottom:12px">{{ $i('intro') }}</p>
        <form wire:submit="previewFile">
            {{ $this->form }}
            <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                <x-filament::button type="submit" color="gray">{{ $i('preview') }}</x-filament::button>
                <x-filament::button type="button" wire:click="importFile" wire:confirm="{{ $i('confirm') }}">{{ $i('import') }}</x-filament::button>
            </div>
        </form>
    </x-filament::section>

    @if ($preview !== null)
        <x-filament::section :heading="$summary">
            <table class="im-table">
                <thead><tr><th>{{ $i('columns.row') }}</th><th>{{ $i('columns.action') }}</th><th>{{ $i('columns.what') }}</th><th>{{ $i('columns.detail') }}</th></tr></thead>
                <tbody>
                    @foreach ($preview as $row)
                        <tr><td>{{ $row['row'] }}</td><td><span class="im-pill im-{{ $row['action'] }}">{{ $i('actions.'.$row['action']) }}</span></td><td><strong>{{ $row['label'] }}</strong></td><td>{{ $row['message'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
