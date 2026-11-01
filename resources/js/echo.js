import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Connects browsers (KDS, POS) to the Laravel Reverb WebSocket server.
// If the socket drops, screens fall back to polling (handled per screen).
// On the store server the host is left empty: tills connect to whatever address they opened the app on
// (the server's LAN IP), and nginx forwards /reverb to the Reverb container on the same port.
const host = import.meta.env.VITE_REVERB_HOST || window.location.hostname;
const scheme = import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '');
const port = Number(import.meta.env.VITE_REVERB_PORT || (scheme === 'https' ? 443 : 80));
const path = import.meta.env.VITE_REVERB_PATH || '';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    wsPath: path,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
});
