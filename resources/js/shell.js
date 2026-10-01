// App frame: the phone menu drawer and the dropdowns (account menu, company switcher).
export function initShell() {
    const nav = document.querySelector('[data-shell-nav]');
    const backdrop = document.querySelector('[data-shell-backdrop]');
    if (nav) {
        const open = (on) => {
            nav.classList.toggle('-translate-x-full', !on);
            if (backdrop) backdrop.hidden = !on;
            document.body.classList.toggle('overflow-hidden', on);
        };
        document.querySelector('[data-shell-open]')?.addEventListener('click', () => open(true));
        document.querySelector('[data-shell-close]')?.addEventListener('click', () => open(false));
        backdrop?.addEventListener('click', () => open(false));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });
    }

    // <details data-dropdown>: close when clicking elsewhere or pressing Escape; one open at a time.
    const drops = [...document.querySelectorAll('details[data-dropdown]')];
    document.addEventListener('click', (e) => drops.forEach((d) => { if (d.open && !d.contains(e.target)) d.open = false; }));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') drops.forEach((d) => { d.open = false; }); });
    drops.forEach((d) => d.addEventListener('toggle', () => { if (d.open) drops.forEach((o) => { if (o !== d) o.open = false; }); }));
}
