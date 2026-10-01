// Billing page: monthly/yearly switch and Razorpay checkout for plans, auction credits and AI packs.
// The server creates the subscription/order; the browser only opens checkout and sends back
// Razorpay's signed response, which the server verifies before anything is credited.
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function loadCheckout() {
    if (window.Razorpay) return Promise.resolve();
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.onload = resolve;
        s.onerror = () => reject(new Error('Could not load the payment window. Check your connection and try again.'));
        document.head.appendChild(s);
    });
}

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(first || data.message || 'Something went wrong. Please try again.');
    }
    return data;
}

export function initBilling() {
    const root = document.querySelector('[data-billing]');
    if (!root) return;

    const errorBox = root.querySelector('[data-billing-error]');
    const showError = (msg) => {
        errorBox.textContent = msg;
        errorBox.classList.toggle('hidden', !msg);
        if (msg) errorBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    // Monthly / yearly
    let cycle = 'monthly';
    root.querySelectorAll('[data-cycle]').forEach((btn) => btn.addEventListener('click', () => {
        cycle = btn.dataset.cycle;
        root.querySelectorAll('[data-cycle]').forEach((b) => {
            const on = b.dataset.cycle === cycle;
            b.classList.toggle('bg-slate-900', on);
            b.classList.toggle('text-white', on);
            b.classList.toggle('text-slate-700', !on);
        });
        root.querySelectorAll('[data-show-cycle]').forEach((el) => { el.hidden = el.dataset.showCycle !== cycle; });
    }));

    async function pay(button, startUrl, startBody, confirmUrl) {
        showError('');
        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Opening payment…';
        try {
            const [opts] = await Promise.all([post(startUrl, startBody), loadCheckout()]);
            await new Promise((resolve, reject) => {
                const rzp = new window.Razorpay({
                    ...opts,
                    handler: async (resp) => {
                        try {
                            button.textContent = 'Confirming…';
                            const done = await post(confirmUrl, resp);
                            window.location.href = done.redirect;
                            resolve();
                        } catch (e) { reject(e); }
                    },
                    modal: { ondismiss: () => resolve() },
                });
                rzp.on('payment.failed', (r) => reject(new Error(r.error?.description || 'Payment failed. No money was taken.')));
                rzp.open();
            });
        } catch (e) {
            showError(e.message);
        } finally {
            button.disabled = false;
            button.textContent = label;
        }
    }

    root.querySelectorAll('[data-subscribe]').forEach((btn) => btn.addEventListener('click', () => pay(
        btn, root.dataset.subscribeUrl, { plan: btn.dataset.subscribe, cycle }, root.dataset.subscribeConfirmUrl,
    )));

    root.querySelector('[data-buy-credits]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        pay(form.querySelector('button'), root.dataset.creditsUrl, { quantity: Number(form.quantity.value) || 1 }, root.dataset.creditsConfirmUrl);
    });

    // AI packs are one-time orders too, confirmed by the same signed-order endpoint.
    root.querySelector('[data-buy-ai]')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        pay(form.querySelector('button'), root.dataset.aiPacksUrl, { quantity: Number(form.quantity.value) || 1 }, root.dataset.creditsConfirmUrl);
    });
}
