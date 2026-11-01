<div class="h-full overflow-y-auto p-4">
    <div class="mb-4 flex items-center justify-between">
        <h1 class="text-2xl font-bold">{{ __('pos::pos.today.title') }} <span class="text-base font-normal text-slate-400">{{ \Carbon\Carbon::parse($summary['date'])->translatedFormat('l j F Y') }}</span></h1>
        <a href="{{ route('pos.home') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">← {{ __('pos::pos.nav.new_order') }}</a>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([
            ['gross', $summary['gross_sales'], 'text-emerald-400'],
            ['net', $summary['net_sales'], ''],
            ['tax', $summary['tax_total'], ''],
            ['average', $summary['average_order'], ''],
        ] as [$key, $value, $color])
            <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.'.$key) }}</div>
                <div class="mt-1 text-2xl font-black {{ $color }}">{{ money($value) }}</div>
            </div>
        @endforeach
        <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.orders') }}</div>
            <div class="mt-1 text-2xl font-black">{{ $summary['orders_paid'] }} <span class="text-sm font-medium text-slate-400">· {{ $summary['orders_open'] }} {{ strtolower(__('pos::pos.today.open')) }} · {{ $summary['orders_void'] }} {{ strtolower(__('pos::pos.today.void')) }}</span></div>
        </div>
        <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.discounts') }}</div>
            <div class="mt-1 text-2xl font-black text-amber-300">{{ money($summary['discount_total']) }}</div>
        </div>
        <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <div class="text-xs uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.extras') }}</div>
            <div class="mt-1 text-sm"><span class="text-slate-400">{{ __('orders::orders.service_charge') }}</span> <span class="font-bold">{{ money($summary['service_charge_total']) }}</span></div>
            <div class="text-sm"><span class="text-slate-400">{{ __('orders::orders.delivery_fee') }}</span> <span class="font-bold">{{ money($summary['delivery_fee_total']) }}</span></div>
            <div class="text-sm"><span class="text-slate-400">{{ __('orders::orders.tip') }}</span> <span class="font-bold">{{ money($summary['tip_total']) }}</span></div>
            <div class="text-sm"><span class="text-slate-400">{{ __('pos::pos.today.refunds') }}</span> <span class="font-bold text-rose-300">{{ money($summary['refund_total']) }}</span></div>
        </div>
        @foreach ($summary['tax_lines'] as $tax)
            <div class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $tax['name'] }}</div>
                <div class="mt-1 text-2xl font-black">{{ money($tax['amount']) }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-3">
        <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.by_type') }}</h2>
            @forelse ($summary['by_type'] as $row)
                <div class="flex justify-between py-1"><span>{{ \App\Modules\Orders\Enums\OrderType::from($row['type'])->label() }} <span class="text-slate-500">× {{ $row['count'] }}</span></span><span class="font-semibold">{{ money($row['total']) }}</span></div>
            @empty
                <p class="text-slate-500">{{ __('pos::pos.today.no_sales') }}</p>
            @endforelse
        </section>
        <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.by_method') }}</h2>
            @forelse ($summary['by_payment_method'] as $row)
                <div class="flex justify-between py-1"><span>{{ \App\Modules\Payments\Enums\PaymentMethod::from($row['method'])->label() }} <span class="text-slate-500">× {{ $row['count'] }}</span></span><span class="font-semibold">{{ money($row['total']) }}</span></div>
            @empty
                <p class="text-slate-500">{{ __('pos::pos.today.no_sales') }}</p>
            @endforelse
        </section>
        <section class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.top_items') }}</h2>
            @forelse ($summary['top_items'] as $row)
                <div class="flex justify-between py-1"><span>{{ $row['name'] }} <span class="text-slate-500">× {{ $row['quantity'] }}</span></span><span class="font-semibold">{{ money($row['total']) }}</span></div>
            @empty
                <p class="text-slate-500">{{ __('pos::pos.today.no_sales') }}</p>
            @endforelse
        </section>
    </div>

    <section class="mt-4 rounded-2xl bg-slate-900 ring-1 ring-slate-800">
        <h2 class="px-4 pt-4 text-sm font-semibold uppercase tracking-wide text-slate-400">{{ __('pos::pos.today.orders_list') }}</h2>
        <table class="mt-2 w-full text-sm">
            <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-2">{{ __('pos::pos.today.number') }}</th><th class="px-2 py-2">{{ __('pos::pos.today.time') }}</th><th class="px-2 py-2">{{ __('pos::pos.today.status') }}</th><th class="px-2 py-2 text-right">{{ __('pos::pos.today.total') }}</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-800">
                @foreach ($summary['orders'] as $order)
                    <tr>
                        <td class="px-4 py-2 font-semibold">#{{ $order->displayNumber() }} <span class="text-slate-500">{{ $order->table?->name ?? $order->type->label() }}</span></td>
                        <td class="px-2 py-2 text-slate-400">{{ $order->opened_at->format('H:i') }}</td>
                        <td class="px-2 py-2">{{ $order->status->label() }}</td>
                        <td class="px-2 py-2 text-right font-semibold">{{ money($order->total) }}</td>
                        <td class="px-2 py-2 text-right"><a href="{{ $order->isActive() ? route('pos.order', $order) : route('pos.receipt', $order) }}" class="inline-flex h-10 items-center rounded-lg bg-slate-800 px-3 font-semibold hover:bg-slate-700">{{ __('pos::pos.today.view') }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
