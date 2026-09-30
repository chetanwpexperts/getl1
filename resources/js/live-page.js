// Self-updating pages. The page carries a fingerprint of its data; we re-check it every
// 15 seconds and exactly when something is due to change (deadline, auction start/end).
// If it changed we reload, unless the user is mid-typing: then we offer a refresh instead.
const POLL_MS = 15000;

export function initLivePage() {
    const el = document.querySelector('[data-live-page]');
    if (!el) return;

    const url = el.dataset.url;
    let version = el.dataset.v;
    let refreshAt = Number(el.dataset.refreshAt) || null;
    let offset = Number(el.dataset.serverTime) - Date.now(); // server clock minus ours
    let dirty = false;
    let timer = null;
    let busy = false;

    document.addEventListener('input', (e) => { if (e.target.closest('form')) dirty = true; });
    document.addEventListener('submit', () => { dirty = false; });

    const editing = () => {
        const a = document.activeElement;
        return dirty || (a && a.closest('form') && ['INPUT', 'TEXTAREA', 'SELECT'].includes(a.tagName));
    };

    function banner() {
        if (document.querySelector('[data-live-banner]')) return;
        const box = document.createElement('div');
        box.dataset.liveBanner = '';
        box.setAttribute('role', 'status');
        box.className = 'fixed inset-x-0 bottom-4 z-50 mx-auto flex w-fit max-w-[calc(100%-2rem)] items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-sm text-white shadow-lg';
        const text = document.createElement('span');
        text.textContent = 'This page has new updates.';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rounded-lg bg-white px-3 py-1 font-semibold text-slate-900 hover:bg-slate-100';
        btn.textContent = 'Refresh';
        btn.addEventListener('click', () => window.location.reload());
        box.append(text, btn);
        document.body.appendChild(box);
    }

    async function check() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const t0 = Date.now();
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.status === 401 || res.status === 419) return window.location.reload(); // signed out
            if (!res.ok) return;
            const data = await res.json();
            offset = data.server_time - (t0 + (Date.now() - t0) / 2);
            refreshAt = data.refresh_at ?? null;
            if (data.v !== version) {
                if (editing()) {
                    version = data.v;
                    banner();
                } else {
                    window.location.reload();
                    return;
                }
            }
        } catch {
            // offline for a moment: try again next round
        } finally {
            busy = false;
            schedule();
        }
    }

    function schedule() {
        clearTimeout(timer);
        let wait = POLL_MS;
        if (refreshAt) {
            const untilDue = refreshAt - (Date.now() + offset) + 1200; // just after the moment, by server time
            wait = Math.max(500, Math.min(wait, untilDue));
        }
        timer = setTimeout(check, wait);
    }

    document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });
    schedule();
}
