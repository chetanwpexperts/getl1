// <span data-countdown-to="epoch ms" [data-countdown-prefix="in "] [data-countdown-done="now"]>
// Ticks every second against the server clock (page render time on <body data-server-time>).
export function initCountdowns() {
    const counters = document.querySelectorAll('[data-countdown-to]');
    if (!counters.length) return;

    const offset = (Number(document.body.dataset.serverTime) || Date.now()) - Date.now();
    const fmt = (ms) => {
        const s = Math.max(0, Math.floor(ms / 1000));
        const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
        if (d) return `${d}d ${h}h`;
        if (h) return `${h}h ${m}m`;
        return `${m}m ${String(sec).padStart(2, '0')}s`;
    };
    const tick = () => counters.forEach((c) => {
        const left = Number(c.dataset.countdownTo) - (Date.now() + offset);
        c.textContent = left > 0 ? (c.dataset.countdownPrefix ?? '') + fmt(left) : (c.dataset.countdownDone ?? 'now');
    });
    tick();
    setInterval(tick, 1000);
}
