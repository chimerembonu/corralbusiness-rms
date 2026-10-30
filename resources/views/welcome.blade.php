<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('rms.product_name') }}</title>
    <link rel="icon" href="{{ asset(config('rms.brand.favicon')) }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex min-h-screen max-w-4xl flex-col justify-center px-6 py-10">
        <div class="mb-8 flex flex-col items-center text-center">
            <img src="{{ asset(config('rms.brand.logo_dark')) }}" alt="{{ __('rms.product_name') }}" class="h-16 w-auto">
            <p class="mt-3 text-slate-400">{{ $branch ? $branch->tenant->name.' · '.$branch->name : __('rms.tagline') }}</p>
            <p class="mt-1 text-xs font-semibold uppercase tracking-wide {{ $databaseOk ? 'text-emerald-400' : 'text-rose-400' }}">● {{ $databaseOk ? __('rms.landing.status_ok') : __('rms.landing.status_db') }}</p>
        </div>

        <h1 class="sr-only">{{ __('rms.landing.title') }}</h1>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <a href="{{ url('pos') }}" class="flex min-h-36 flex-col justify-center rounded-3xl bg-emerald-500 p-6 text-slate-950 hover:bg-emerald-400">
                <span class="text-3xl font-black">{{ __('rms.landing.pos') }}</span>
                <span class="mt-1 text-base font-medium opacity-80">{{ __('rms.landing.pos_hint') }}</span>
            </a>
            <a href="{{ url('kitchen') }}" class="flex min-h-36 flex-col justify-center rounded-3xl bg-sky-500 p-6 text-slate-950 hover:bg-sky-400">
                <span class="text-3xl font-black">{{ __('rms.landing.kitchen') }}</span>
                <span class="mt-1 text-base font-medium opacity-80">{{ __('rms.landing.kitchen_hint') }}</span>
            </a>
            <a href="{{ url(config('rms.admin_path')) }}" class="flex min-h-36 flex-col justify-center rounded-3xl bg-amber-400 p-6 text-slate-950 hover:bg-amber-300">
                <span class="text-3xl font-black">{{ __('rms.landing.admin') }}</span>
                <span class="mt-1 text-base font-medium opacity-80">{{ __('rms.landing.admin_hint') }}</span>
            </a>
            <a href="{{ route('clock-in') }}" class="flex min-h-36 flex-col justify-center rounded-3xl bg-slate-800 p-6 text-slate-100 ring-1 ring-slate-700 hover:bg-slate-700">
                <span class="text-3xl font-black">{{ __('rms.landing.clock_in') }}</span>
                <span class="mt-1 text-base font-medium text-slate-400">{{ __('rms.landing.clock_in_hint') }} · {{ __('rms.landing.coming') }}</span>
            </a>
        </div>

        <p class="mt-8 text-center text-xs text-slate-600">{{ __('rms.product_name') }} v{{ config('rms.version') }} · {{ __('rms.company') }} · {{ config('app.timezone') }} · {{ config('rms.currency') }}</p>
    </main>
</body>
</html>
