// GetL1 service worker (served by PwaController at /sw.js).
//
// What it stores: the built CSS/JS (file names change on every build), the app icons and the
// offline page. Nothing else. Pages, auction data, documents and anything behind a login are
// always fetched live and never written to the device.
const VERSION = '__VERSION__';
const CACHE = `getl1-${VERSION}`;
const OFFLINE = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll([OFFLINE, '/icons/icon-192.png']))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('getl1-') && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return; // Razorpay, Cloudflare, fonts: untouched

    // Pages: always from the network. Only when the network is down, show the offline page.
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
        return;
    }

    // Built assets and icons never change under the same name: serve from cache, fill on first use.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.match(req).then((hit) => hit || fetch(req).then((res) => {
                if (res.ok && res.type === 'basic') {
                    const copy = res.clone();
                    caches.open(CACHE).then((cache) => cache.put(req, copy));
                }
                return res;
            })),
        );
    }
    // Everything else (live auction updates, JSON, downloads): the browser handles it as usual.
});

// ------------------------------------------------------------------ device alerts (Web Push)
// The server sends {id, title, body, url}. Safari requires every push to show a notification, so it
// always does there. Elsewhere, if a GetL1 tab is in front it already shows the live pop-up, so the
// system notification is skipped. Clicks only ever open GetL1 pages.

const isSafari = /safari/i.test(self.navigator.userAgent) && !/chrome|chromium|crios|edg|android/i.test(self.navigator.userAgent);

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { data = {}; }
    const show = () => self.registration.showNotification(String(data.title || 'GetL1'), {
        body: String(data.body || ''),
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: data.id ? `getl1-${data.id}` : undefined,
        data: { url: typeof data.url === 'string' ? data.url : '/dashboard' },
    });
    if (isSafari) {
        event.waitUntil(show());
        return;
    }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((wins) => (wins.some((w) => w.focused && w.visibilityState === 'visible') ? undefined : show())),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    let target;
    try { target = new URL(event.notification.data?.url || '/dashboard', self.location.origin); } catch { target = new URL('/dashboard', self.location.origin); }
    if (target.origin !== self.location.origin) target = new URL('/dashboard', self.location.origin);
    // A new window, so an open auction or half-filled form is never replaced.
    event.waitUntil(self.clients.openWindow(target.href));
});
