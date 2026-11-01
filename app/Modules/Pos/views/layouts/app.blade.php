<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('pos::pos.title') }} · {{ __('rms.product_name') }}</title>
    <link rel="icon" href="{{ asset(config('rms.brand.favicon')) }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // Browser-print agent: this till prints "browser" print jobs through a hidden frame (CLAUDE.md §3.8).
        function browserPrintAgent(nextUrl, token) {
            return {
                busy: false,
                start() { setInterval(() => this.poll(), 8000); },
                async poll() {
                    if (this.busy) return;
                    try {
                        const res = await fetch(nextUrl, { headers: { 'Accept': 'application/json' } });
                        if (!res.ok) return;
                        const data = await res.json();
                        if (!data.job) return;
                        this.busy = true;
                        const frame = document.createElement('iframe');
                        frame.style.cssText = 'position:fixed;width:0;height:0;border:0;opacity:0;';
                        frame.src = data.job.url;
                        frame.onload = async () => {
                            await fetch(data.job.url.split('/print/jobs/')[0] + '/print/jobs/' + data.job.id + '/printed', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } });
                            setTimeout(() => { frame.remove(); this.busy = false; }, 4000);
                        };
                        document.body.appendChild(frame);
                    } catch (e) { this.busy = false; }
                },
            };
        }
    </script>
</head>
<body class="h-full overflow-hidden bg-slate-950 text-slate-100 antialiased select-none touch-manipulation"
      @auth x-data="browserPrintAgent('{{ route('printing.next') }}', '{{ csrf_token() }}')" x-init="start()" @endauth>
    <div class="flex h-full flex-col">
        @if ($chrome ?? true)
            <header class="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-slate-800 bg-slate-900 px-3">
                <div class="flex items-center gap-3">
                    <a href="{{ route('pos.home') }}" class="flex h-11 items-center gap-2 rounded-xl px-3 text-base font-semibold hover:bg-slate-800">
                        <img src="{{ asset(config('rms.brand.mark')) }}" alt="" class="h-8 w-8">
                        <span class="hidden sm:inline">{{ \App\Support\Tenancy\Tenancy::branch()?->name ?? __('rms.product_name') }}</span>
                    </a>
                </div>
                <div class="flex min-w-0 items-center gap-2 overflow-x-auto [&>*]:shrink-0">
                    @auth
                        <span class="hidden rounded-xl bg-slate-800 px-3 py-2 text-sm text-slate-300 md:inline">{{ auth()->user()->name }}@if ($till = app(\App\Modules\Pos\Services\CurrentTill::class)->get()) · {{ $till->name }}@endif</span>
                        @can(\App\Modules\Staff\Enums\Permission::StaffClock->value)
                            @php($clockedIn = auth()->user()->openTimeEntry())
                            <form method="post" action="{{ route('pos.clock') }}">@csrf
                                <button type="submit" class="flex h-11 items-center rounded-xl px-4 text-sm font-semibold {{ $clockedIn ? 'bg-amber-400/20 text-amber-200 hover:bg-amber-400/30' : 'bg-slate-800 hover:bg-slate-700' }}" title="{{ $clockedIn ? __('staff::staff.clock.since', ['time' => $clockedIn->clocked_in_at->format('H:i')]) : '' }}">{{ $clockedIn ? __('staff::staff.clock.nav_out') : __('staff::staff.clock.nav_in') }}</button>
                            </form>
                        @endcan
                        @can(\App\Modules\Staff\Enums\Permission::CashDrawer->value)
                            <form method="post" action="{{ route('pos.drawer') }}">@csrf
                                <button type="submit" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700" title="{{ __('pos::pos.drawer.hint') }}">{{ __('pos::pos.drawer.open') }}</button>
                            </form>
                        @endcan
                        @can(\App\Modules\Staff\Enums\Permission::KitchenView->value)
                            <a href="{{ route('kitchen.display') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('kitchen::kitchen.title') }}</a>
                        @endcan
                        @can(\App\Modules\Staff\Enums\Permission::OrdersCreate->value)
                            <a href="{{ route('pos.orders') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.nav.orders') }}</a>
                            <a href="{{ route('pos.deliveries') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.delivery.nav') }}</a>
                        @endcan
                        @can(\App\Modules\Staff\Enums\Permission::DeliveryRide->value)
                            <a href="{{ route('pos.rider') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.delivery.rider_nav') }}</a>
                        @endcan
                        @canany([\App\Modules\Staff\Enums\Permission::PaymentsTake->value, \App\Modules\Staff\Enums\Permission::CashDrawer->value, \App\Modules\Staff\Enums\Permission::DayClose->value])
                            <a href="{{ route('pos.cash') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.nav.cash') }}</a>
                        @endcanany
                        @can(\App\Modules\Staff\Enums\Permission::CustomersManage->value)
                            <a href="{{ route('pos.customers') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('customers::customers.pos.nav') }}</a>
                        @endcan
                        @canany([\App\Modules\Staff\Enums\Permission::InventoryCount->value, \App\Modules\Staff\Enums\Permission::InventoryManage->value])
                            <a href="{{ route('pos.stock-take') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('inventory::inventory.stock_take.phone.nav') }}</a>
                        @endcanany
                        @can(\App\Modules\Staff\Enums\Permission::ReportsView->value)
                            <a href="{{ route('pos.today') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.nav.today') }}</a>
                        @endcan
                        <form method="post" action="{{ route('pos.logout') }}">@csrf
                            <button type="submit" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('pos::pos.nav.lock') }}</button>
                        </form>
                    @endauth
                </div>
            </header>
        @endif
        @auth
            @if ($chrome ?? true)
                <livewire:printing.printer-problems />
            @endif
        @endauth
        @if (session('status'))<p class="bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" role="status">{{ session('status') }}</p>@endif
        @if (session('error'))<p class="bg-rose-600 px-4 py-2 text-sm font-semibold text-white" role="alert">{{ session('error') }}</p>@endif
        <main class="min-h-0 flex-1">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
