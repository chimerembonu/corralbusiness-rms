<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('rms.landing.clock_in') }} · {{ __('rms.product_name') }}</title>
    <link rel="icon" href="{{ asset(config('rms.brand.favicon')) }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex min-h-screen max-w-xl flex-col items-center justify-center px-6 text-center">
        <img src="{{ asset(config('rms.brand.mark')) }}" alt="" class="h-16 w-16">
        <h1 class="mt-4 text-3xl font-black">{{ __('rms.landing.clock_in') }}</h1>
        <p class="mt-3 text-slate-400">{{ __('rms.landing.clock_in_soon') }}</p>
        <a href="{{ route('home') }}" class="mt-8 flex h-14 items-center rounded-2xl bg-emerald-500 px-6 text-lg font-bold text-slate-950 hover:bg-emerald-400">{{ __('rms.landing.back') }}</a>
    </main>
</body>
</html>
