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
        if (cfg.practice) { dirty = true; return; }
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
        if (cfg.practice) {
            const dot = $('[data-connection]');
            if (dot) { dot.textContent = 'Practice mode · nothing is saved'; dot.dataset.state = 'up'; }
            return;
        }
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
        if (cfg.practice) return;
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
        // Paused by GetL1: the clock is frozen at the time that was left.
        const left = state.paused ? Math.max(0, state.paused_remaining_ms || 0) : Math.max(0, target - now);
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
            label.textContent = state.paused ? (state.format === 'japanese' ? 'Paused · round time left' : 'Paused · time left')
                : ({ scheduled: 'Starts in', live: state.format === 'japanese' ? `Round ${state.round} ends in` : 'Ends in', closed: 'Auction closed', cancelled: 'Cancelled' }[state.status] ?? '');
        }

        // Crossing a boundary (start/end): ask the server for the authoritative state.
        if (!state.paused && left === 0 && (state.status === 'scheduled' || state.status === 'live') && Date.now() - lastBoundaryFetch > 1000) {
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
        setLive('[data-savings]', state.savings_pct === null || state.savings_pct < 0 ? '—' : `${state.savings_pct.toFixed(2)}%`);
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

        if (state.format === 'japanese') renderJapaneseCommon();
        const bidders = $('[data-jp-bidders]');
        if (bidders && state.bidders) {
            renderList(bidders, state.bidders, (b) => `j${b.id}`, (b) => {
                const status = state.status === 'closed' ? (b.rank === 1 ? 'Winner' : `Out after round ${b.last_round}`)
                    : b.accepted ? '✓ Accepted' : b.in ? 'Waiting…' : (b.last_round ? `Dropped out (round ${b.last_round + 1})` : 'Never accepted');
                const tr = row([
                    [`L${b.rank}`, 'px-5 py-3 font-semibold'],
                    [b.supplier, 'px-5 py-3'],
                    [status, `px-5 py-3 ${b.accepted || (state.status === 'closed' && b.rank === 1) ? 'font-medium text-emerald-700' : b.in ? 'text-amber-700' : 'text-slate-400'}`],
                    [b.last_price === null ? '—' : fmt(b.last_price), 'px-5 py-3 text-right tabular-nums'],
                ]);
                if (b.rank === 1) tr.className = 'bg-emerald-50';
                return tr;
            }, (b) => `${b.accepted}|${b.in}|${b.last_round}`);
        }
        const rounds = $('[data-jp-rounds]');
        if (rounds && state.rounds) {
            renderList(rounds, state.rounds.length ? state.rounds : [null], (r) => (r ? `r${r.round}-${r.accepted}` : 'none'), (r) => {
                const li = document.createElement('li');
                li.className = 'flex justify-between gap-3 px-5 py-2.5';
                if (!r) { li.textContent = 'No acceptances yet.'; li.className += ' text-slate-500'; return li; }
                const left = document.createElement('span');
                left.textContent = `Round ${r.round} · ${fmt(r.price)}`;
                const right = document.createElement('span');
                right.className = 'text-slate-500';
                right.textContent = `${r.accepted} accepted`;
                li.append(left, right);
                return li;
            });
        }

        const board = $('[data-items-board]');
        if (board && state.items) {
            renderList(board, state.items, (it) => `i${it.id}`, (it) => {
                const others = it.ranking.slice(1, 4).map((r) => `L${r.rank} ${r.supplier} ${fmt(r.rate)}`).join(' · ');
                return row([
                    [`${it.line}. ${it.name} (${it.qty} ${it.unit})`, 'px-5 py-3 font-medium'],
                    [it.l1_supplier ?? '—', 'px-5 py-3'],
                    [fmt(it.l1_rate), 'px-5 py-3 text-right tabular-nums font-semibold text-emerald-700'],
                    [fmt(it.l1_total), 'px-5 py-3 text-right tabular-nums'],
                    [others || '—', 'px-5 py-3 text-xs text-slate-500'],
                    [String(it.bids), 'px-5 py-3 text-right tabular-nums'],
                ]);
            }, (it) => `${it.l1_rate}|${it.l1_supplier}|${it.bids}`);
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
                left.textContent = `${b.supplier} → ${b.item ? `${b.item}: ${fmt(b.amount)}/unit` : fmt(b.amount)}${b.rank ? ` (L${b.rank})` : ''}`;
                const right = document.createElement('span');
                right.className = 'text-slate-500';
                right.textContent = time(b.at);
                li.append(left, right);
                return li;
            });
        }
    }

    function bidWaitingText() {
        return state.paused ? 'Bidding is paused. You can bid again as soon as the auction resumes.'
            : state.status === 'scheduled' ? 'Bidding opens when the countdown reaches zero.'
            : state.status === 'cancelled' ? 'This auction was cancelled.'
            : 'Bidding is closed. The buyer will review the result and award.';
    }

    function renderMyBids() {
        const list = $('[data-my-bids]');
        if (!list) return;
        renderList(list, state.my_bids, (b) => `m${b.at}-${b.amount}-${b.item ?? ''}`, (b) => {
            const li = document.createElement('li');
            li.className = 'flex justify-between gap-3 px-4 py-2';
            const left = document.createElement('span');
            left.textContent = `${b.item ? `${b.item}: ` : ''}${fmt(b.amount)}${b.item ? '/unit' : ''}${b.kind === 'sealed' ? ' (sealed quote)' : ''}`;
            const right = document.createElement('span');
            right.className = 'text-slate-500';
            right.textContent = `${b.rank ? `L${b.rank} · ` : ''}${time(b.at)}`;
            li.append(left, right);
            return li;
        });
    }

    /** Item-wise: rows are rendered by the server once; only their cells change (inputs keep what's typed). */
    function renderSupplierItems() {
        setLive('[data-leading]', String(state.leading));
        setLive('[data-my-total]', fmt(state.my_total));
        setText('[data-extensions]', `${state.extensions_used} of ${state.max_extensions}`);
        const badge = $('[data-rank-badge]');
        if (badge) badge.dataset.rank = state.leading > 0 ? 'first' : 'other';

        const canBid = state.status === 'live' && !state.paused;
        for (const it of state.items) {
            const tr = root.querySelector(`[data-item-row="${it.id}"]`);
            if (!tr) continue;
            const cell = (name) => tr.querySelector(`[data-cell="${name}"]`);
            const rank = cell('rank');
            const rankText = `L${it.my_rank}`;
            if (rank.textContent !== rankText) {
                rank.textContent = rankText;
                replay(rank, 'anim-pop');
            }
            rank.dataset.rank = it.my_rank === 1 ? 'first' : 'other';
            const rate = cell('rate');
            if (rate.textContent !== fmt(it.my_rate)) { rate.textContent = fmt(it.my_rate); replay(rate, 'anim-pop'); }
            cell('l1').textContent = it.l1_rate === null ? 'Hidden' : fmt(it.l1_rate);
            cell('max').textContent = fmt(it.max_next_bid);
            tr.querySelectorAll('input, button').forEach((el) => { el.disabled = !canBid; });
        }

        const waiting = $('[data-bid-waiting]');
        if (waiting) {
            waiting.hidden = canBid;
            waiting.textContent = bidWaitingText();
        }
        renderMyBids();
    }

    function renderJapaneseCommon() {
        setText('[data-jp-round]', String(state.round));
        setText('[data-jp-max]', String(state.max_rounds));
        setLive('[data-jp-price]', fmt(state.round_price));
        setText('[data-jp-next]', state.next_price === null ? 'floor reached' : fmt(state.next_price));
        setText('[data-jp-floor]', fmt(state.floor_price));
        setLive('[data-jp-in]', String(state.still_in));
        setLive('[data-jp-accepted]', String(state.accepted_count));
    }

    function renderSupplierJapanese() {
        renderJapaneseCommon();
        setLive('[data-my-amount]', fmt(state.my_amount));
        setText('[data-jp-accept-price]', fmt(state.round_price));
        const btn = $('[data-jp-accept]');
        const canAccept = state.status === 'live' && !state.paused && state.my_status === 'in';
        if (btn && !btn.dataset.confirming) btn.disabled = !canAccept;
        const action = $('[data-jp-action]');
        if (action) action.hidden = state.status !== 'live' || state.my_status !== 'in';
        const box = $('[data-jp-status]');
        if (box) {
            const [text, tone] = state.paused ? ['Paused by GetL1. The round clock is stopped; you lose no time.', 'amber']
                : state.status === 'scheduled' ? ['Round 1 opens when the countdown reaches zero.', 'slate']
                : state.status === 'cancelled' ? ['This auction was cancelled.', 'slate']
                : state.status === 'closed' ? [state.my_rank === 1 ? 'Auction over: you are L1 at your last accepted price. The buyer will review and award.' : `Auction over: you finished L${state.my_rank ?? '—'}.`, state.my_rank === 1 ? 'emerald' : 'slate']
                : state.my_status === 'accepted' ? [`You're in at ${fmt(state.round_price)}. Wait for round ${state.round + 1}.`, 'emerald']
                : state.my_status === 'out' ? ['You dropped out. You can watch until the end.', 'slate']
                : ['Accept before the countdown ends to stay in.', 'amber'];
            box.textContent = text;
            box.className = `mt-6 rounded-xl px-4 py-3 text-sm font-medium ${{ emerald: 'bg-emerald-50 text-emerald-800', amber: 'bg-amber-50 text-amber-900', slate: 'bg-slate-50 text-slate-700' }[tone]}`;
        }
        const list = $('[data-my-bids]');
        if (list) {
            renderList(list, state.my_bids, (b) => `m${b.at}-${b.round ?? 's'}`, (b) => {
                const li = document.createElement('li');
                li.className = 'flex justify-between gap-3 px-4 py-2';
                const left = document.createElement('span');
                left.textContent = b.kind === 'sealed' ? `${fmt(b.amount)} (sealed quote)` : `Round ${b.round}: accepted ${fmt(b.amount)}`;
                const right = document.createElement('span');
                right.className = 'text-slate-500';
                right.textContent = time(b.at);
                li.append(left, right);
                return li;
            });
        }
    }

    function renderSupplier() {
        if (state.format === 'japanese') return renderSupplierJapanese();
        if (state.basis === 'per_item') return renderSupplierItems();
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
        const canBid = state.status === 'live' && !state.paused;
        if (form) form.hidden = !canBid;
        const waiting = $('[data-bid-waiting]');
        if (waiting) {
            waiting.hidden = canBid;
            waiting.textContent = bidWaitingText();
        }

        renderMyBids();
    }

    function render(ts) {
        countdown();
        if (dirty && ts - lastRender > 200) {
            dirty = false;
            lastRender = ts;
            setText('[data-status]', state.paused ? 'Paused' : ({ scheduled: 'Scheduled', live: 'Live', closed: 'Closed', cancelled: 'Cancelled' }[state.status] ?? state.status));
            const statusEl = $('[data-status]');
            if (statusEl) statusEl.dataset.status = state.paused ? 'paused' : state.status;
            const banner = $('[data-notice]');
            if (banner) { banner.hidden = !state.notice; banner.textContent = state.notice || ''; }
            $$('[data-show-when]').forEach((el) => { el.hidden = el.dataset.showWhen !== state.status; });
            (cfg.role === 'buyer' ? renderBuyer : renderSupplier)();
            firstPaint = false;
        }
        requestAnimationFrame(render);
    }

    // ------------------------------------------------------------------ bidding (supplier)

    const confirmBox = $('[data-bid-confirm]');
    const msg = $('[data-bid-message]');
    const submitBtn = confirmBox?.querySelector('[data-bid-submit]');
    let pending = null; // { amount, key, item?, input }

    const say = (text, ok = false) => {
        if (!msg) return;
        msg.textContent = text;
        msg.dataset.tone = ok ? 'ok' : 'error';
    };
    const parse = (v) => Number(String(v).replace(/[,₹\s]/g, ''));

    function ask(next, drop, itemName = null) {
        pending = { ...next, key: uuid() }; // one key per confirmed attempt
        setText('[data-confirm-amount]', fmt(Number(next.amount)));
        setText('[data-confirm-drop]', `${drop}% below your current ${itemName ? 'rate' : 'price'}`);
        if (itemName) setText('[data-confirm-item]', itemName);
        confirmBox.hidden = false;
        submitBtn.focus();
    }

    // Lot auction: one form for the whole RFQ total.
    const form = $('[data-bid-form]');
    if (form) {
        const input = form.querySelector('input[name="amount"]');

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
            const amount = parse(input.value);
            if (!Number.isFinite(amount) || amount <= 0) return say('Enter a valid amount.');
            if (amount > state.max_next_bid) return say(`Your bid must be at most ${fmt(state.max_next_bid)}.`);
            if (state.floor !== null && amount < state.floor) return say(`That's too far below the current lowest price. Check for a typo.`);
            const drop = state.my_amount ? ((1 - amount / state.my_amount) * 100).toFixed(2) : '0';
            ask({ amount: amount.toFixed(2), input }, drop);
        });
    }

    // Item-wise auction: a small form per item row.
    $$('[data-item-bid]').forEach((itemForm) => {
        const input = itemForm.querySelector('input[name="amount"]');
        itemForm.addEventListener('submit', (e) => {
            e.preventDefault();
            say('');
            const id = Number(itemForm.dataset.itemBid);
            const it = (state.items || []).find((x) => x.id === id);
            if (!it) return say('Refresh the page and try again.');
            const amount = parse(input.value);
            if (!Number.isFinite(amount) || amount <= 0) return say(`Enter a valid rate for ${it.name}.`);
            if (amount > it.max_next_bid) return say(`Your rate for ${it.name} must be at most ${fmt(it.max_next_bid)}.`);
            if (it.floor !== null && amount < it.floor) return say(`That's too far below the current lowest rate for ${it.name}. Check for a typo.`);
            const drop = ((1 - amount / it.my_rate) * 100).toFixed(2);
            ask({ amount: amount.toFixed(2), item: id, input }, drop, `${it.name} (line total ${fmt(amount * it.qty)})`);
        });
    });

    // Japanese: one button, tapped twice (accept → confirm) so a stray tap never commits.
    const jpBtn = $('[data-jp-accept]');
    if (jpBtn) {
        let resetTimer = null;
        const reset = () => {
            delete jpBtn.dataset.confirming;
            jpBtn.innerHTML = '';
            jpBtn.append('Accept ');
            const span = document.createElement('span');
            span.dataset.jpAcceptPrice = '';
            span.textContent = fmt(state.round_price);
            jpBtn.append(span);
            dirty = true;
        };
        jpBtn.addEventListener('click', async () => {
            say('');
            if (!jpBtn.dataset.confirming) {
                jpBtn.dataset.confirming = String(state.round);
                jpBtn.textContent = `Tap again to accept ${fmt(state.round_price)}`;
                clearTimeout(resetTimer);
                resetTimer = setTimeout(reset, 4000);
                return;
            }
            clearTimeout(resetTimer);
            if (Number(jpBtn.dataset.confirming) !== state.round) {
                reset();
                return say(`The round moved on. The price is now ${fmt(state.round_price)}.`);
            }
            pending = { amount: Number(state.round_price).toFixed(2), round: state.round, key: uuid() };
            jpBtn.disabled = true;
            jpBtn.textContent = 'Accepting…';
            await send();
            reset();
        });
    }

    confirmBox?.querySelector('[data-bid-cancel]')?.addEventListener('click', () => {
        pending = null;
        confirmBox.hidden = true;
    });

    async function send(attempt = 1) {
        if (cfg.practice) return practiceBid();
        try {
            const body = { amount: pending.amount, idempotency_key: pending.key };
            if (pending.item) body.item = pending.item;
            if (pending.round) body.round = pending.round;
            const res = await fetch(cfg.bidUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify(body),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) {
                apply(data.state);
                if (pending.round) {
                    say(''); // the status box already says "You're in"
                } else {
                    const it = pending.item ? (data.state.items || []).find((x) => x.id === pending.item) : null;
                    const rank = it ? it.my_rank : data.state.my_rank;
                    say(`Bid placed. You are L${rank}${it ? ` on ${it.name}` : ''}.${data.extended ? ' The auction was extended.' : ''}`, true);
                }
                if (pending.input) pending.input.value = '';
                pending = null;
                if (confirmBox) confirmBox.hidden = true;
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

    // ------------------------------------------------------------------ practice (no server)
    //
    // Three simulated suppliers with hidden lowest prices bid against the user. Same rules as a
    // real lot auction: beat your own price by the minimum drop, typo guard against L1, auto-extend.

    const bots = [
        { name: 'b1', amount: 98500, floor: 93800, at: Date.now() - 3000000 },
        { name: 'b2', amount: 99200, floor: 95200, at: Date.now() - 2500000 },
        { name: 'b3', amount: 101000, floor: 96900, at: Date.now() - 2000000 },
    ];
    let myAt = Date.now() - 3600000;

    function practiceRecompute(extendOnBid = false) {
        const now = Date.now();
        const rows = [...bots.map((b) => ({ me: false, amount: b.amount, at: b.at })), { me: true, amount: state.my_amount, at: myAt }]
            .sort((x, y) => x.amount - y.amount || x.at - y.at);
        const next = { ...state, server_time: now };
        next.my_rank = rows.findIndex((r) => r.me) + 1;
        next.l1_amount = rows[0].amount;
        next.min_decrement = Math.round(state.my_amount * 0.005 * 100) / 100;
        next.max_next_bid = Math.round((state.my_amount - next.min_decrement) * 100) / 100;
        next.floor = Math.round(rows[0].amount * 0.9 * 100) / 100;
        if (extendOnBid && next.status === 'live' && next.extensions_used < next.max_extensions && next.ends_at - now <= next.extend_window_sec * 1000) {
            next.ends_at += next.extend_by_sec * 1000;
            next.extensions_used += 1;
        }
        offset = 0;
        state = next;
        dirty = true;
        return next;
    }

    function practiceBid() {
        const amount = Number(pending.amount);
        if (state.status !== 'live') return say('The practice auction has ended. Start again to try once more.');
        if (amount > state.max_next_bid) return say(`Your bid must be at most ${fmt(state.max_next_bid)}.`);
        if (amount < state.floor) return say('That’s too far below the current lowest price. Check for a typo.');
        state.my_amount = amount;
        myAt = Date.now();
        const before = state.extensions_used;
        const next = practiceRecompute(true);
        state.my_bids = [{ amount, kind: 'live', rank: next.my_rank, at: myAt }, ...state.my_bids].slice(0, 10);
        say(`Bid placed. You are L${next.my_rank}.${next.extensions_used > before ? ' The auction was extended.' : ''} (Practice: nothing is saved.)`, true);
        if (pending.input) pending.input.value = '';
        pending = null;
        if (confirmBox) confirmBox.hidden = true;
    }

    function practiceTick() {
        if (state.status !== 'live') return;
        if (Date.now() >= state.ends_at) {
            state = { ...state, status: 'closed' };
            dirty = true;
            say(state.my_rank === 1 ? 'Practice over: you finished L1. In a real auction the buyer would now review and award.' : `Practice over: you finished L${state.my_rank}.`, state.my_rank === 1);
            return;
        }
        // A competitor who isn't leading (or sometimes the leader) moves, never below its hidden floor.
        const l1 = Math.min(...bots.map((b) => b.amount), state.my_amount);
        const movers = bots.filter((b) => b.amount > b.floor && (b.amount > l1 || Math.random() < 0.15));
        if (movers.length) {
            const b = movers[Math.floor(Math.random() * movers.length)];
            const own = b.amount * (1 - (0.3 + Math.random() * 0.6) / 100);
            const target = Math.random() < 0.55 ? Math.min(own, l1 - 50 - Math.random() * 400) : own; // sometimes just improve, not lead
            b.amount = Math.max(b.floor, Math.round(target / 10) * 10);
            b.at = Date.now();
            practiceRecompute(true);
        }
        setTimeout(practiceTick, 6000 + Math.random() * 7000);
    }

    // ------------------------------------------------------------------ start

    schedulePolling();
    connectSocket();
    if (cfg.practice) {
        practiceRecompute();
        setTimeout(practiceTick, 3000);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    requestAnimationFrame(render);
}
