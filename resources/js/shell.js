// App header dropdowns (company switcher, Company menu, account menu):
// close when clicking elsewhere or pressing Escape, and only one open at a time.
export function initShell() {
    const drops = [...document.querySelectorAll('details[data-dropdown]')];
    if (!drops.length) return;
    document.addEventListener('click', (e) => drops.forEach((d) => { if (d.open && !d.contains(e.target)) d.open = false; }));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') drops.forEach((d) => { d.open = false; }); });
    drops.forEach((d) => d.addEventListener('toggle', () => { if (d.open) drops.forEach((o) => { if (o !== d) o.open = false; }); }));
}
