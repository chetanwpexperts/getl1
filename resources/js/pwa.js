// Installable app: registers the service worker and shows "Install app" where the device supports it.
// Only runs on pages that link the manifest (login, app and admin pages), not on the public website.
export function initPwa() {
    if (!document.querySelector('link[rel="manifest"]')) return;

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => { /* app works without it */ });
        });
    }

    const slots = document.querySelectorAll('[data-install-app]');
    const installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (!slots.length || installed) return;

    const show = () => slots.forEach((s) => { s.hidden = false; });
    const hide = () => slots.forEach((s) => { s.hidden = true; });
    let prompt = null;

    // Chrome, Edge, Android: the browser hands us its install prompt.
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        prompt = e;
        show();
    });
    window.addEventListener('appinstalled', () => { prompt = null; hide(); });

    // iPhone / iPad Safari has no prompt: show how to add it to the home screen.
    const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios) show();

    slots.forEach((slot) => {
        slot.querySelector('[data-install-button]')?.addEventListener('click', async () => {
            if (prompt) {
                prompt.prompt();
                const { outcome } = await prompt.userChoice;
                prompt = null;
                if (outcome === 'accepted') hide();
                return;
            }
            const tip = slot.querySelector('[data-install-tip]');
            if (tip) tip.hidden = !tip.hidden;
        });
    });
}
