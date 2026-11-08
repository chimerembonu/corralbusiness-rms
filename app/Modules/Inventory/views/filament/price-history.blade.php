@php($c = fn (string $k, array $r = []) => __('inventory::inventory.costs.'.$k, $r))
@php($unit = $ingredient->unit->short())
<div style="display: flex; flex-direction: column; gap: 12px; font-size: .9rem">
    <p>
        {{ $c('now', ['cost' => money($ingredient->cost_per_unit), 'unit' => $unit]) }}
        @if ($last) · {{ $c('last_paid', ['price' => money($last), 'unit' => $unit]) }} @endif
    </p>
    <p style="opacity: .7; font-size: .8rem">{{ $c(app(\App\Modules\Inventory\Services\CostService::class)->averaging() ? 'method_average' : 'method') }}</p>
    @if ($changes->isEmpty())
        <p style="opacity: .7">{{ $c('none') }}</p>
    @else
        <div style="overflow-x: auto">
            <table style="width: 100%; border-collapse: collapse">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid rgba(128,128,128,.3)">
                        <th style="padding: 6px">{{ $c('columns.when') }}</th>
                        <th style="padding: 6px">{{ $c('columns.what') }}</th>
                        <th style="padding: 6px; text-align: right">{{ $c('columns.paid') }}</th>
                        <th style="padding: 6px; text-align: right">{{ $c('columns.cost') }}</th>
                        <th style="padding: 6px">{{ $c('columns.by') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($changes as $change)
                        <tr style="border-bottom: 1px solid rgba(128,128,128,.15); vertical-align: top">
                            <td style="padding: 6px; white-space: nowrap">{{ $change->happened_at->format('d M Y H:i') }}</td>
                            <td style="padding: 6px">
                                {{ $change->source->label() }}
                                @if ($change->purchase)
                                    <span style="opacity: .7">· {{ $change->purchase->displayNumber() }}{{ $change->purchase->supplier ? ' · '.$change->purchase->supplier->name : '' }}</span>
                                @endif
                                @if ($change->quantity !== null)
                                    <span style="opacity: .7">· {{ \App\Modules\Inventory\Services\StockService::qty((float) $change->quantity) }} {{ $unit }}</span>
                                @endif
                                @if ($change->reason)<div style="opacity: .7; font-size: .8rem">{{ $change->reason }}</div>@endif
                            </td>
                            <td style="padding: 6px; text-align: right; white-space: nowrap">{{ $change->price_paid ? money($change->price_paid) : '—' }}</td>
                            <td style="padding: 6px; text-align: right; white-space: nowrap">{{ money($change->old_cost) }} → <strong>{{ money($change->new_cost) }}</strong></td>
                            <td style="padding: 6px">{{ $change->user?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
