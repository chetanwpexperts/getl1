import './bootstrap';
import { initRfqItems } from './rfq-items';
import { initAuction } from './auction';
import { initQuoteForm } from './quote-form';
import { initLivePage } from './live-page';
import { initCountdowns } from './countdowns';
import { initBilling } from './billing';

document.addEventListener('DOMContentLoaded', () => {
    initRfqItems();
    initAuction();
    initQuoteForm();
    initLivePage();
    initCountdowns();
    initBilling();

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
    document.querySelector('[data-ai-form]')?.addEventListener('submit', (e) => {
        const btn = e.currentTarget.querySelector('[data-ai-submit]');
        if (btn) { btn.disabled = true; btn.textContent = 'Uploading…'; }
    });

    // Award form: the "why not L1?" box appears only when a non-L1 supplier is picked.
    document.addEventListener('change', (e) => {
        const form = e.target.closest('[data-award-form]');
        if (!form || e.target.name !== 'supplier_org_id') return;
        const box = form.querySelector('[data-award-reason]');
        if (box) box.hidden = e.target.dataset.rank === '1';
    });

    // Confirm before destructive actions: <form data-confirm="Are you sure?">
    document.addEventListener('submit', (e) => {
        const msg = e.target.dataset?.confirm;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
});
