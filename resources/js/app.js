import './bootstrap';
import { initRfqItems } from './rfq-items';
import { initAuction } from './auction';
import { initQuoteForm } from './quote-form';
import { initLivePage } from './live-page';
import { initCountdowns } from './countdowns';
import { initBilling } from './billing';
import { initPwa } from './pwa';
import { initShell } from './shell';

document.addEventListener('DOMContentLoaded', () => {
    initRfqItems();
    initAuction();
    initQuoteForm();
    initLivePage();
    initCountdowns();
    initBilling();
    initPwa();
    initShell();

    // Copy-to-clipboard buttons: <button data-copy="text">
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-copy]');
        if (!btn) return;
        try {
            await navigator.clipboard.writeText(btn.dataset.copy);
            const label = btn.textContent;
            btn.textContent = 'Copied';
            setTimeout(() => { btn.textContent = label; }, 1500);
        } catch {
            window.prompt('Copy this link:', btn.dataset.copy);
        }
    });

    // AI RFQ: wait for the read to finish, then open the filled form.
    const aiJob = document.querySelector('[data-ai-job]');
    if (aiJob && !aiJob.querySelector('[data-ai-failed]:not([hidden])')) {
        const poll = async () => {
            try {
                const res = await fetch(aiJob.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const data = await res.json();
                if (data.next) return window.location.assign(data.next);
                if (data.status === 'failed') {
                    aiJob.querySelector('[data-ai-working]').hidden = true;
                    aiJob.querySelector('[data-ai-failed]').hidden = false;
                    aiJob.querySelector('[data-ai-error]').textContent = data.error || 'Please try again or fill the form yourself.';
                    return;
                }
            } catch { /* network blip: keep waiting */ }
            setTimeout(poll, 2000);
        };
        setTimeout(poll, 1500);
    }
    document.querySelector('[data-ai-example]')?.addEventListener('click', (e) => {
        const box = document.getElementById('ai_text');
        if (box) { box.value = e.currentTarget.dataset.example; box.focus(); }
    });
    document.querySelector('[data-ai-form]')?.addEventListener('submit', (e) => {
        const form = e.currentTarget;
        if (!form.ai_text.value.trim() && !form.ai_file.files.length) {
            e.preventDefault();
            form.ai_text.focus();
            form.ai_text.setCustomValidity('Paste your requirement here, or choose a file.');
            form.ai_text.reportValidity();
            form.ai_text.addEventListener('input', () => form.ai_text.setCustomValidity(''), { once: true });
            return;
        }
        const btn = form.querySelector('[data-ai-submit]');
        if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }
    });

    // RFQ form: a yellow "Check this" field clears once the buyer edits or confirms it.
    const clearDoubt = (e) => {
        const el = e.target;
        if (!el.classList?.contains('ai-doubt')) return;
        el.classList.remove('ai-doubt');
        el.parentElement?.querySelector('[data-ai-doubt-note]')?.remove();
    };
    document.addEventListener('input', clearDoubt);
    document.addEventListener('change', clearDoubt);

    // Admin Overview charts: only loaded on pages that have them.
    if (document.querySelector('canvas[data-chart]')) {
        import('./admin-charts').then(({ initAdminCharts }) => initAdminCharts()).catch(() => {});
    }

    // Admin two-step setup: draw the authenticator QR code locally (the secret never leaves the page).
    const qr = document.querySelector('[data-qr]');
    if (qr) {
        import('qrcode').then(({ default: QRCode }) => QRCode.toCanvas(qr, qr.dataset.qr, { width: 208, margin: 1 }))
            .catch(() => { qr.hidden = true; });
    }

    // Award form: the "why not L1?" box appears only when a non-L1 supplier is picked.
    document.addEventListener('change', (e) => {
        const form = e.target.closest('[data-award-form]');
        if (!form || e.target.name !== 'supplier_org_id') return;
        const box = form.querySelector('[data-award-reason]');
        if (box) box.hidden = e.target.dataset.rank === '1';
    });

    // Item-wise award form: live combined total, number of POs, and the reason box when any item
    // isn't going to its L1.
    const itemAward = document.querySelector('[data-item-award]');
    if (itemAward) {
        const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });
        const update = () => {
            let total = 0;
            let offL1 = false;
            const suppliers = new Set();
            itemAward.querySelectorAll('select[data-item]').forEach((sel) => {
                const opt = sel.selectedOptions[0];
                if (!opt) return;
                total += Number(opt.dataset.total || 0);
                offL1 = offL1 || opt.dataset.rank !== '1';
                suppliers.add(sel.value);
                sel.closest('tr')?.toggleAttribute('data-off-l1', opt.dataset.rank !== '1');
            });
            const t = itemAward.querySelector('[data-award-total]');
            if (t) t.textContent = inr.format(total);
            const n = itemAward.querySelector('[data-award-pos]');
            if (n) n.textContent = suppliers.size === 1 ? '1 purchase order' : `${suppliers.size} purchase orders (one per supplier)`;
            const box = itemAward.querySelector('[data-award-reason]');
            if (box) box.hidden = !offL1;
        };
        itemAward.addEventListener('change', update);
        itemAward.querySelectorAll('[data-all-to]').forEach((btn) => btn.addEventListener('click', () => {
            const id = btn.dataset.allTo;
            itemAward.querySelectorAll('select[data-item]').forEach((sel) => {
                if ([...sel.options].some((o) => o.value === id)) sel.value = id;
            });
            update();
        }));
        itemAward.querySelector('[data-all-l1]')?.addEventListener('click', () => {
            itemAward.querySelectorAll('select[data-item]').forEach((sel) => { sel.selectedIndex = 0; });
            update();
        });
        update();
    }

    // Auction form: show the fields for the chosen auction type; hidden fields are disabled so
    // they are neither validated by the browser nor sent.
    const auctionForm = document.querySelector('[data-auction-form]');
    if (auctionForm) {
        const applyFormat = () => {
            const chosen = auctionForm.querySelector('[data-format-switch]:checked')?.value ?? 'english_reverse';
            auctionForm.querySelectorAll('[data-format-only]').forEach((el) => {
                const on = el.dataset.formatOnly === chosen;
                el.hidden = !on;
                el.querySelectorAll('input, select, textarea').forEach((f) => { f.disabled = !on; });
            });
            auctionForm.querySelectorAll('[data-text-japanese]').forEach((el) => {
                el.dataset.textEnglish ??= el.textContent;
                el.textContent = chosen === 'japanese' ? el.dataset.textJapanese : el.dataset.textEnglish;
            });
        };
        auctionForm.addEventListener('change', (e) => { if (e.target.matches('[data-format-switch]')) applyFormat(); });
        applyFormat();
    }

    // Confirm before destructive actions: <form data-confirm="Are you sure?">
    document.addEventListener('submit', (e) => {
        const msg = e.target.dataset?.confirm;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
});
