// Supplier quote form: live line amounts and totals. The server recomputes everything on submit.
const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
const num = (v) => {
    const n = parseFloat(String(v ?? '').replace(/[,\s₹]/g, ''));
    return Number.isFinite(n) && n > 0 ? n : 0;
};
const r2 = (n) => Math.round(n * 100) / 100;

export function initQuoteForm(root = document) {
    const form = root.querySelector('[data-quote-form]');
    if (!form) return;

    const set = (sel, text) => {
        const el = form.querySelector(sel);
        if (el) el.textContent = text;
    };

    const recalc = () => {
        let basic = 0, gst = 0, freight = 0;
        form.querySelectorAll('[data-quote-row]').forEach((row) => {
            const qty = num(row.dataset.qty);
            const price = num(row.querySelector('[data-price]')?.value);
            const rate = num(row.querySelector('[data-gst]')?.value);
            const fr = num(row.querySelector('[data-freight]')?.value);
            const amount = r2(qty * price);
            basic += amount;
            gst += r2(amount * rate / 100);
            freight += r2(fr);
            const cell = row.querySelector('[data-line-amount]');
            if (cell) cell.textContent = price ? inr.format(amount) : '—';
        });
        const any = basic > 0;
        set('[data-sum-basic]', any ? inr.format(r2(basic)) : '—');
        set('[data-sum-gst]', any ? inr.format(r2(gst)) : '—');
        set('[data-sum-freight]', any ? inr.format(r2(freight)) : '—');
        set('[data-sum-landed]', any ? inr.format(r2(basic + gst + freight)) : '—');
    };

    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    recalc();
}
