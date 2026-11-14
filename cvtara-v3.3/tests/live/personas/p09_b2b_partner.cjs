// Persona 9: B2B. A college placement cell applies to partner, a student arrives through a partner link,
// the partner manager opens the partner portal, and a customer deletes their account (data rights).
const { run, accounts, sleep, BASE } = require('../lib.cjs');
run('p09-b2b-partner', 'B2B: partner page + application, partner link tracking, partner portal, and account deletion', async p => {
  const a = accounts(); const partner = a.customers[9], quitter = a.customers[8];
  await p.step('page', '/partners.html', async rec => { await p.goto('/partners.html'); await p.pageChecks(rec); });
  await p.step('function', 'partner application form (placement cell)', async rec => {
    const r = await p.api('POST', 'partners/apply', { organisation: 'QA TEST College (delete me)', kind: 'college', name: 'QA Placement Officer', email: 'cvtara-qa-partner-apply@example.com', city: 'Bengaluru', message: 'QA TEST: automated check, please ignore.' });
    rec.notes.push(`${r.status} ${JSON.stringify(r.json).slice(0, 120)}`); if (!(r.status === 200 || (r.status === 422 && /already/.test(JSON.stringify(r.json))))) throw new Error('application not accepted');
  }, { expect: /^422/ });
  await p.step('function', 'partner form in the UI shows its fields', async rec => { await p.goto('/partners.html'); const n = await p.page.locator('form input:visible, form select:visible, form textarea:visible').count(); rec.notes.push(`${n} fields`); if (n < 3) p.warn(rec, 'partner form not visible'); });
  await p.step('function', 'bot honeypot on the partner form', async rec => { await p.call(rec, 'POST', 'partners/apply', { organisation: 'Spam', kind: 'other', name: 'S', email: 'cvtara-qa-partner-apply@example.com', website: 'x' }, [422, 429]); }, { expect: /^4(22|29)/ });
  await p.step('function', 'partner link /p/qa-test-partner tracks and redirects', async rec => {
    const r = await fetch(`${BASE}/p/${a.partner.link}?to=/register`, { redirect: 'manual' }); rec.notes.push(`${r.status} → ${r.headers.get('location')}`);
    if (r.status !== 302) throw new Error('partner link answered ' + r.status); if (!/register/.test(r.headers.get('location') || '')) p.warn(rec, 'did not go to sign-up');
    const bad = await fetch(`${BASE}/p/${a.partner.link}?to=https://evil.example`, { redirect: 'manual' }); if (/evil/.test(bad.headers.get('location') || '')) throw new Error('open redirect through partner link');
  });
  await p.step('function', 'partner manager signs in', async () => p.login(partner.email, partner.password), { critical: true });
  await p.step('function', 'partner portal numbers', async rec => { const j = await p.call(rec, 'GET', 'partner'); rec.notes.push(JSON.stringify(j).slice(0, 200)); });
  await p.step('function', 'partner invites and payout details', async rec => { await p.call(rec, 'GET', 'partner/invites', null, [200, 404]); await p.call(rec, 'GET', 'partner/payout', null, [200, 404, 405]); }, { expect: /^40[45]/ });
  await p.step('page', 'partner portal screen', async rec => {
    await p.goto('/workspace.html'); await sleep(2500); await p.wsView(rec, 'partner'); const t = await p.page.innerText('#view-partner').catch(() => ''); rec.notes.push(t.replace(/\s+/g, ' ').slice(0, 160)); if (/Loading…/.test(t) && t.length < 40) p.warn(rec, 'portal still loading');
  });
  await p.step('function', 'customer deletes their account (password + DELETE)', async rec => {
    await p.page.context().clearCookies(); await p.login(quitter.email, quitter.password);
    await p.call(rec, 'POST', 'account/delete', { password: 'wrong-password', confirm: 'DELETE' }, [403, 422]);
    await p.call(rec, 'POST', 'account/delete', { password: quitter.password, confirm: 'DELETE' });
    await p.page.context().clearCookies(); await p.goto('/login'); await p.page.fill('input[name=email]', quitter.email); await p.page.fill('input[name=password]', quitter.password); await p.page.click('button.au-go'); await sleep(2000);
    if (/workspace/.test(p.page.url())) throw new Error('deleted account can still sign in');
  }, { expect: /^4(03|22)/ });
});
