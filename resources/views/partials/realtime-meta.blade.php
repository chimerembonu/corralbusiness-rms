{{-- Realtime (Reverb) settings for the browser; read by resources/js/echo.js. Empty values mean "same as this page". --}}
<meta name="rms-realtime" content="{{ json_encode(array_filter(config('rms.realtime', []), fn ($v) => $v !== null && $v !== '')) }}">
