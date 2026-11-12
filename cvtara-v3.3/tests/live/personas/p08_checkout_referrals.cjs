// Persona 8: a free customer looking at plans, prices, coupons and checkout (STOPS before paying), referrals, wallet, account.
const { run, accounts, sleep, BASE } = require('../lib.cjs');
run('p08-checkout-referrals', 'Free customer: packages, price quotes, coupon, checkout up to the payment step (no payment), referrals, wallet, profile', async p => {
  const a = accounts(); const me = a.customers[7]; let plans = {};
  await p.step('function', 'referral link /r/<code> remembers the referrer (signed out)', async rec => {
    const r = await fetch(`${BASE}/r/${a.customers[0].referral}`, { redirect: 'manual' }); rec.notes.push(`${r.status} → ${r.headers.get('location')}`);
    if (r.status !== 302 || !/register/.test(r.headers.get('location') || '')) throw new Error('referral link did not go to sign-up'); if (!/cvtara_ref=/.test(r.headers.get('set-cookie') || '')) p.warn(rec, 'no referral cookie set');
  });
  await p.step('page', '/pricing/ (signed out)', async rec => { await p.goto('/pricing/'); await p.pageChecks(rec); });
  await p.step('function', 'sign in', async () => p.login(me.email, me.password), { critical: true });
  await p.step('function', 'packages and prices (business-public)', async rec => { const j = await p.call(rec, 'GET', 'business-public'); plans = j.plans || {}; rec.notes.push(Object.entries(plans).map(([k, v]) => `${k} ₹${v.price ?? v.amount ?? '?'}`).join(', ').slice(0, 300)); rec.notes.push('gateways ' + (j.gateways || []).join(',')); if (!(j.gateways || []).length) p.warn(rec, 'no payment gateway switched on'); });
  for (const v of ['billing', 'account', 'referrals', 'wallet', 'referral-history']) await p.step('page', `workspace: ${v}`, async rec => { await p.wsView(rec, v); });
  for (const plan of ['quick', 'job-hunt', 'pro']) await p.step('function', `price quote: ${plan}`, async rec => {
    const q = await p.call(rec, 'POST', 'checkout/quote', { plan }); rec.notes.push(`subtotal ${q.subtotalPaise / 100} discount ${q.discountPaise / 100} total ${q.totalPaise / 100}`);
    if (!(q.totalPaise > 0)) throw new Error('quote total is not positive');
  });
  await p.step('function', 'wrong coupon is refused with a message', async rec => { const q = await p.call(rec, 'POST', 'checkout/quote', { plan: 'job-hunt', coupon: 'QA-NOT-A-REAL-COUPON' }, [200, 422]); rec.notes.push(JSON.stringify(q).slice(0, 160)); if (q.discountPaise > 0) throw new Error('fake coupon gave a discount'); }, { expect: /^422/ });
  await p.step('function', 'add-ons quote (Expert Review / Profile Boost)', async rec => { const keys = Object.keys(plans).filter(k => /review|boost|expert|addon|kit/i.test(k)); if (!keys.length) return p.warn(rec, 'no add-on plans listed'); for (const k of keys) { const q = await p.call(rec, 'POST', 'checkout/quote', { plan: k }, [200, 402, 422]); rec.notes.push(`${k}: ${q.totalPaise ? q.totalPaise / 100 : q.error}`); } });
  await p.step('function', 'checkout page up to the payment button (NO payment)', async rec => {
    await p.goto('/checkout.html?plan=job-hunt'); await sleep(2500); await p.pageChecks(rec);
    const pay = p.page.locator('button:visible', { hasText: /pay|continue|proceed|buy/i }).first(); if (!(await pay.count())) throw new Error('no pay button on checkout'); rec.notes.push('pay button: "' + (await pay.innerText()).trim().slice(0, 40) + '" (not clicked)');
  });
  await p.step('function', 'checkout start creates an unpaid gateway order (then abandoned)', async rec => {
    const j = await p.call(rec, 'POST', 'checkout/start', { plan: 'quick' }, [200, 422, 503]); rec.notes.push(Object.keys(j).join(',').slice(0, 200));
    if (!j.ok) p.warn(rec, 'checkout could not start: ' + (j.error || '')); else if (!(j.orderId || j.order || j.redirectUrl || j.key || j.checkout)) p.warn(rec, 'no gateway order details in the answer');
  });
  await p.step('function', 'orders, allowance, credits history', async rec => { await p.call(rec, 'GET', 'purchases'); await p.call(rec, 'GET', 'allowance'); await p.call(rec, 'GET', 'credits/history'); await p.call(rec, 'GET', 'downloads'); });
  await p.step('function', 'refund without a paid order is refused', async rec => { await p.call(rec, 'POST', 'refunds', { purchaseId: '00000000-0000-0000-0000-000000000000' }, [404, 409, 422]); }, { expect: /^4(04|09|22)/ });
  await p.step('function', 'referrals, rewards and wallet', async rec => { await p.call(rec, 'GET', 'referrals'); await p.call(rec, 'GET', 'referral-rewards'); await p.call(rec, 'GET', 'wallet'); });
  await p.step('function', 'withdrawal below the minimum is refused', async rec => { await p.call(rec, 'POST', 'referrals/withdraw', { amount: 1, method: 'upi', destination: 'qa@upi' }, [403, 409, 422]); }, { expect: /^4(03|09|22)/ });
  await p.step('function', 'profile update (name, target role)', async rec => { await p.call(rec, 'POST', 'profile', { name: 'QA Tester 08', experienceLevel: 'fresher', profession: 'Data Analyst', category: 'Data & Analytics', experienceYears: 0, marketingConsent: false }); const j = await p.call(rec, 'GET', 'profile'); if (!/QA Tester 08/.test(JSON.stringify(j))) p.warn(rec, 'saved name not returned'); });
  await p.step('function', 'security status (sign-in codes)', async rec => { await p.call(rec, 'GET', 'security/status'); });
});
