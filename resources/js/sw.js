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
