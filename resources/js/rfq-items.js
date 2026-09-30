// RFQ form: add / remove item rows. Keys don't need to be contiguous; the server re-numbers lines.
export function initRfqItems(root = document) {
    const list = root.querySelector('[data-items]');
    const template = root.querySelector('template[data-item-template]');
    const addBtn = root.querySelector('[data-add-item]');
    if (!list || !template || !addBtn) return;

    const MAX = 100;
    let next = list.querySelectorAll('[data-item-row]').length + Date.now() % 100000;

    const refresh = () => {
        const rows = list.querySelectorAll('[data-item-row]');
        rows.forEach((row) => {
            const btn = row.querySelector('[data-remove-item]');
            if (btn) btn.disabled = rows.length === 1;
        });
        addBtn.disabled = rows.length >= MAX;
    };

    addBtn.addEventListener('click', () => {
        const html = template.innerHTML.replaceAll('__i__', String(next++));
        list.insertAdjacentHTML('beforeend', html);
        refresh();
        list.lastElementChild?.querySelector('input')?.focus();
    });

    list.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-remove-item]');
        if (!btn) return;
        if (list.querySelectorAll('[data-item-row]').length > 1) {
            btn.closest('[data-item-row]').remove();
            refresh();
        }
    });

    refresh();
}
