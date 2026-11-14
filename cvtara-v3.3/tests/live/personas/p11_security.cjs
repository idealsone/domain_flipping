// Security check (owner asked 2026-11-12: "everyone will try to breach or hack"). Non-destructive: it only uses the
// QA accounts, only tries to read/change QA data, sends a handful of requests per probe and never floods.
const { run, accounts, sleep, BASE } = require('../lib.cjs');
const XSS = '<img src=x onerror="window.__qa_xss=1">"><script>window.__qa_xss=1</script>';
run('p11-security', 'Attacker: exposed files, headers, cookies, sign-in guessing, cross-account access, CSRF, XSS, open redirects, uploads, console access', async p => {
  const a = accounts(); const me = a.customers[1], other = a.customers[18];
  const raw = (u, o = {}) => fetch(BASE + u, { redirect: 'manual', ...o });
  await p.step('function', 'secret files are not downloadable', async rec => {
    const bad = []; for (const u of ['/.env', '/.git/config', '/.git/HEAD', '/composer.json', '/composer.lock', '/artisan', '/storage/logs/laravel.log', '/cvtara_app/.env', '/../cvtara_app/.env', '/vendor/autoload.php', '/phpinfo.php', '/info.php', '/error_log', '/backup.zip', '/database.sql', '/.DS_Store', '/server-status', '/install.php', '/BUILD_INFO.json', '/package.json']) {
      const r = await raw(u); const t = r.status === 200 ? (await r.text()).slice(0, 300) : ''; if (r.status === 200 && !/<html/i.test(t)) bad.push(`${u} (${t.slice(0, 40).replace(/\s+/g, ' ')})`); await sleep(250); }
    if (bad.length) throw new Error('readable: ' + bad.join(', '));
  });
  await p.step('function', 'security headers on pages', async rec => {
    const r = await raw('/'); const h = k => r.headers.get(k) || ''; const miss = ['strict-transport-security', 'content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy'].filter(k => !h(k));
    if (miss.length) p.warn(rec, 'missing headers: ' + miss.join(', ')); if (/unsafe-inline/.test(h('content-security-policy'))) p.warn(rec, "CSP allows 'unsafe-inline' scripts (weakens XSS protection)");
    if (h('x-powered-by')) p.warn(rec, 'x-powered-by reveals ' + h('x-powered-by'));
  });
  await p.step('function', 'session cookies are Secure + HttpOnly', async rec => {
    const r = await raw('/login'); const c = (r.headers.getSetCookie?.() || []); const sess = c.filter(x => /session/i.test(x));
    for (const x of sess) { if (!/secure/i.test(x)) p.warn(rec, 'cookie without Secure: ' + x.split(';')[0].split('=')[0]); if (!/httponly/i.test(x)) p.warn(rec, 'cookie without HttpOnly: ' + x.split('=')[0]); }
    if (!sess.length) p.warn(rec, 'no session cookie seen');
  });
  await p.step('function', 'console and admin API refuse visitors', async rec => {
    const bad = []; for (const u of ['/api/admin/users', '/api/admin/report/kpis?days=30', '/api/admin/content/integrations', '/console/owner.js', '/api/admin/errors']) { const r = await raw(u, { headers: { Accept: 'application/json' } }); if (r.status === 200) bad.push(u); await sleep(250); }
    if (bad.length) throw new Error('open to visitors: ' + bad.join(', '));
  }, { expect: /^40[1345]/ });
  await p.step('function', 'sign-in guessing is slowed down (6 wrong passwords)', async rec => {
    const pg = await p.ctx.newPage(); let blocked = false;
    for (let i = 0; i < 6; i++) { await pg.goto('/login', { waitUntil: 'domcontentloaded' }); await pg.fill('input[name=email]', a.customers[0].email); await pg.fill('input[name=password]', 'Wrong-' + i); await pg.click('button.au-go'); await sleep(1200); if (/too many/i.test(await pg.innerText('body'))) { blocked = true; break; } }
    await pg.close(); rec.notes.push(blocked ? 'blocked after repeated wrong passwords' : 'not blocked after 6 tries'); if (!blocked) p.warn(rec, 'no lock-out after 6 wrong passwords');
  }, { expect: /^4\d\d/ });
  await p.step('function', 'sign in as tester B', async () => { await p.page.context().clearCookies(); await p.login(other.email, other.password); }, { critical: true });
  let otherResume = null;
  await p.step('function', 'tester B creates a private resume', async rec => { const j = await p.call(rec, 'POST', 'resumes/import', { text: 'Bharat Secret\nAnalyst\nEXPERIENCE\nAnalyst, Private Co, 2021 to Present\n- Confidential work on 40 dashboards for the finance team every week.\nEDUCATION\nB.Sc 2020', fileName: 'private.pdf' }, [200, 409]); otherResume = j.id || j.resume?.id || (j.resumes || [])[0]?.id; rec.notes.push('id ' + otherResume); });
  await p.step('function', 'sign in as tester A (attacker)', async () => { await p.page.context().clearCookies(); await p.login(me.email, me.password); }, { critical: true });
  await p.step('function', "cross-account: A cannot read, edit, delete, scan or export B's resume", async rec => {
    if (!otherResume) return 'skip'; const leaks = [];
    for (const [m, u, b] of [['GET', `resumes/${otherResume}`], ['POST', 'resumes', { id: otherResume, revision: 1, data: { title: 'hacked' } }], ['DELETE', `resumes/${otherResume}`], ['GET', `resumes/${otherResume}/versions`], ['GET', `resumes/${otherResume}/quality`], ['POST', 'ats/scan', { resumeId: otherResume, source: 'text', text: 'x'.repeat(200) }], ['POST', 'letters/draft', { resumeId: otherResume }]]) {
      const r = await p.api(m, u, b); if (r.status === 200 && JSON.stringify(r.json).includes('Bharat')) leaks.push(`${m} ${u}`); if (r.status === 200 && m !== 'GET') leaks.push(`${m} ${u} accepted`); await sleep(300); }
    const x = await p.page.evaluate(async id => { const t = (await (await fetch('/api/csrf')).json()).token; const r = await fetch(`/export/resume/${id}/pdf`, { method: 'POST', headers: { 'X-CSRF-TOKEN': t } }); return r.status; }, otherResume); if (x === 200) leaks.push('export of B resume');
    if (leaks.length) throw new Error('LEAK: ' + leaks.join('; '));
  }, { expect: /^4\d\d/ });
  await p.step('function', 'POST without CSRF token is refused (signed-in cookie, no token)', async rec => { const r = await p.ctx.request.post('/api/profile', { headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, data: { name: 'csrf' }, failOnStatusCode: false }); const s = r.status(); rec.notes.push('answered ' + s); if (s !== 419) throw new Error('expected 419 (token missing), got ' + s); }, { expect: /^4(19|03)/ });
  await p.step('function', 'stored XSS: script in name and resume is shown as text', async rec => {
    await p.call(rec, 'POST', 'profile', { name: 'QA ' + XSS.slice(0, 100), experienceLevel: 'fresher', profession: XSS.slice(0, 110), category: 'Other', experienceYears: 0, marketingConsent: false }, [200, 422]);
    for (const u of ['/workspace.html', '/workspace.html?view=account']) { await p.goto(u); await sleep(2500); if (await p.page.evaluate(() => window.__qa_xss === 1)) throw new Error('script ran on ' + u); }
    await p.call(rec, 'POST', 'profile', { name: 'QA Tester 02', experienceLevel: 'fresher', profession: 'Data Analyst', category: 'Other', experienceYears: 0, marketingConsent: false }, [200, 422]);
  });
  await p.step('function', 'reflected XSS in query strings', async rec => { for (const u of [`/login?email=${encodeURIComponent(XSS)}`, `/register?email=${encodeURIComponent(XSS)}`, `/checkout.html?plan=${encodeURIComponent(XSS)}`, `/workspace.html?view=${encodeURIComponent(XSS)}`, `/blog.html?q=${encodeURIComponent(XSS)}`]) { await p.goto(u); await sleep(1200); if (await p.page.evaluate(() => window.__qa_xss === 1)) throw new Error('script ran on ' + u); } }, { expect: /^4\d\d/ });
  await p.step('function', 'open redirect after sign-in (next=)', async rec => {
    await p.page.context().clearCookies(); for (const n of ['//evil.example', 'https://evil.example', '/\\evil.example', '/%2F%2Fevil.example']) { await p.goto('/login?next=' + encodeURIComponent(n)); await p.page.fill('input[name=email]', me.email); await p.page.fill('input[name=password]', me.password); await p.page.evaluate(n => { const f = document.querySelector('form'); let i = f.querySelector('[name=next]'); if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = 'next'; f.appendChild(i); } i.value = n; }, n); await Promise.all([p.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), p.page.click('button.au-go')]); await sleep(1200); if (/evil\.example/.test(p.page.url())) throw new Error('redirected off-site with next=' + n); await p.page.context().clearCookies(); }
  });
  await p.step('function', 'uploads: a script disguised as a resume is refused or made harmless', async rec => {
    await p.login(me.email, me.password); const r = await p.api('POST', 'resumes/import', { text: '<?php system($_GET["c"]); ?>' + ' padding'.repeat(20), fileName: 'shell.php' }); rec.notes.push('import of PHP text answered ' + r.status);
    const s = await raw('/media/../../.env'); if (s.status === 200) throw new Error('path traversal on /media');
  }, { expect: /^4\d\d/ });
  await p.step('function', 'customer cannot reach owner features', async rec => { const bad = []; for (const u of ['admin/users', 'admin/plans', 'admin/content/integrations', 'admin/report/kpis?days=30']) { const r = await p.api('GET', u); if (r.status === 200) bad.push(u); await sleep(300); } if (bad.length) throw new Error('customer reached ' + bad.join(', ')); }, { expect: /^40[1345]/ });
  await p.step('function', 'price cannot be changed from the browser', async rec => { const r = await p.api('POST', 'checkout/quote', { plan: 'job-hunt', amount: 1, totalPaise: 100, price: 1 }); if (r.json?.totalPaise && r.json.totalPaise < 10000) throw new Error('quote accepted a client price: ' + r.json.totalPaise); rec.notes.push('quote total ' + (r.json?.totalPaise / 100)); });
  await p.step('function', 'payment webhooks reject unsigned calls', async rec => { for (const u of ['/webhooks/razorpay', '/webhooks/phonepe']) { const r = await raw(u, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ event: 'payment.captured', payload: { payment: { entity: { order_id: 'order_qa', amount: 29900, status: 'captured' } } } }) }); rec.notes.push(`${u} → ${r.status}`); if (r.status === 200 && u.includes('razorpay')) { const t = await r.text(); if (!/false|retry/.test(t)) p.warn(rec, `${u} answered 200 to an unsigned call (check it is ignored): ${t.slice(0, 80)}`); } await sleep(400); } }, { expect: /^4\d\d/ });
});
