import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Connects browsers (KDS, POS) to the Laravel Reverb WebSocket server.
// The settings come from the <meta name="rms-realtime"> tag the layouts print from config('rms.realtime'),
// so one compiled front-end works on the Mac, on the store server (nginx forwards /reverb) and in the cloud.
// Anything left empty means "same as the page": the address and port the till opened the app on.
// If the socket drops, screens fall back to polling (handled per screen).
const meta = document.querySelector('meta[name="rms-realtime"]');
const settings = meta ? JSON.parse(meta.content || '{}') : {};
const key = settings.key || import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    const scheme = settings.scheme || window.location.protocol.replace(':', '');
    const port = Number(settings.port || window.location.port || (scheme === 'https' ? 443 : 80));

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: settings.host || window.location.hostname,
        wsPort: port,
        wssPort: port,
        wsPath: settings.path || '',
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });
} else {
    console.warn('Realtime is off (no REVERB_APP_KEY); screens refresh by polling instead.');
}
