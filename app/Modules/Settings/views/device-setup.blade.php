@php($d = fn (string $key, array $r = []) => __('settings::settings.device.'.$key, $r))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $d('title') }} · {{ __('rms.product_name') }}</title>
    <link rel="icon" href="{{ asset(config('rms.brand.favicon')) }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-slate-950 text-slate-100 antialiased touch-manipulation">
<main class="mx-auto max-w-2xl px-4 py-8">
    <h1 class="text-2xl font-bold">{{ $d('title') }}</h1>

    @if (! $https)
        <p class="mt-4 text-slate-300">{{ $d('off') }}</p>
        <a href="{{ $next }}" class="mt-6 inline-flex min-h-12 items-center rounded-2xl bg-emerald-500 px-6 text-lg font-bold text-slate-950">{{ $d('continue') }}</a>
    @else
        <div id="checking" class="mt-4 rounded-2xl bg-slate-800 p-4 text-slate-200 {{ $secure ? 'hidden' : '' }}" aria-live="polite">{{ $d('checking') }}</div>
        <div id="trusted" class="mt-4 rounded-2xl bg-emerald-500/15 p-4 font-semibold text-emerald-300 {{ $secure ? '' : 'hidden' }}">{{ $secure ? $d('trusted_here') : $d('trusted') }}</div>

        <div id="steps" class="{{ $secure ? '' : 'hidden' }}">
            <p class="mt-4 text-slate-300">{{ $d('why') }}</p>

            <ol class="mt-6 space-y-6">
                <li class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                    <h2 class="text-lg font-semibold">1. {{ $d('step_download') }}</h2>
                    <a href="{{ route('device-setup.certificate') }}" class="mt-3 inline-flex min-h-12 items-center rounded-2xl bg-amber-400 px-6 text-lg font-bold text-slate-950">{{ $d('download') }}</a>
                    <p class="mt-3 text-sm text-slate-400">{{ $d('fingerprint') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-slate-300">{{ $fingerprint }}</p>
                </li>
                <li class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                    <h2 class="text-lg font-semibold">2. {{ $d('step_trust') }}</h2>
                    @foreach (['android', 'ios', 'windows', 'mac', 'chromebook', 'firefox'] as $kind)
                        <details class="mt-3 rounded-xl bg-slate-800/60 p-3" @if ($kind === $device) open @endif>
                            <summary class="min-h-12 cursor-pointer content-center font-semibold">{{ $d('devices.'.$kind.'.name') }}</summary>
                            <ol class="mt-2 list-decimal space-y-1 ps-6 text-slate-300">
                                @foreach ((array) __('settings::settings.device.devices.'.$kind.'.steps') as $step)
                                    <li>{{ $step }}</li>
                                @endforeach
                            </ol>
                        </details>
                    @endforeach
                </li>
                <li class="rounded-2xl bg-slate-900 p-4 ring-1 ring-slate-800">
                    <h2 class="text-lg font-semibold">3. {{ $d('step_open') }}</h2>
                    <button type="button" id="recheck" class="mt-3 inline-flex min-h-12 items-center rounded-2xl bg-emerald-500 px-6 text-lg font-bold text-slate-950">{{ $d('recheck') }}</button>
                    <p id="still" class="mt-3 hidden text-sm text-rose-300">{{ $d('not_yet') }}</p>
                </li>
            </ol>
            @if ($endsAt)
                <p class="mt-6 text-xs text-slate-500">{{ $d('ends', ['date' => $endsAt->format('d/m/Y')]) }}</p>
            @endif
        </div>

        <script>
            (function () {
                var target = @json($origin . $next);
                var probe = @json($origin . '/favicon.svg') + '?t=';
                var show = function (id, on) { document.getElementById(id).classList.toggle('hidden', ! on); };
                function check(manual) {
                    // A request to the secure address only succeeds once this device trusts the certificate.
                    fetch(probe + Date.now(), { mode: 'no-cors', cache: 'no-store' })
                        .then(function () { show('checking', false); show('trusted', true); window.location.replace(target); })
                        .catch(function () { show('checking', false); show('steps', true); show('still', manual); });
                }
                document.getElementById('recheck').addEventListener('click', function () { check(true); });
                @if (! $secure) check(false); @endif
            })();
        </script>
    @endif
</main>
</body>
</html>
