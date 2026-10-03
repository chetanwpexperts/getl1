// Notifications: the bell, live pop-ups and device alerts (Web Push).
//
// - Live: listens on the person's private channel (user.{id}) over the shared Reverb socket.
//   When the socket is down it checks the server every 45 seconds instead, so nothing is missed.
// - Pop-ups are small cards at the bottom right; they never block the page and close by themselves.
// - Device alerts are only ever turned on by the person clicking "Turn on"; never asked on page load.
// - All text is written with textContent, never innerHTML.

import { getEcho } from './echo';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const json = (url, opts = {}) => fetch(url, {
    credentials: 'same-origin',
    ...opts,
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...(opts.headers || {}) },
}).then((r) => { if (!r.ok) throw new Error(String(r.status)); return r.json(); });

const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;
const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
}

export function initNotifications() {
    const bell = document.querySelector('[data-bell]');
    if (!bell) return;

    const countEl = bell.querySelector('[data-bell-count]');
    const list = bell.querySelector('[data-bell-list]');
    const known = new Set();
    const since = Number(bell.dataset.since) || Date.now(); // server time when this page was made
    let unread = Number(countEl.textContent.replace('+', '')) || 0;
    let socketUp = false;
    let pollTimer = null;
    let listLoaded = false;
    setUnread(unread);

    function setUnread(n) {
        unread = Math.max(0, n);
        countEl.textContent = unread > 99 ? '99+' : String(unread);
        countEl.classList.toggle('hidden', unread === 0);
        document.title = document.title.replace(/^\(\d+\+?\) /, '');
        if (unread > 0) document.title = `(${unread > 99 ? '99+' : unread}) ${document.title}`;
    }

    function row(item) {
        const li = el('li');
        const a = el('a', `block px-4 py-3 hover:bg-slate-50 ${item.unread ? 'bg-emerald-50/50' : ''}`);
        a.href = item.url;
        const head = el('div', 'flex items-start justify-between gap-3');
        const title = el('p', `font-medium ${item.unread ? 'text-slate-900' : 'text-slate-700'}`, item.title);
        head.append(title, el('span', 'shrink-0 text-xs text-slate-400', item.ago || 'now'));
        a.append(head);
        if (item.body) a.append(el('p', 'mt-0.5 line-clamp-2 text-slate-600', item.body));
        li.append(a);
        return li;
    }

    function render(items) {
        list.replaceChildren();
        if (!items.length) {
            list.append(el('li', 'px-4 py-8 text-center text-slate-500', 'No notifications yet. Updates on your RFQs, auctions, orders and payments appear here.'));
            return;
        }
        items.forEach((i) => list.append(row(i)));
    }

    async function load() {
        try {
            const data = await json(bell.dataset.feed);
            data.items.forEach((i) => known.add(i.id));
            setUnread(data.unread);
            render(data.items);
            listLoaded = true;
            return data;
        } catch {
            return null;
        }
    }

    // ------------------------------------------------------------------ pop-ups

    let stack = null;
    function toast(alert) {
        if (!stack) {
            stack = el('div', 'pointer-events-none fixed inset-x-3 bottom-3 z-50 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-5 sm:bottom-5 sm:w-96');
            stack.setAttribute('aria-live', 'polite');
            document.body.append(stack);
        }
        while (stack.children.length >= 3) stack.firstChild.remove();
        const card = el('a', 'pointer-events-auto block w-full rounded-xl border border-slate-200 bg-white p-4 shadow-lg ring-1 ring-black/5 transition hover:bg-slate-50');
        card.href = alert.url;
        const top = el('div', 'flex items-start gap-3');
        const dot = el('span', 'mt-1.5 size-2 shrink-0 rounded-full bg-emerald-600');
        const text = el('div', 'min-w-0 flex-1');
        text.append(el('p', 'text-sm font-semibold text-slate-900', alert.title));
        if (alert.body) text.append(el('p', 'mt-0.5 line-clamp-2 text-sm text-slate-600', alert.body));
        const close = el('button', 'shrink-0 rounded p-0.5 text-slate-400 hover:text-slate-700', '×');
        close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss');
        close.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); card.remove(); });
        top.append(dot, text, close);
        card.append(top);
        stack.append(card);
        let timer = setTimeout(() => card.remove(), 9000);
        card.addEventListener('mouseenter', () => clearTimeout(timer));
        card.addEventListener('mouseleave', () => { timer = setTimeout(() => card.remove(), 4000); });
    }

    // ------------------------------------------------------------------ live + fallback

    // New alerts since this page opened that this tab hasn't shown yet: pop up (max 3), refresh the bell.
    let inFlight = null;
    function poll() {
        if (inFlight) return inFlight;
        inFlight = (async () => {
            try {
                const data = await json(bell.dataset.feed);
                const fresh = data.items.filter((i) => i.unread && !known.has(i.id) && (i.ts || 0) >= since).reverse();
                data.items.forEach((i) => known.add(i.id));
                fresh.slice(-3).forEach((i) => toast(i));
                setUnread(data.unread);
                if (bell.open || listLoaded) { render(data.items); listLoaded = true; }
            } catch { /* offline: try again next round */ }
            finally { inFlight = null; }
        })();
        return inFlight;
    }

    // The socket only says "something new"; a burst of alerts becomes one fetch.
    let nudge = null;
    function onAlert() {
        clearTimeout(nudge);
        nudge = setTimeout(poll, 250);
    }

    function schedule() {
        clearInterval(pollTimer);
        pollTimer = socketUp ? null : setInterval(() => { if (!document.hidden) poll(); }, 45000);
    }

    (async () => {
        const echo = await getEcho();
        if (!echo) { schedule(); return; }
        echo.private(`user.${bell.dataset.user}`).listen('.alert', onAlert);
        const conn = echo.connector.pusher.connection;
        let wasUp = false;
        const sync = (up) => {
            if (up === socketUp) return;
            socketUp = up;
            schedule();
            if (up && wasUp) poll(); // reconnected: catch anything missed meanwhile
            if (up) wasUp = true;
        };
        conn.bind('state_change', ({ current }) => sync(current === 'connected'));
        sync(conn.state === 'connected');
        if (!socketUp) schedule();
    })();

    // Another tab read them, or this tab came back to the front: refresh the count.
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !socketUp) poll(); });

    bell.addEventListener('toggle', () => { if (bell.open) { load(); offerPush(); } });

    bell.querySelector('[data-bell-read-all]')?.addEventListener('click', async () => {
        try {
            await json(bell.dataset.readAll, { method: 'POST', body: '{}' });
            setUnread(0);
            list.querySelectorAll('a').forEach((a) => a.classList.remove('bg-emerald-50/50'));
        } catch { /* leave as is */ }
    });

    // Signing out stops device alerts in this browser too (shared office computers).
    document.querySelectorAll('form[action$="/logout"]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            if (form.dataset.pushCleared || !pushSupported()) return;
            e.preventDefault();
            form.dataset.pushCleared = '1';
            await Promise.race([disablePush(bell.dataset.devices), new Promise((r) => setTimeout(r, 1500))]);
            form.submit();
        });
    });

    // ------------------------------------------------------------------ device alerts

    const offer = bell.querySelector('[data-push-offer]');
    const msg = bell.querySelector('[data-push-msg]');
    const say = (t) => { if (msg) { msg.textContent = t; msg.classList.remove('hidden'); } };

    async function offerPush() {
        if (!offer || !bell.dataset.vapid) return;
        if (isIos() && !standalone()) {
            offer.hidden = false;
            offer.querySelector('[data-push-enable]').hidden = true;
            say('On iPhone and iPad, add GetL1 to your Home Screen first (Share → Add to Home Screen), then turn on alerts from the app.');
            return;
        }
        if (!pushSupported() || Notification.permission === 'denied') { offer.hidden = true; return; }
        const reg = await navigator.serviceWorker.getRegistration('/');
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        offer.hidden = !!sub && Notification.permission === 'granted';
    }

    offer?.querySelector('[data-push-enable]')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        const result = await enablePush(bell.dataset.vapid, bell.dataset.devices);
        btn.disabled = false;
        if (result === 'on') { say('Done. This device will now get GetL1 alerts.'); btn.hidden = true; }
        else say(result);
    });
}

function keyBytes(base64) {
    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

/** Ask the browser for permission and register this device. Returns 'on' or a message to show. */
export async function enablePush(vapid, endpointUrl) {
    if (!vapid) return 'Device alerts are not set up on this server yet.';
    if (!pushSupported()) return 'This browser does not support device alerts. Try Chrome, Edge, Firefox or Safari.';
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') return 'Alerts are blocked for this site. Allow notifications in your browser’s site settings, then try again.';
    try {
        const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(() => navigator.serviceWorker.ready);
        let sub = await reg.pushManager.getSubscription();
        if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(vapid) });
        const enc = (PushManager.supportedContentEncodings || ['aesgcm']).includes('aes128gcm') ? 'aes128gcm' : 'aesgcm';
        await json(endpointUrl, { method: 'POST', body: JSON.stringify({ ...sub.toJSON(), content_encoding: enc }) });
        return 'on';
    } catch {
        return 'Could not turn on alerts on this device. Please try again.';
    }
}

/** Stop alerts on this device only. */
export async function disablePush(endpointUrl) {
    try {
        const reg = await navigator.serviceWorker.getRegistration('/');
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        if (!sub) return true;
        await json(endpointUrl, { method: 'DELETE', body: JSON.stringify({ endpoint: sub.endpoint }) });
        await sub.unsubscribe();
        return true;
    } catch {
        return false;
    }
}

/** My profile → Notifications: this-device switch. */
export function initPushSettings() {
    const box = document.querySelector('[data-push-settings]');
    if (!box) return;
    const state = box.querySelector('[data-push-state]');
    const on = box.querySelector('[data-push-on]');
    const off = box.querySelector('[data-push-off]');
    const show = (text, enabled) => { state.textContent = text; on.hidden = enabled; off.hidden = !enabled; };

    const refresh = async () => {
        if (!box.dataset.vapid) return show('Device alerts are not set up on this server yet.', false), (on.hidden = true);
        if (isIos() && !standalone()) return show('On iPhone and iPad, add GetL1 to your Home Screen first (Share → Add to Home Screen), then open it from there to turn on alerts.', false), (on.hidden = true);
        if (!pushSupported()) return show('This browser does not support device alerts.', false), (on.hidden = true);
        if (Notification.permission === 'denied') return show('Alerts are blocked for this site in your browser settings.', false), (on.hidden = true);
        const reg = await navigator.serviceWorker.getRegistration('/');
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        show(sub && Notification.permission === 'granted' ? 'On for this device.' : 'Off for this device.', !!sub && Notification.permission === 'granted');
    };
    on.addEventListener('click', async () => {
        on.disabled = true;
        const r = await enablePush(box.dataset.vapid, box.dataset.devices);
        on.disabled = false;
        if (r === 'on') refresh(); else state.textContent = r;
    });
    off.addEventListener('click', async () => {
        off.disabled = true;
        await disablePush(box.dataset.devices);
        off.disabled = false;
        refresh();
    });
    refresh();
}
