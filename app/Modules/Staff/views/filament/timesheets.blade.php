@php($t = fn (string $k, array $r = []) => __('staff::staff.timesheets.'.$k, $r))
@php($c = fn (string $k) => __('staff::staff.timesheets.columns.'.$k))
@php($h = fn (int $m) => \App\Modules\Staff\Models\TimeEntry::hoursLabel($m))
@php($rows = $this->rows())
@php($in = $this->whoIsIn())
<x-filament-panels::page>
    <style>
        .ts-filters { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
        .ts-filters label { display: block; font-size: .75rem; opacity: .7; margin-bottom: 2px; }
        .ts-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
        .ts-table th, .ts-table td { padding: 8px 6px; border-bottom: 1px solid rgba(128,128,128,.25); text-align: left; vertical-align: top; }
        .ts-table th.r, .ts-table td.r { text-align: right; }
        .ts-sub td { font-size: .82rem; background: rgba(128,128,128,.06); }
        .ts-neg { color: #dc2626; } .ts-pos { color: #16a34a; }
        .ts-pill { display: inline-block; border-radius: 999px; padding: 1px 8px; font-size: .72rem; background: rgba(245,158,11,.2); color: #b45309; }
        .ts-link { text-decoration: underline; cursor: pointer; font-size: .8rem; }
    </style>

    <x-filament::section :heading="$t('who_is_in')">
        @if ($in->isEmpty())
            <p class="text-sm text-gray-500">{{ $t('nobody') }}</p>
        @else
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @foreach ($in as $entry)
                    <span class="ts-pill" style="font-size:.85rem;padding:4px 12px">{{ $entry->user?->name }} · {{ __('staff::staff.clock.since', ['time' => $entry->clocked_in_at->format('H:i')]) }}</span>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <div class="ts-filters">
            <div><label>{{ $t('from') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
            <div><label>{{ $t('to') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
            <x-filament::button color="gray" wire:click="preset('this_week')">{{ $t('this_week') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="preset('last_week')">{{ $t('last_week') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="preset('this_month')">{{ $t('this_month') }}</x-filament::button>
        </div>

        <table class="ts-table" style="margin-top:12px">
            <thead><tr><th>{{ $c('staff') }}</th><th>{{ $c('role') }}</th><th class="r">{{ $c('days') }}</th><th class="r">{{ $c('scheduled') }}</th><th class="r">{{ $c('worked') }}</th><th class="r">{{ $c('difference') }}</th><th></th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    @php($diff = $row['worked_minutes'] - $row['scheduled_minutes'])
                    <tr wire:key="ts-{{ $row['user']->id }}">
                        <td><strong>{{ $row['user']->name }}</strong> @if ($row['open'])<span class="ts-pill">{{ $t('still_in') }}</span>@endif</td>
                        <td>{{ $row['user']->rolesLabel() }}</td>
                        <td class="r">{{ $row['days'] }}</td>
                        <td class="r">{{ $h($row['scheduled_minutes']) }}</td>
                        <td class="r"><strong>{{ $h($row['worked_minutes']) }}</strong></td>
                        <td class="r {{ $diff < 0 ? 'ts-neg' : 'ts-pos' }}">{{ $diff < 0 ? '-' : '+' }}{{ $h(abs($diff)) }}</td>
                        <td class="r" style="white-space:nowrap">
                            <span class="ts-link" wire:click="toggle('{{ $row['user']->id }}')">{{ in_array($row['user']->id, $expanded, true) ? $t('hide') : $t('show') }}</span>
                            · <span class="ts-link" wire:click="mountAction('addEntry', { user: '{{ $row['user']->id }}' })">{{ $t('add_entry') }}</span>
                        </td>
                    </tr>
                    @if (in_array($row['user']->id, $expanded, true))
                        @foreach ($row['entries'] as $entry)
                            <tr class="ts-sub" wire:key="te-{{ $entry->id }}">
                                <td colspan="2" style="padding-left:24px">{{ $entry->clocked_in_at->translatedFormat('D j M') }} · {{ $entry->clocked_in_at->format('H:i') }} → {{ $entry->clocked_out_at?->format('H:i') ?? $t('still_in') }}@if ($entry->break_minutes) · {{ $c('break') }} {{ $entry->break_minutes }}@endif</td>
                                <td colspan="2">{{ $entry->source->label() }}@if ($entry->till) · {{ $entry->till->name }}@endif @if ($entry->editedBy)<br><em>{{ $t('edited', ['name' => $entry->editedBy->name, 'reason' => $entry->edit_reason]) }}</em>@endif @if ($entry->notes)<br><em>{{ $entry->notes }}</em>@endif</td>
                                <td class="r">{{ $h($entry->workedMinutes()) }}</td>
                                <td></td>
                                <td class="r"><span class="ts-link" wire:click="mountAction('editEntry', { entry: '{{ $entry->id }}' })">{{ $t('edit_entry') }}</span></td>
                            </tr>
                        @endforeach
                    @endif
                @empty
                    <tr><td colspan="7" class="text-gray-500">{{ $t('empty') }}</td></tr>
                @endforelse
            </tbody>
            @if ($rows->isNotEmpty())
                <tfoot><tr><th colspan="3">{{ $t('total') }}</th><th class="r">{{ $h($rows->sum('scheduled_minutes')) }}</th><th class="r">{{ $h($rows->sum('worked_minutes')) }}</th><th></th><th></th></tr></tfoot>
            @endif
        </table>
    </x-filament::section>
</x-filament-panels::page>
