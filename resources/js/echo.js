// One shared Reverb connection per tab (auction screen and notifications use the same socket).
// Resolves to null when real-time isn't configured; callers then fall back to polling.
let pending = null;

export function getEcho() {
    if (pending) return pending;
    pending = (async () => {
        const key = import.meta.env.VITE_REVERB_APP_KEY;
        if (!key) return null;
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
            window.Pusher = Pusher;
            const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            return new Echo({
                broadcaster: 'reverb',
                key,
                wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
                wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
                wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/auth',
                auth: { headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } },
            });
        } catch {
            return null;
        }
    })();
    return pending;
}
