// Persona 3: a new fresher signing up, plus every sign-in / password / owner-door path.
const { run, accounts, sleep } = require('../lib.cjs');
const RUN = process.env.QA_RUN || String(Date.now()).slice(-6);
run('p03-signup-auth', 'New visitor: sign-up form, email code page, sign-in, wrong password, forgot/reset password, owner door', async p => {
  const a = accounts(); const me = a.customers[2]; const page = p.page;
  const fill = async (v) => { for (const [k, val] of Object.entries(v)) { const el = page.locator(`[name="${k}"]`).first(); if (!(await el.count())) continue; const tag = await el.evaluate(e => e.tagName + ':' + e.type); if (tag.startsWith('SELECT')) await el.selectOption({ index: 1 }).catch(() => {}); else if (/checkbox/.test(tag)) { if (val) await el.check({ force: true }); } else await el.fill(val); } };
  const submit = async () => { await Promise.all([page.waitForLoadState('domcontentloaded').catch(() => {}), page.click('button.au-go')]); await sleep(1500); const shown = (await page.locator('.error, [role=alert], .au-note').allInnerTexts().catch(() => [])).join(' ').trim(); const invalid = await page.evaluate(() => [...document.querySelectorAll('form :invalid')].map(e => e.name + ': ' + e.validationMessage).join('; ')).catch(() => ''); return (shown + ' ' + invalid).trim(); };
  const signup = { name: 'QA Tester Signup', email: '', password: 'QaTest!2026x', password_confirmation: 'QaTest!2026x', profession: 'Data Analyst', experienceLevel: 'x', category: 'x', terms: true };
  for (const [label, over, want] of [
    ['sign-up: empty form shows errors', { name: '', email: '', password: '', password_confirmation: '', terms: false }, /./],
    ['sign-up: passwords that do not match', { email: `cvtara-qa-signup-02@example.com`, password_confirmation: 'Different!2026' }, /match/i],
    ['sign-up: password under 8 characters', { email: `cvtara-qa-signup-02@example.com`, password: 'Ab1!', password_confirmation: 'Ab1!' }, /8 characters/i],
    ['sign-up: email already used', { email: me.email }, /already exists/i],
  ]) await p.step('function', label, async rec => {
    await p.goto('/register'); await fill({ ...signup, ...over });
    if (!over.terms && over.terms !== undefined) await page.evaluate(() => document.querySelectorAll('form [required]').forEach(e => e.removeAttribute('required')));
    const msg = await submit();
    if (!/register/.test(page.url())) throw new Error('form was accepted, went to ' + page.url());
    const body = await page.innerText('body'); if (!want.test(msg + ' ' + body)) p.warn(rec, `error message not as expected: "${msg.slice(0, 160)}"`);
  }, { expect: /^4(22|19)/ });
  await p.step('function', 'sign-up: new account reaches the email-code page', async rec => {
    await p.goto('/register'); await fill({ ...signup, email: process.env.QA_SIGNUP || 'cvtara-qa-signup-01@example.com' });
    const msg = await submit();
    if (!/verify-email|workspace/.test(page.url())) throw new Error(`stayed on ${page.url()}: ${msg.slice(0, 200)}`);
    rec.notes.push('landed on ' + page.url().replace(/^https?:\/\/[^/]+/, ''));
    await p.pageChecks(rec);
  }, { critical: false });
  await p.step('function', 'email-code page: wrong code is refused with a message', async rec => {
    if (!/verify-email/.test(page.url())) return 'skip';
    await page.fill('input[name=code]', '000000'); const msg = await submit();
    if (!/verify-email/.test(page.url())) throw new Error('wrong code was accepted');
    if (!msg) p.warn(rec, 'no message shown for a wrong code');
  }, { expect: /^4(22|29)/ });
  await p.step('function', 'email-code page: resend code button', async rec => {
    if (!/verify-email/.test(page.url())) return 'skip';
    const b = page.locator('button.au-ghost, form[action*=resend] button').first(); if (!(await b.count())) throw new Error('no resend button');
    await b.click(); await sleep(1500); await p.pageChecks(rec);
  });
  await p.step('function', 'sign out', async rec => { await p.goto('/workspace.html'); await page.evaluate(async () => { const t = (await (await fetch('/api/csrf')).json()).token; await fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': t } }); }); await p.goto('/workspace.html'); if (!/login/.test(page.url())) p.warn(rec, 'workspace still open after sign-out: ' + page.url()); });
  await p.step('function', 'sign-in with a wrong password is refused', async rec => {
    await p.goto('/login'); await page.fill('input[name=email]', me.email); await page.fill('input[name=password]', 'wrong-password-qa'); const msg = await submit();
    if (!/login/.test(page.url())) throw new Error('wrong password signed in!'); if (!/incorrect/i.test(msg)) p.warn(rec, `message: "${msg.slice(0, 120)}"`);
  }, { expect: /^4(22|19|29)/ });
  await p.step('function', 'sign-in with the right password opens the workspace', async rec => { await p.login(me.email, me.password); if (!/workspace/.test(page.url())) throw new Error('went to ' + page.url()); await p.pageChecks(rec); });
  await p.step('function', 'forgot password form accepts an email', async rec => {
    await page.context().clearCookies(); await p.goto('/forgot-password'); await page.fill('input[name=email]', me.email); const msg = await submit();
    if (!/queued|sent|reset|if that account/i.test(msg + await page.innerText('body'))) p.warn(rec, 'no confirmation shown');
  });
  await p.step('page', '/reset-password/<bad token>', async rec => { await p.goto('/reset-password/qa-bad-token?email=' + encodeURIComponent(me.email)); await p.pageChecks(rec); });
  await p.step('function', 'owner door refuses a customer account', async rec => {
    await page.context().clearCookies(); const door = (a.owner.paths || ['sanaulla'])[0];
    await p.goto('/' + door); await page.fill('input[name=email]', me.email); await page.fill('input[name=password]', me.password); await submit();
    await p.goto('/admin.html'); const t = await page.innerText('body').catch(() => '');
    if (/Error Log|Business Ops|Approvals/.test(t) && !/sign in/i.test(t)) throw new Error('customer reached the owner console');
  }, { expect: /^4\d\d/ });
  await p.step('function', 'customer cannot open the console scripts', async () => {
    await p.login(me.email, me.password); const r = await page.evaluate(async () => (await fetch('/console/owner.js')).status);
    if (r !== 404) throw new Error('console script answered ' + r);
  }, { expect: /^404/ });
});
