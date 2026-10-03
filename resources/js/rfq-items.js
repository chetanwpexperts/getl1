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
    initPriceHints(list);
}

// What was last paid for an item, and any rate contract, shown under the item name. Fills the
// "last price" box when it is empty. Text only (textContent), never HTML.
function initPriceHints(list) {
    const url = list.dataset.priceLookup;
    if (!url) return;
    const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
    const seen = new Map();

    const keyOf = (row) => {
        const name = row.querySelector('input[name$="[name]"]')?.value.trim() || '';
        const spec = row.querySelector('input[name$="[spec]"]')?.value.trim() || '';
        const unit = row.querySelector('select[name$="[unit]"]')?.value || '';
        return { name, spec, unit, key: `${name.toLowerCase()}|${spec.toLowerCase()}|${unit}` };
    };

    async function check(row) {
        const { name, spec, unit, key } = keyOf(row);
        const hint = row.querySelector('[data-price-hint]');
        const last = row.querySelector('input[name$="[last_purchase_price]"]');
        if (!hint) return;
        // A price we filled earlier for a different item is taken back out; a typed one is kept.
        const clearAuto = () => { if (last && last.dataset.auto !== undefined && last.value === last.dataset.auto) last.value = ''; if (last) delete last.dataset.auto; };
        if (!name || name.length < 3 || !unit) { hint.classList.add('hidden'); clearAuto(); return; }
        try {
            if (!seen.has(key)) {
                const q = new URLSearchParams({ name, unit, spec });
                seen.set(key, fetch(`${url}?${q}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : null)));
            }
            const data = await seen.get(key);
            if (keyOf(row).key !== key) return; // the row changed while we waited: a newer check runs
            const parts = [];
            if (data?.last) parts.push(`Last paid ${inr.format(data.last.rate)} (${data.last.supplier}, ${data.last.on})`);
            if (data?.contract) parts.push(`Rate contract ${data.contract.number}: ${inr.format(data.contract.rate)} with ${data.contract.supplier} till ${data.contract.till}`);
            hint.textContent = parts.join(' · ');
            hint.classList.toggle('hidden', parts.length === 0);
            if (last) {
                const auto = last.dataset.auto !== undefined && last.value === last.dataset.auto;
                if (data?.last && (last.value === '' || auto)) { last.value = String(data.last.rate); last.dataset.auto = last.value; }
                else if (!data?.last && auto) clearAuto();
            }
        } catch {
            hint.classList.add('hidden');
        }
    }

    list.addEventListener('change', (e) => {
        if (e.target.matches('input[name$="[name]"], input[name$="[spec]"], select[name$="[unit]"]')) check(e.target.closest('[data-item-row]'));
    });
    list.querySelectorAll('[data-item-row]').forEach((row) => check(row));
}
