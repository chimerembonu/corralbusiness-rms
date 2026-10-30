<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('rms.product_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex min-h-screen max-w-3xl flex-col justify-center px-6 py-12">
        <div class="mb-10 flex items-center gap-4">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500 text-3xl font-black text-slate-950">C</div>
            <div>
                <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">{{ __('rms.product_name') }}</h1>
                <p class="text-slate-400">{{ __('rms.tagline') }} · {{ __('rms.company') }}</p>
            </div>
        </div>

        <section class="rounded-2xl border border-slate-800 bg-slate-900 p-6 sm:p-8">
            <h2 class="text-xl font-semibold text-emerald-400">{{ __('rms.placeholder.title') }}</h2>
            <p class="mt-2 text-slate-400">{{ __('rms.placeholder.subtitle') }}</p>

            <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-800/60 p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ __('rms.placeholder.database') }}</dt>
                    <dd class="mt-1 text-lg font-semibold {{ $databaseOk ? 'text-emerald-400' : 'text-rose-400' }}">
                        {{ $databaseOk ? __('rms.placeholder.connected') : __('rms.placeholder.not_connected') }}
                    </dd>
                </div>
                <div class="rounded-xl bg-slate-800/60 p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ __('rms.placeholder.timezone') }}</dt>
                    <dd class="mt-1 text-lg font-semibold">{{ config('app.timezone') }}</dd>
                </div>
                <div class="rounded-xl bg-slate-800/60 p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ __('rms.placeholder.currency') }}</dt>
                    <dd class="mt-1 text-lg font-semibold">{{ config('rms.currency') }} ({{ config('rms.currency_symbol') }})</dd>
                </div>
                <div class="rounded-xl bg-slate-800/60 p-4">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">{{ __('rms.placeholder.version') }}</dt>
                    <dd class="mt-1 text-lg font-semibold">v{{ config('rms.version') }}</dd>
                </div>
            </dl>

            <div class="mt-6">
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ __('rms.placeholder.modules') }}</p>
                <ul class="mt-2 flex flex-wrap gap-2">
                    @foreach ($modules as $module)
                        <li class="rounded-full bg-slate-800 px-3 py-1 text-sm text-slate-300">{{ $module }}</li>
                    @endforeach
                </ul>
            </div>
        </section>

        <nav class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <a href="{{ url(config('rms.admin_path')) }}" class="flex min-h-14 items-center justify-center rounded-2xl bg-emerald-500 px-4 text-center text-lg font-semibold text-slate-950 hover:bg-emerald-400">{{ __('rms.placeholder.admin_link') }}</a>
            <span class="flex min-h-14 items-center justify-center rounded-2xl border border-slate-800 px-4 text-center text-slate-500">{{ __('rms.placeholder.pos_link') }} · {{ __('rms.placeholder.coming_soon') }}</span>
            <span class="flex min-h-14 items-center justify-center rounded-2xl border border-slate-800 px-4 text-center text-slate-500">{{ __('rms.placeholder.kitchen_link') }} · {{ __('rms.placeholder.coming_soon') }}</span>
        </nav>
    </main>
</body>
</html>
