import './bootstrap';
import { initRfqItems } from './rfq-items';
import { initAuction } from './auction';
import { initQuoteForm } from './quote-form';

document.addEventListener('DOMContentLoaded', () => {
    initRfqItems();
    initAuction();
    initQuoteForm();

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

    // Confirm before destructive actions: <form data-confirm="Are you sure?">
    document.addEventListener('submit', (e) => {
        const msg = e.target.dataset?.confirm;
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
});
