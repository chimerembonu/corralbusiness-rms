<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('pos::pos.title') }} · {{ __('rms.product_name') }}</title>
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
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500 text-lg font-black text-slate-950">C</span>
                        <span class="hidden sm:inline">{{ \App\Support\Tenancy\Tenancy::branch()?->name ?? __('rms.product_name') }}</span>
                    </a>
                </div>
                <div class="flex items-center gap-2">
                    @auth
                        <span class="hidden rounded-xl bg-slate-800 px-3 py-2 text-sm text-slate-300 md:inline">{{ auth()->user()->name }}</span>
                        @can(\App\Modules\Staff\Enums\Permission::KitchenView->value)
                            <a href="{{ route('kitchen.display') }}" class="flex h-11 items-center rounded-xl bg-slate-800 px-4 text-sm font-semibold hover:bg-slate-700">{{ __('kitchen::kitchen.title') }}</a>
                        @endcan
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
        <main class="min-h-0 flex-1">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
