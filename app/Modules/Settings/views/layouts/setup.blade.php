<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('settings::settings.setup.title') }} · {{ __('rms.product_name') }}</title>
    <link rel="icon" href="{{ asset(config('rms.brand.favicon')) }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-950 text-slate-100 antialiased touch-manipulation">
    {{ $slot }}
</body>
</html>
