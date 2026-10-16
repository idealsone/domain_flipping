(() => {
  'use strict';
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const button = document.getElementById('payPreview'), consent = document.getElementById('checkoutConsent'), status = document.getElementById('checkoutStatus');
  if (!button) return;
  const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN');
  const guest = (document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest';
  let active = new URLSearchParams(location.search).get('plan') || '', plans = {};
  const set = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
  function state() {
    const live = window.CVTaraOwnerContent?.integrations?.payments !== false;
    button.textContent = guest ? 'Sign in to continue' : 'Continue to secure payment';
    button.disabled = !guest && (!consent.checked || !live || !plans[active]);
    status.innerHTML = guest ? 'Please <a href="/login?next=' + encodeURIComponent('/checkout.html?plan=' + active) + '">sign in</a> or <a href="/register?next=' + encodeURIComponent('/checkout.html?plan=' + active) + '">create a free account</a> to buy a plan.'
      : !live ? 'Payments are not enabled yet. Please check back soon.'
      : consent.checked ? 'Your payment is handled securely by Cashfree. Access activates after verification.' : 'Accept the policies to continue.';
  }
  function render() {
    const p = plans[active];
    if (!p) return;
    const url = new URL(location.href); url.searchParams.set('plan', active); history.replaceState(null, '', url);
    const addon = p.type === 'addon';
    set('checkoutPlanName', p.name); set('checkoutPlanDescription', p.tagline);
    set('checkoutAccess', addon ? 'Valid as long as your current plan' : p.accessDays + ' days');
    set('checkoutResumes', (addon ? '+' : '') + p.savedResumes + ' downloads');
    set('checkoutLetters', (addon ? '+' : '') + p.savedLetters + ' downloads');
    set('checkoutScans', addon ? (p.atsScans ? '+' + p.atsScans + ' scans' : 'Unchanged') : p.atsScans == null ? 'Unlimited' : p.atsScans + ' scans');
    const price = document.getElementById('checkoutPrice'); if (price) price.innerHTML = inr(p.price) + (p.mrp > p.price ? ` <s style="font-weight:500;opacity:.6;font-size:14px">${inr(p.mrp)}</s>` : '');
    state();
  }
  async function init() {
    const data = await window.CVTaraPlans.load();
    plans = data.plans || {};
    if (!plans[active] || plans[active].key === 'free' || active === 'free') {
      const aud = (() => { try { return localStorage.getItem('cvtara-audience') || 'fresher'; } catch { return 'fresher'; } })();
      active = Object.keys(plans).find(k => plans[k].type === 'plan' && plans[k].audience === aud && plans[k].popular) || 'fresher-standard';
    }
    const host = document.getElementById('coPlans');
    await window.CVTaraPlans.mount(host, { mode: 'checkout', selected: active });
    host.addEventListener('cvtara-plan-select', e => { active = e.detail; window.CVTaraCoupon = null; render(); document.querySelector('.co-grid')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
    render();
  }
  consent.onchange = state;
  window.addEventListener('cvtara-owner-content', state);
  const phoneBox = document.createElement('label');
  phoneBox.style.cssText = 'display:grid;gap:4px;margin:10px 0;font-weight:600;font-size:13px';
  phoneBox.innerHTML = 'Mobile number (needed by the payment gateway)<input id="coPhone" type="tel" inputmode="numeric" maxlength="10" autocomplete="tel-national" placeholder="10-digit mobile number" style="padding:10px;border:1px solid var(--line,#cbd5e1);border-radius:10px;font:inherit">';
  const finalNote = document.createElement('p');
  finalNote.style.cssText = 'margin:6px 0;font-size:13px;font-weight:700;color:var(--ink,#0f172a)';
  finalNote.textContent = 'All payments are final and non-refundable.';
  button.parentNode.insertBefore(phoneBox, button); button.parentNode.insertBefore(finalNote, button);
  try { phoneBox.querySelector('input').value = localStorage.getItem('cvtara-phone') || ''; } catch { /* storage blocked */ }
  button.onclick = async () => {
    if (guest) { location.href = '/login?next=' + encodeURIComponent('/checkout.html?plan=' + active); return; }
    button.disabled = true; status.textContent = 'Creating your secure order…';
    try {
      const order = await api('/api/checkout/start', { method: 'POST', body: { plan: active, phone: document.getElementById('coPhone').value.replace(/\D/g, ''), coupon: window.CVTaraCoupon?.plan === active ? window.CVTaraCoupon.code : '', showInActivity: !!document.getElementById('coActivity')?.checked } });
      try { localStorage.setItem('cvtara-phone', document.getElementById('coPhone').value); } catch { /* storage blocked */ }
      if (order.provider === 'demo') {
        status.textContent = 'Demo payment (preview only) — activating your plan…';
        await api('/api/checkout/verify', { method: 'POST', body: { provider: 'demo', orderId: order.orderId } });
        status.textContent = 'Demo payment complete. Opening your dashboard…';
        location.href = '/workspace.html?view=billing';
        return;
      }
      if (order.provider === 'cashfree') {
        if (!window.Cashfree) await new Promise((resolve, reject) => { const s = document.createElement('script'); s.src = 'https://sdk.cashfree.com/js/v3/cashfree.js'; s.onload = resolve; s.onerror = () => reject(Error('Payment checkout could not load. Retry without paying again.')); document.head.append(s); });
        status.textContent = 'Opening secure payment…';
        const result = await Cashfree({ mode: order.mode }).checkout({ paymentSessionId: order.paymentSessionId, redirectTarget: '_modal' });
        if (result && result.error) { status.textContent = (result.error.message || 'Payment was not completed.') + ' If money was debited, do not pay again; check Billing shortly.'; state(); return; }
        status.textContent = 'Verifying payment…';
        await api('/api/checkout/verify', { method: 'POST', body: { provider: 'cashfree', orderId: order.orderId } });
        status.textContent = 'Payment verified. Opening your dashboard…';
        location.href = '/workspace.html?view=billing';
        return;
      }
      if (!window.Razorpay) await new Promise((resolve, reject) => { const s = document.createElement('script'); s.src = 'https://checkout.razorpay.com/v1/checkout.js'; s.onload = resolve; s.onerror = () => reject(Error('Payment checkout could not load. Retry without paying again.')); document.head.append(s); });
      const checkout = new Razorpay({ key: order.keyId, order_id: order.orderId, amount: order.amount, currency: order.currency, name: 'CVTara', description: order.test ? 'TEST PAYMENT — no real charge' : plans[active]?.name || active,
        handler: async response => { try { status.textContent = 'Verifying payment…'; await api('/api/checkout/verify', { method: 'POST', body: response }); status.textContent = 'Payment verified. Opening your dashboard…'; location.href = '/workspace.html?view=billing'; } catch (e) { status.textContent = e.message + ' If money was debited, do not pay again. Your webhook confirmation may still arrive.'; } },
        modal: { ondismiss: () => { status.textContent = 'Checkout closed. You can retry when ready.'; state(); } } });
      checkout.on('payment.failed', () => { status.textContent = 'Payment was not completed. Check the gateway message before retrying.'; });
      checkout.open();
    } catch (e) { status.textContent = e.message; } finally { button.disabled = false; }
  };
  state();
  window.addEventListener('load', () => init().catch(() => { status.textContent = 'Plans could not be loaded. Please refresh.'; }));
})();
