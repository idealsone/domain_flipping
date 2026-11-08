(() => {
  'use strict';
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const button = document.getElementById('payPreview'), consent = document.getElementById('checkoutConsent'), status = document.getElementById('checkoutStatus');
  if (!button) return;
  const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN');
  const guest = (document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest';
  let active = new URLSearchParams(location.search).get('plan') || '', plans = {}, gateways = [], gateway = '';
  const gatewayName = () => (gateways.find(g => g.id === gateway) || gateways[0])?.name || 'our payment partner';
  function renderGateways() {
    const box = document.getElementById('coGateways'), note = document.getElementById('coGatewayNote');
    if (!gateways.some(g => g.id === gateway)) gateway = gateways[0]?.id || '';
    if (note) note.textContent = 'You pay in ' + gatewayName() + '’s secure payment window. CVTara never sees or stores your card or UPI details.';
    if (!box) return;
    box.hidden = gateways.length < 2;
    box.innerHTML = gateways.length < 2 ? '' : '<p style="margin:8px 0 4px;font-weight:700">Pay with</p>' + gateways.map(g => `<label class="checkout-consent" style="margin-top:4px"><input type="radio" name="coGateway" value="${g.id}" ${g.id === gateway ? 'checked' : ''}><span><b>${g.name}</b> · UPI, cards and net banking</span></label>`).join('');
    box.querySelectorAll('input[name=coGateway]').forEach(i => { i.onchange = () => { gateway = i.value; renderGateways(); state(); }; });
  }
  const set = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
  function state() {
    const live = window.CVTaraOwnerContent?.integrations?.payments !== false;
    button.textContent = guest ? 'Sign in to continue' : 'Continue to secure payment';
    button.disabled = !guest && (!consent.checked || !live || !plans[active]);
    status.innerHTML = guest ? 'Please <a href="/login?next=' + encodeURIComponent('/checkout.html?plan=' + active) + '">sign in</a> or <a href="/register?next=' + encodeURIComponent('/checkout.html?plan=' + active) + '">create a free account</a> to buy a plan.'
      : !live ? 'Payments are not enabled yet. Please check back soon.'
      : consent.checked ? 'Your payment is handled securely by ' + gatewayName() + '. Access activates after verification.' : 'Accept the policies to continue.';
  }
  let quoteSeq = 0;
  async function breakdown() {
    const box = document.getElementById('coBreakdown'), p = plans[active];
    if (!box || guest || !p) return;
    const seq = ++quoteSeq;
    const coupon = window.CVTaraCoupon?.plan === active ? window.CVTaraCoupon.code : '';
    const useWallet = document.getElementById('coWallet')?.checked !== false;
    try {
      const q = await api('/api/checkout/quote', { method: 'POST', body: { plan: active, coupon, useWallet } });
      if (seq !== quoteSeq) return;
      if (Array.isArray(q.gateways)) { gateways = q.gateways; renderGateways(); state(); }
      const rows = [['Price', inr(q.listPaise / 100)]];
      if (q.addonDiscountPaise) rows.push(['Add-on price with your plan', '− ' + inr(q.addonDiscountPaise / 100)]);
      if (q.discountPaise) rows.push(['Coupon ' + q.code, '− ' + inr(q.discountPaise / 100)]);
      if (q.upgradeCreditPaise) rows.push(['Quick Download credit', '− ' + inr(q.upgradeCreditPaise / 100)]);
      if (q.referralDiscountPaise) rows.push(['₹50 referral discount applied', '− ' + inr(q.referralDiscountPaise / 100)]);
      if (q.walletPaise) rows.push(['Wallet credit', '− ' + inr(q.walletPaise / 100)]);
      box.innerHTML = rows.map(([l, v]) => `<div><dt>${l}</dt><dd>${v}</dd></div>`).join('')
        + (q.walletBalancePaise ? `<label class="co-wallet"><input type="checkbox" id="coWallet" ${useWallet ? 'checked' : ''}> Use my wallet credit (${inr(q.walletBalancePaise / 100)} available)</label>` : '');
      const w = document.getElementById('coWallet'); if (w) w.onchange = breakdown;
      const price = document.getElementById('checkoutPrice'); if (price) price.textContent = inr(q.totalPaise / 100);
    } catch (e) { if (seq === quoteSeq) box.innerHTML = `<p role="alert">${e.message}</p>`; }
  }
  function render() {
    const p = plans[active];
    if (!p) return;
    const url = new URL(location.href); url.searchParams.set('plan', active); history.replaceState(null, '', url);
    const addon = p.type === 'addon', service = p.type === 'service';
    set('checkoutPlanName', p.name); set('checkoutPlanDescription', p.tagline);
    set('checkoutAccess', active === 'expert-review' ? 'Prepared by the CVTara team from your saved resume, then emailed and kept in your account' : service ? 'Done by the CVTara team within 48 working hours' : addon ? 'Valid as long as your current plan' : p.accessDays + ' days, no auto-renewal');
    set('checkoutResumes', service ? '—' : p.oneResume ? '1 resume, edit and re-download for 7 days' : (addon ? '+' : '') + p.savedResumes + ' tailored resumes');
    set('checkoutLetters', service ? '—' : (addon ? '+' : '') + p.savedLetters + ' cover letters');
    set('checkoutScans', service ? '—' : p.oneResume ? '3 free checks a day' : addon ? (p.atsScans ? '+' + p.atsScans + ' scans' : 'Unchanged') : p.atsScans == null ? 'Unlimited' : p.atsScans + ' scans');
    const price = document.getElementById('checkoutPrice'); if (price) price.innerHTML = inr(p.price) + (p.mrp > p.price ? ` <s style="font-weight:500;opacity:.6;font-size:14px">${inr(p.mrp)}</s>` : '');
    state(); breakdown();
  }
  async function init() {
    const data = await window.CVTaraPlans.load();
    plans = data.plans || {};
    if (!plans[active] || plans[active].sale === false || plans[active].limitReached || active === 'free') {
      const aud = (() => { try { return localStorage.getItem('cvtara-audience') || 'fresher'; } catch { return 'fresher'; } })();
      active = Object.keys(plans).find(k => plans[k].type === 'plan' && plans[k].sale !== false && plans[k].popular && (plans[k].audience === aud || plans[k].audience === 'all')) || 'job-hunt';
    }
    const host = document.getElementById('coPlans');
    await window.CVTaraPlans.mount(host, { mode: 'checkout', selected: active });
    host.addEventListener('cvtara-plan-select', e => { active = e.detail; window.CVTaraCoupon = null; render(); document.querySelector('.co-grid')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
    render();
  }
  consent.onchange = state;
  window.addEventListener('cvtara-owner-content', state);
  window.addEventListener('cvtara-coupon', breakdown);
  const finalNote = document.createElement('p');
  finalNote.style.cssText = 'margin:6px 0;font-size:13px;font-weight:700;color:var(--ink,#0f172a)';
  finalNote.textContent = '7-day refund if unused · Pay once, no auto-renewal · UPI, cards and net banking.';
  button.parentNode.insertBefore(finalNote, button);
  button.onclick = async () => {
    if (guest) { location.href = '/login?next=' + encodeURIComponent('/checkout.html?plan=' + active); return; }
    button.disabled = true; status.textContent = 'Creating your secure order…';
    try {
      const order = await api('/api/checkout/start', { method: 'POST', body: { plan: active, coupon: window.CVTaraCoupon?.plan === active ? window.CVTaraCoupon.code : '', useWallet: document.getElementById('coWallet')?.checked !== false, gateway: gateway || undefined, showInActivity: !!document.getElementById('coActivity')?.checked } });
      if (order.provider === 'demo') {
        status.textContent = 'Demo payment (preview only) — activating your plan…';
        await api('/api/checkout/verify', { method: 'POST', body: { provider: 'demo', orderId: order.orderId } });
        status.textContent = 'Demo payment complete. Opening your dashboard…';
        location.href = '/workspace.html?view=billing';
        return;
      }
      if (order.provider === 'phonepe') {
        status.textContent = 'Opening PhonePe’s secure payment page…';
        location.href = order.redirectUrl;
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
  // Back from PhonePe's payment page: the server asks PhonePe for the result before granting anything.
  async function phonepeReturn(orderId) {
    button.disabled = true;
    for (let i = 0; i < 12; i++) {
      status.textContent = i ? 'Waiting for PhonePe to confirm your payment…' : 'Checking your PhonePe payment…';
      try {
        const r = await api('/api/checkout/verify', { method: 'POST', body: { provider: 'phonepe', orderId } });
        if (r.state === 'COMPLETED') { status.textContent = 'Payment verified. Opening your dashboard…'; location.href = '/workspace.html?view=billing'; return; }
        if (r.state === 'FAILED') { status.textContent = 'Payment was not completed and no money was taken for this order. You can try again.'; break; }
      } catch (e) { status.textContent = e.message; break; }
      await new Promise(res => setTimeout(res, 5000));
      if (i === 11) status.textContent = 'PhonePe is still processing this payment. If money was debited, do not pay again: your plan activates automatically once PhonePe confirms it.';
    }
    const url = new URL(location.href); url.searchParams.delete('phonepe_order'); history.replaceState(null, '', url);
    state();
  }
  state();
  const returned = new URLSearchParams(location.search).get('phonepe_order');
  if (returned && !guest) phonepeReturn(returned);
  window.addEventListener('load', () => init().catch(() => { status.textContent = 'Plans could not be loaded. Please refresh.'; }));
})();
