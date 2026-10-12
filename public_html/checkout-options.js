(() => {
  'use strict';
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const button = document.getElementById('payPreview'),
    consent = document.getElementById('checkoutConsent'),
    status = document.getElementById('checkoutStatus'),
    phone = document.getElementById('checkoutPhone');
  if (!button) return;
  const PASSES = ['d30', 'd60', 'd90'];
  const ADDONS = ['add10', 'add25'];
  const RUPEE = '\u20B9';
  let active = new URLSearchParams(location.search).get('plan') || 'd30';
  let plans = {}, paid = false, catalog = {}, quote = null, busy = false;
  if (![...PASSES, ...ADDONS].includes(active)) active = 'd30';
  button.textContent = 'Confirm and pay';

  const say = msg => { status.textContent = msg; };
  const validPhone = () => !phone || /^[6-9][0-9]{9}$/.test(phone.value.trim());
  const money = n => RUPEE + Number(n).toLocaleString('en-IN');
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const signedIn = () => (document.querySelector('meta[name=cvtara-user]')?.content || 'guest') !== 'guest';
  const loadScript = (src, ready) => new Promise((resolve, reject) => {
    if (ready()) return resolve();
    const s = document.createElement('script');
    s.src = src; s.onload = resolve;
    s.onerror = () => reject(Error('Payment checkout could not load. Retry without paying again.'));
    document.head.append(s);
  });

  function paintSteps() {
    const step = quote ? 3 : 2;
    document.querySelectorAll('.checkout-steps li').forEach((el, i) => {
      el.classList.toggle('is-active', i === step - 1);
      el.classList.toggle('is-done', i < step - 1);
    });
  }

  function state() {
    const paymentsOff = window.CVTaraOwnerContent?.integrations?.payments === false;
    button.disabled = busy || !consent.checked || paymentsOff || !validPhone() || !plans[active];
    if (busy) return;
    if (paymentsOff) say('Payments are switched off right now. Please try again later.');
    else if (!plans[active]) say('Choose a package to continue.');
    else if (!consent.checked) say('Confirm the total and accept the policies to continue.');
    else if (!validPhone()) say('Enter your 10-digit Indian mobile number. Our payment partner needs it to open checkout.');
    else say('Your payment is handled by our secure payment partner. Access activates after verification.');
    paintSteps();
  }

  function renderCards() {
    const passBox = document.getElementById('checkoutPasses');
    if (passBox) passBox.hidden = paid;
    document.querySelectorAll('.checkout-choice').forEach(label => {
      const key = label.querySelector('input')?.value, p = plans[key];
      if (!p) { label.hidden = true; return; }
      label.hidden = false;
      const set = (sel, fn) => { const el = label.querySelector(sel); if (el) fn(el); };
      set('.choice-label', el => { el.textContent = String(p.name).toUpperCase(); });
      set('b', el => {
        el.replaceChildren(document.createTextNode(money(p.displayPrice)), Object.assign(document.createElement('small'), { textContent: p.addon ? 'add-on' : '' }));
      });
      set('strong', el => { el.textContent = (p.kits || 0) + ' job kits' + (p.accessDays ? ' · ' + p.accessDays + ' days' : ' added to your pass'); });
      set('.choice-features', el => {
        const lines = p.addon
          ? ['Adds to your current pass', (p.kits || 0) + ' extra resumes + ' + (p.kits || 0) + ' cover letters', 'Unused add-on kits continue after this pass ends']
          : [(p.kits || 0) + ' resumes + ' + (p.kits || 0) + ' cover letters', (p.accessDays || 0) + ' days of access', 'No automatic renewal'];
        el.replaceChildren(...lines.flatMap((t, i) => (i ? [document.createElement('br'), document.createTextNode(t)] : [document.createTextNode(t)])));
      });
      set('.choice-select', el => { el.textContent = 'Select ' + String(p.name).toLowerCase(); });
    });
    const addonWrap = document.getElementById('checkoutAddonWrap');
    if (addonWrap) addonWrap.hidden = !paid;
  }

  function renderSummary() {
    const p = plans[active];
    const rupees = quote ? quote.totalPaise / 100 : (p?.displayPrice ?? 0);
    const sub = quote ? quote.subtotalPaise / 100 : (p?.displayPrice ?? 0);
    const disc = quote ? quote.discountPaise / 100 : 0;
    const values = {
      checkoutPlanName: p ? p.name : 'Choose a package',
      checkoutPlanDescription: p ? (p.addon ? 'Extra kits added to your current pass.' : 'Plan allowance applies for the full access period. No automatic renewal.') : 'A job kit is one resume plus its cover letter for one job description.',
      checkoutAccess: p ? (p.accessDays ? p.accessDays + ' days' : 'Current pass') : '—',
      checkoutResumes: p ? p.savedResumes + ' saved resumes' : '—',
      checkoutLetters: p ? 'Editable letter drafts · ' + p.exports + ' shared PDF/Word exports' : '—',
      checkoutSubtotal: p ? money(sub) : '—',
      checkoutDiscount: disc ? '−' + money(disc) : 'None',
      checkoutPrice: p ? money(rupees) : '—',
    };
    for (const [id, value] of Object.entries(values)) { const el = document.getElementById(id); if (el) el.textContent = value; }
    document.querySelectorAll('[data-plan-name]').forEach(e => { e.textContent = p?.name || ''; });
    document.querySelectorAll('[data-plan-price]').forEach(e => { e.textContent = p ? money(rupees) : ''; });
  }

  function render() {
    if (!plans[active]) {
      const next = Object.keys(plans)[0];
      if (next) active = next;
    }
    const url = new URL(location.href);
    url.searchParams.set('plan', active);
    history.replaceState(null, '', url);
    document.querySelectorAll('input[name=access_plan]').forEach(b => { b.checked = b.value === active; });
    document.querySelectorAll('.checkout-choice').forEach(l => l.classList.toggle('selected', l.querySelector('input')?.value === active));
    renderCards();
    renderSummary();
    state();
  }

  async function loadCatalog() {
    if (!signedIn()) {
      location.href = '/login?next=' + encodeURIComponent('/checkout' + location.search);
      return;
    }
    try {
      const d = await api('/api/checkout/catalog');
      catalog = d || {};
      plans = d.plans || {};
      paid = !!d.paid;
      const banner = document.getElementById('checkoutStage');
      const lead = document.getElementById('checkoutLead');
      const until = d.accessUntil ? new Date(d.accessUntil).toLocaleDateString('en-IN', {day:'numeric', month:'short', year:'numeric'}) : '';
      if (banner) {
        if (paid) {
          banner.hidden = false;
          banner.textContent = (d.planName || 'Your pass') + ' is active' + (until ? ' until ' + until : '') + '. You can add extra job kits now. A new starter, growth or pro package is available after this period ends.';
        } else if (d.carriedKits) {
          banner.hidden = false;
          banner.textContent = d.carriedKits + ' unused add-on kits continue on your next pass.';
        } else {
          banner.hidden = true;
          banner.textContent = '';
        }
      }
      if (lead) {
        lead.textContent = paid
          ? 'Add extra kits to the pass you already paid for. Unused add-on kits continue after this period ends.'
          : 'Choose a package for your account. After you have an active pass you can add extra kits only. Unused add-on kits continue after 30, 60 or 90 days end.';
      }
      if (paid && PASSES.includes(active)) active = plans.add10 ? 'add10' : (Object.keys(plans)[0] || active);
      if (!paid && ADDONS.includes(active)) active = 'd30';
      render();
    } catch (e) {
      say(e.message || 'Could not load your packages. Sign in and retry.');
    }
  }

  async function applyCoupon() {
    const box = document.getElementById('couponStatus');
    const code = (document.getElementById('couponCode')?.value || '').trim().toUpperCase();
    window.CVTaraCoupon = null;
    quote = null;
    if (!code) { if (box) box.textContent = 'Enter a coupon, or continue without one.'; render(); return; }
    try {
      quote = await api('/api/checkout/quote', { method: 'POST', body: { plan: active, coupon: code } });
      window.CVTaraCoupon = { code, plan: active };
      if (box) box.textContent = 'Coupon applied. Check the total, then confirm and pay.';
    } catch (e) {
      quote = null;
      if (box) box.textContent = e.message;
    }
    render();
  }

  async function verifyCashfree(orderId, attempts = 4) {
    let last = null;
    for (let i = 0; i < attempts; i++) {
      try {
        await api('/api/checkout/verify', { method: 'POST', body: { provider: 'cashfree', orderId } });
        return true;
      } catch (e) { last = e; if (i < attempts - 1) await sleep(2500); }
    }
    throw last || Error('Payment is not confirmed yet.');
  }
  const paidDone = () => { say('Payment verified. Opening your account…'); location.href = '/workspace?view=account'; };
  const notConfirmed = e => say((e && e.message ? e.message : 'Payment is not confirmed yet.') + ' If money was debited, do not pay again. Confirmation may still arrive and your access will activate automatically.');

  async function payWithCashfree(order) {
    await loadScript('https://sdk.cashfree.com/js/v3/cashfree.js', () => window.Cashfree);
    const cashfree = window.Cashfree({ mode: order.mode === 'production' ? 'production' : 'sandbox' });
    say(order.test ? 'TEST PAYMENT – no real charge. Complete it in the secure window.' : 'Complete your payment in the secure window.');
    const result = await cashfree.checkout({ paymentSessionId: order.paymentSessionId, redirectTarget: '_modal' });
    if (result && result.error && !result.paymentDetails) {
      say((result.error.message || 'Payment was not completed.') + ' You can retry when ready.');
      try { await verifyCashfree(order.orderId, 1); return paidDone(); } catch (_) { return; }
    }
    say('Verifying payment…');
    try { await verifyCashfree(order.orderId); paidDone(); } catch (e) { notConfirmed(e); }
  }

  async function payWithRazorpay(order) {
    await loadScript('https://checkout.razorpay.com/v1/checkout.js', () => window.Razorpay);
    const checkout = new window.Razorpay({
      key: order.keyId, order_id: order.orderId, amount: order.amount, currency: order.currency, name: 'CVTara',
      description: order.test ? 'TEST PAYMENT – no real charge' : plans[active]?.name || active,
      handler: async response => {
        try {
          say('Verifying payment…');
          await api('/api/checkout/verify', { method: 'POST', body: response });
          paidDone();
        } catch (e) { notConfirmed(e); }
      },
      modal: { ondismiss: () => { say('Checkout closed. You can retry when ready.'); busy = false; state(); } },
    });
    checkout.on('payment.failed', () => say('Payment was not completed. Check the gateway message before retrying.'));
    checkout.open();
  }

  button.onclick = async () => {
    busy = true; button.disabled = true; say('Creating your secure order…');
    try {
      const order = await api('/api/checkout/start', {
        method: 'POST',
        body: { plan: active, phone: phone ? phone.value.trim() : '', coupon: window.CVTaraCoupon?.plan === active ? window.CVTaraCoupon.code : '' },
      });
      if (order.provider === 'cashfree') await payWithCashfree(order); else await payWithRazorpay(order);
    } catch (e) { say(e.message); }
    finally { busy = false; state(); }
  };

  document.querySelectorAll('input[name=access_plan]').forEach(b => {
    b.onchange = () => { active = b.value; quote = null; window.CVTaraCoupon = null; const s = document.getElementById('couponStatus'); if (s) s.textContent = 'Package changed. Apply the coupon again if you have one.'; render(); };
  });
  consent.onchange = state;
  if (phone) phone.addEventListener('input', () => { phone.value = phone.value.replace(/[^0-9]/g, '').slice(0, 10); state(); });
  const slider = document.getElementById('addonSlider');
  const hint = document.getElementById('addonSliderHint');
  function paintSlider() {
    if (!slider || !hint) return;
    const map = {0: '', 10: 'add10', 25: 'add25'};
    const v = Number(slider.value);
    const key = v <= 5 ? '' : (v < 18 ? 'add10' : 'add25');
    slider.value = key === 'add10' ? 10 : key === 'add25' ? 25 : 0;
    hint.textContent = key === 'add10' ? 'Add 10 more packages.' : key === 'add25' ? 'Add 25 more packages.' : 'No extra kits selected.';
    if (key && plans[key]) { active = key; quote = null; render(); }
    else if (ADDONS.includes(active) && !paid) { active = 'd30'; quote = null; render(); }
  }
  slider?.addEventListener('input', paintSlider);
  document.getElementById('applyCoupon')?.addEventListener('click', applyCoupon);

  const returned = new URLSearchParams(location.search).get('cashfree_order');
  if (returned && /^cvt_[a-f0-9]{32}$/.test(returned)) {
    busy = true; button.disabled = true; say('Checking your payment…');
    const clean = new URL(location.href); clean.searchParams.delete('cashfree_order'); history.replaceState(null, '', clean);
    (async () => {
      for (let i = 0; i < 20 && !window.CVTaraAPI; i++) await sleep(250);
      try { await verifyCashfree(returned, 5); paidDone(); } catch (e) { busy = false; button.disabled = !consent.checked || !validPhone(); notConfirmed(e); }
    })();
  }

  (async () => {
    for (let i = 0; i < 40 && !window.CVTaraAPI; i++) await sleep(100);
    await loadCatalog();
  })();
})();
