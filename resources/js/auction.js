// Live auction client (buyer console + supplier bidding).
//
// - Server clock only: countdown = server end time − (device time + offset measured from the server).
// - Updates arrive over the private WebSocket channel; if the socket isn't connected, the page polls.
// - Rendering is throttled (max ~5 frames/second) so busy auctions don't freeze phones.
// - Every bid attempt carries an idempotency key; a retry after a network error reuses it.
// - All text is written with textContent (never innerHTML), so names can't inject markup.

const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmt = (v) => (v === null || v === undefined ? '—' : inr.format(v));
const time = (ms) => new Date(ms).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Asia/Kolkata' });
const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2, 12)}`);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export function initAuction() {
    const root = document.querySelector('[data-auction]');
    if (!root) return;

    const cfg = JSON.parse(root.dataset.auction);
    const $ = (sel) => root.querySelector(sel);
    const $$ = (sel) => root.querySelectorAll(sel);

    let state = cfg.state;
    let offset = state.server_time - Date.now();
    let socketUp = false;
    let dirty = true;
    let lastRender = 0;
    let pollTimer = null;
    let fetching = false;
    let lastBoundaryFetch = 0;

    // ------------------------------------------------------------------ state

    function apply(next, fromHttp = false, rtt = 0) {
        if (!next || next.id !== state.id) return;
        if (next.server_time < state.server_time) return; // stale (arrived out of order)
        if (fromHttp) offset = next.server_time + rtt / 2 - Date.now();
        state = next;
        dirty = true;
    }

    async function refresh() {
        if (fetching) return;
        fetching = true;
        const t0 = Date.now();
        try {
            const res = await fetch(cfg.stateUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.ok) apply(await res.json(), true, Date.now() - t0);
        } catch { /* offline: keep showing the last state */ } finally {
            fetching = false;
        }
    }

    function schedulePolling() {
        clearInterval(pollTimer);
        // Socket up: a slow safety poll. Socket down: poll every 2 seconds.
        pollTimer = setInterval(refresh, socketUp ? 20000 : 2000);
        const dot = $('[data-connection]');
        if (dot) {
            dot.textContent = socketUp ? 'Real-time updates on' : 'Updating every 2 seconds';
            dot.dataset.state = socketUp ? 'up' : 'down';
        }
    }

    // ------------------------------------------------------------------ socket

    async function connectSocket() {
        const key = import.meta.env.VITE_REVERB_APP_KEY;
        if (!key) return; // not configured: polling only
        try {
            const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
            window.Pusher = Pusher;
            const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
            const echo = new Echo({
                broadcaster: 'reverb',
                key,
                wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
                wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
                wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
                forceTLS: scheme === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/auth',
                auth: { headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' } },
            });
            echo.private(cfg.channel).listen('.state', (s) => (s.refresh ? refresh() : apply(s)));
            echo.connector.pusher.connection.bind('state_change', ({ current }) => {
                const up = current === 'connected';
                if (up !== socketUp) {
                    socketUp = up;
                    schedulePolling();
                    if (up) refresh(); // catch anything missed while disconnected
                }
            });
        } catch {
            socketUp = false;
            schedulePolling();
        }
    }

    // ------------------------------------------------------------------ render

    function countdown() {
        const now = Date.now() + offset;
        const target = state.status === 'scheduled' ? state.starts_at : state.ends_at;
        const left = Math.max(0, target - now);
        const h = Math.floor(left / 3600000);
        const m = Math.floor((left % 3600000) / 60000);
        const s = Math.floor((left % 60000) / 1000);
        const text = (h ? `${String(h).padStart(2, '0')}:` : '') + `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

        const el = $('[data-countdown]');
        if (el) {
            el.textContent = state.status === 'closed' || state.status === 'cancelled' ? '00:00' : text;
            el.dataset.urgent = state.status === 'live' && left <= Math.max(state.extend_window_sec, 60) * 1000 ? '1' : '0';
        }
        const label = $('[data-countdown-label]');
        if (label) {
            label.textContent = { scheduled: 'Starts in', live: 'Ends in', closed: 'Auction closed', cancelled: 'Cancelled' }[state.status] ?? '';
        }

        // Crossing a boundary (start/end): ask the server for the authoritative state.
        if (left === 0 && (state.status === 'scheduled' || state.status === 'live') && Date.now() - lastBoundaryFetch > 1000) {
            lastBoundaryFetch = Date.now();
            refresh();
        }
    }

    function setText(sel, value) {
        const el = $(sel);
        if (el) el.textContent = value;
    }

    // ------------------------------------------------------------------ motion
    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    let firstPaint = true;

    function replay(el, cls) {
        if (!el || reduceMotion || firstPaint) return;
        el.classList.remove(cls);
        void el.offsetWidth; // restart the animation
        el.classList.add(cls);
    }

    /** Set text; pulse the element when the value actually changes. */
    function setLive(sel, value) {
        const el = $(sel);
        if (!el || el.textContent === value) return;
        el.textContent = value;
        replay(el, 'anim-pop');
    }

    /**
     * Keyed list update with FLIP: rows that move slide from their old position, new rows
     * slide in, rows whose content changed flash once.
     */
    function renderList(container, items, key, build, sig = null) {
        const before = new Map();
        for (const el of container.children) {
            if (el.dataset.key) before.set(el.dataset.key, { top: el.getBoundingClientRect().top, sig: el.dataset.sig });
        }
        const nodes = items.map((item) => {
            const el = build(item);
            el.dataset.key = key(item);
            el.dataset.sig = sig ? sig(item) : el.textContent;
            return el;
        });
        container.replaceChildren(...nodes);
        if (reduceMotion || firstPaint) return;

        for (const el of nodes) {
            const old = before.get(el.dataset.key);
            if (!old) {
                el.classList.add('anim-enter');
                continue;
            }
            if (old.sig !== el.dataset.sig) el.classList.add('anim-flash');
            const delta = old.top - el.getBoundingClientRect().top;
            if (Math.abs(delta) > 1) {
                el.style.transform = `translateY(${delta}px)`;
                el.style.transition = 'none';
                requestAnimationFrame(() => {
                    el.style.transition = 'transform 500ms cubic-bezier(0.2, 0.8, 0.2, 1)';
                    el.style.transform = '';
                });
            }
        }
    }

    function row(cells) {
        const tr = document.createElement('tr');
        for (const [text, cls] of cells) {
            const td = document.createElement('td');
            td.textContent = text;
            td.className = cls ?? '';
            tr.appendChild(td);
        }
        return tr;
    }

    function renderBuyer() {
        setLive('[data-l1]', fmt(state.current_l1));
        setText('[data-start-price]', fmt(state.start_price));
        setLive('[data-savings]', state.savings_pct === null ? '—' : `${state.savings_pct.toFixed(2)}%`);
        setLive('[data-bid-count]', String(state.bid_count));
        setLive('[data-extensions]', `${state.extensions_used} of ${state.max_extensions}`);

        const tbody = $('[data-standings]');
        if (tbody) {
            renderList(tbody, state.standings, (s) => `s${s.id}`, (s) => {
                const tr = row([
                    [`L${s.rank}`, 'px-4 py-3 font-semibold'],
                    [s.supplier + (s.verified ? ' ✓' : ''), 'px-4 py-3'],
                    [fmt(s.amount), 'px-4 py-3 text-right tabular-nums font-medium'],
                    [String(s.bids), 'px-4 py-3 text-right tabular-nums'],
                    [time(s.at), 'px-4 py-3 text-right text-slate-500'],
                ]);
                if (s.rank === 1) tr.className = 'bg-emerald-50';
                return tr;
            }, (s) => `${s.amount}|${s.bids}`); // flash on a new price; rank moves just slide
        }

        const feed = $('[data-recent]');
        if (feed) {
            renderList(feed, state.recent.length ? state.recent : [null], (b) => (b ? `b${b.at}-${b.amount}-${b.supplier}` : 'empty'), (b) => {
                const li = document.createElement('li');
                li.className = 'flex justify-between gap-3 px-4 py-2';
                if (!b) {
                    li.textContent = 'No live bids yet.';
                    li.className += ' text-slate-500';
                    return li;
                }
                const left = document.createElement('span');
                left.textContent = `${b.supplier} → ${fmt(b.amount)}${b.rank ? ` (L${b.rank})` : ''}`;
                const right = document.createElement('span');
                right.className = 'text-slate-500';
                right.textContent = time(b.at);
                li.append(left, right);
                return li;
            });
        }
    }

    function renderSupplier() {
        const rankBefore = $('[data-my-rank]')?.textContent;
        setLive('[data-my-rank]', state.my_rank ? `L${state.my_rank}` : '—');
        if (rankBefore !== $('[data-my-rank]')?.textContent) replay($('[data-rank-badge]'), 'anim-flash');
        setText('[data-participants]', String(state.participants));
        setLive('[data-my-amount]', fmt(state.my_amount));
        setLive('[data-l1]', state.l1_amount === null ? 'Hidden by buyer' : fmt(state.l1_amount));
        setText('[data-max-next]', fmt(state.max_next_bid));
        setText('[data-min-dec]', fmt(state.min_decrement));
        setText('[data-extensions]', `${state.extensions_used} of ${state.max_extensions}`);

        const badge = $('[data-rank-badge]');
        if (badge) badge.dataset.rank = state.my_rank === 1 ? 'first' : 'other';

        const form = $('[data-bid-form]');
        if (form) form.hidden = state.status !== 'live';
        const waiting = $('[data-bid-waiting]');
        if (waiting) {
            waiting.hidden = state.status === 'live';
            waiting.textContent = state.status === 'scheduled' ? 'Bidding opens when the countdown reaches zero.'
                : 'Bidding is closed. The buyer will review the result and award.';
        }

        const list = $('[data-my-bids]');
        if (list) {
            renderList(list, state.my_bids, (b) => `m${b.at}-${b.amount}`, (b) => {
                const li = document.createElement('li');
                li.className = 'flex justify-between gap-3 px-4 py-2';
                const left = document.createElement('span');
                left.textContent = `${fmt(b.amount)}${b.kind === 'sealed' ? ' (sealed quote)' : ''}`;
                const right = document.createElement('span');
                right.className = 'text-slate-500';
                right.textContent = `${b.rank ? `L${b.rank} · ` : ''}${time(b.at)}`;
                li.append(left, right);
                return li;
            });
        }
    }

    function render(ts) {
        countdown();
        if (dirty && ts - lastRender > 200) {
            dirty = false;
            lastRender = ts;
            setText('[data-status]', { scheduled: 'Scheduled', live: 'Live', closed: 'Closed', cancelled: 'Cancelled' }[state.status] ?? state.status);
            const statusEl = $('[data-status]');
            if (statusEl) statusEl.dataset.status = state.status;
            (cfg.role === 'buyer' ? renderBuyer : renderSupplier)();
            firstPaint = false;
        }
        requestAnimationFrame(render);
    }

    // ------------------------------------------------------------------ bidding (supplier)

    const form = $('[data-bid-form]');
    if (form) {
        const input = form.querySelector('input[name="amount"]');
        const confirmBox = $('[data-bid-confirm]');
        const msg = $('[data-bid-message]');
        const submitBtn = confirmBox?.querySelector('[data-bid-submit]');
        let pending = null; // { amount, key }

        const say = (text, ok = false) => {
            msg.textContent = text;
            msg.dataset.tone = ok ? 'ok' : 'error';
        };

        $$('[data-quick-drop]').forEach((btn) => btn.addEventListener('click', () => {
            if (state.my_amount === null) return;
            const drop = Number(btn.dataset.quickDrop);
            const suggested = Math.min(state.max_next_bid, Math.round(state.my_amount * (1 - drop / 100) * 100) / 100);
            input.value = suggested.toFixed(2);
            input.focus();
        }));

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            say('');
            const amount = Number(String(input.value).replace(/[,₹\s]/g, ''));
            if (!Number.isFinite(amount) || amount <= 0) return say('Enter a valid amount.');
            if (amount > state.max_next_bid) return say(`Your bid must be at most ${fmt(state.max_next_bid)}.`);
            if (state.floor !== null && amount < state.floor) return say(`That's too far below the current lowest price. Check for a typo.`);

            pending = { amount: amount.toFixed(2), key: uuid() }; // one key per confirmed attempt
            const drop = state.my_amount ? ((1 - amount / state.my_amount) * 100).toFixed(2) : '0';
            setText('[data-confirm-amount]', fmt(amount));
            setText('[data-confirm-drop]', `${drop}% below your current price`);
            confirmBox.hidden = false;
            submitBtn.focus();
        });

        confirmBox?.querySelector('[data-bid-cancel]')?.addEventListener('click', () => {
            pending = null;
            confirmBox.hidden = true;
        });

        async function send(attempt = 1) {
            try {
                const res = await fetch(cfg.bidUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({ amount: pending.amount, idempotency_key: pending.key }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok) {
                    apply(data.state);
                    say(`Bid placed. You are L${data.state.my_rank}.${data.extended ? ' The auction was extended.' : ''}`, true);
                    input.value = '';
                    pending = null;
                    confirmBox.hidden = true;
                } else if (res.status === 419) {
                    say('Your session expired. Refresh the page and bid again.');
                } else if (res.status === 429) {
                    say('Too many bids too quickly. Wait a moment.');
                } else {
                    say(data.errors?.amount?.[0] ?? data.message ?? 'Bid not accepted.');
                    refresh();
                }
            } catch {
                // Network hiccup: retry once with the SAME key; the server records it only once.
                if (attempt === 1) return new Promise((r) => setTimeout(() => r(send(2)), 1500));
                say('Network problem. Check your connection; your bid may not have been placed.');
                refresh();
            }
        }

        submitBtn?.addEventListener('click', async () => {
            if (!pending || submitBtn.disabled) return;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Placing…';
            await send();
            submitBtn.disabled = false;
            submitBtn.textContent = 'Confirm bid';
        });
    }

    // ------------------------------------------------------------------ start

    schedulePolling();
    connectSocket();
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    requestAnimationFrame(render);
}
