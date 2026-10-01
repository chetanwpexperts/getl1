// Installable app: registers the service worker and runs the "Install app" buttons.
// Only runs on pages that link the manifest (login, app and admin pages), not on the public website.

// How to install, per browser, when the browser doesn't hand us a one-click prompt. Static text only.
function steps() {
    const ua = navigator.userAgent;
    const ios = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios) return 'In Safari, tap <b>Share</b> (the square with an arrow), then <b>Add to Home Screen</b>.';
    if (/firefox|fxios/i.test(ua)) return 'Firefox can’t install apps. Open this page in <b>Chrome</b>, <b>Edge</b> or <b>Safari</b> to install.';
    if (/edg\//i.test(ua)) return 'Open the menu <b>⋯</b> at the top right, then <b>Apps → Install this site as an app</b>.';
    if (/samsungbrowser/i.test(ua)) return 'Open the menu <b>☰</b>, then <b>Add page to → Home screen</b>.';
    if (/android/i.test(ua)) return 'Open the Chrome menu <b>⋮</b>, then <b>Add to Home screen</b> or <b>Install app</b>.';
    if (/chrome|crios/i.test(ua)) return 'Click the install icon at the right end of the address bar, or open the menu <b>⋮</b> → <b>Cast, save and share</b> → <b>Install page as app</b>.';
    if (/safari/i.test(ua) && /macintosh/i.test(ua)) return 'In Safari, open the <b>File</b> menu (or Share), then <b>Add to Dock</b>.';
    return 'Open your browser menu and choose <b>Install</b> or <b>Add to Home Screen</b>.';
}

export function initPwa() {
    if (!document.querySelector('link[rel="manifest"]')) return;

    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => { /* the app works without it */ });
    }

    const slots = document.querySelectorAll('[data-install-app]');
    const installed = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (!slots.length || installed) return;

    let prompt = null;
    window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); prompt = e; });
    window.addEventListener('appinstalled', () => { prompt = null; slots.forEach((s) => { s.hidden = true; }); });

    const text = steps();
    slots.forEach((slot) => {
        slot.querySelectorAll('[data-install-steps]').forEach((el) => { el.innerHTML = text; });
        slot.hidden = false;
        const tip = slot.querySelector('[data-install-tip]');

        slot.querySelector('[data-install-button]')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (prompt) {
                const p = prompt;
                prompt = null;
                p.prompt();
                await p.userChoice.catch(() => null);
                return;
            }
            if (tip) tip.hidden = !tip.hidden;
        });
        // Close the steps when clicking elsewhere (dropdown variants).
        document.addEventListener('click', (e) => { if (tip && !slot.contains(e.target) && slot.querySelector('[data-install-tip].absolute')) tip.hidden = true; });
    });
}
