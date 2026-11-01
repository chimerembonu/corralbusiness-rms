@php($c = fn (string $k) => __('staff::staff.sales.columns.'.$k))
@php($t = fn (string $k) => __('staff::staff.timesheets.'.$k))
@php($rows = $this->rows())
<x-filament-panels::page>
    <style>
        .ts-filters { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
        .ts-filters label { display: block; font-size: .75rem; opacity: .7; margin-bottom: 2px; }
        .ts-table { width: 100%; font-size: .9rem; border-collapse: collapse; }
        .ts-table th, .ts-table td { padding: 8px 6px; border-bottom: 1px solid rgba(128,128,128,.25); text-align: left; }
        .ts-table th.r, .ts-table td.r { text-align: right; }
    </style>
    <x-filament::section>
        <div class="ts-filters">
            <div><label>{{ $t('from') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
            <div><label>{{ $t('to') }}</label><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
            <x-filament::button color="gray" wire:click="preset('today')">{{ __('staff::staff.sales.today') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="preset('this_week')">{{ $t('this_week') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="preset('last_week')">{{ $t('last_week') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="preset('this_month')">{{ $t('this_month') }}</x-filament::button>
        </div>
        <table class="ts-table" style="margin-top:12px">
            <thead><tr><th>{{ $c('staff') }}</th><th>{{ $c('role') }}</th><th class="r">{{ $c('orders') }}</th><th class="r">{{ $c('sales') }}</th><th class="r">{{ $c('average') }}</th><th class="r">{{ $c('voids') }}</th><th class="r">{{ $c('payments') }}</th><th class="r">{{ $c('taken') }}</th><th class="r">{{ $c('tips') }}</th></tr></thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td><strong>{{ $r['user']->name }}</strong></td>
                        <td>{{ $r['user']->rolesLabel() }}</td>
                        <td class="r">{{ $r['orders'] }}</td>
                        <td class="r"><strong>{{ money($r['sales']) }}</strong></td>
                        <td class="r">{{ money($r['average']) }}</td>
                        <td class="r">{{ $r['voids'] }}</td>
                        <td class="r">{{ $r['payments'] }}</td>
                        <td class="r">{{ money($r['taken']) }}</td>
                        <td class="r">{{ money($r['tips']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-gray-500">{{ __('staff::staff.sales.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
