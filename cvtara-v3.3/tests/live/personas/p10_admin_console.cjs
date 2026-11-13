// Persona 10: the owner going through every console page, its tabs, paging and downloads. READ-ONLY: it only clicks
// menu items, tabs, next/previous and Download buttons, never Save/Send/Approve/Run.
const { run, accounts, sleep, totp, OUT } = require('../lib.cjs'); const fs = require('fs'), path = require('path');
const SAFE_TAB = 'button[role=tab]:visible, .tabs button:visible, .tab-bar button:visible, .seg button:visible, .subnav button:visible, [data-tab]:visible';
run('p10-admin-console', 'Owner: sign-in with authenticator code, every console page, tabs, paging, filters, downloads, Error Log', async p => {
  const o = accounts().owner; const page = p.page; const door = '/' + (o.paths || ['sanaulla'])[0];
  await p.step('function', 'owner sign-in (password + authenticator code)', async rec => {
    await p.goto(door); await page.fill('input[name=email]', o.email); await page.fill('input[name=password]', o.password); await page.click('button.au-go'); await sleep(2000);
    if (await page.locator('input[name=code]').count()) { let code = totp(o.totp); await page.fill('input[name=code]', code); await page.click('button.au-go'); await sleep(2500); }
    if (!/admin\.html/.test(page.url())) await p.goto('/admin.html');
    if (!(await page.locator('[data-admin-view]').count())) throw new Error('console did not open: ' + page.url());
  }, { critical: true });
  const views = await page.$$eval('[data-admin-view]', els => [...new Set(els.map(e => e.getAttribute('data-admin-view')))]);
  p.results.push({ persona: p.id, area: 'function', name: `console menu lists ${views.length} pages`, status: 'pass', notes: [views.join(', ')], errors: [] });
  for (const v of views) {
    await p.step('page', `console: ${v}`, async rec => {
      await page.evaluate(v => { const el = [...document.querySelectorAll(`[data-admin-view="${v}"]`)].find(e => e.offsetParent) || document.querySelector(`[data-admin-view="${v}"]`); el && el.click(); }, v);
      await sleep(3500);
      const info = await page.evaluate(() => { const m = document.querySelector('main') || document.body; const vis = [...m.querySelectorAll('section, .view, [data-view], .panel')].filter(e => e.offsetParent); return { text: m.innerText.slice(0, 20000), toasts: [...document.querySelectorAll('.toast, .alert-error, .error, [role=alert]')].filter(e => e.offsetParent).map(e => e.innerText.trim()).filter(Boolean).slice(0, 5) }; });
      if (v !== 'errors' && info.toasts.some(t => /error|failed|could not|something went wrong/i.test(t))) { rec.status = 'fail'; rec.notes.push('error message: ' + info.toasts.join(' | ').slice(0, 300)); }
      if (/Loading…|Loading\.\.\./.test(info.text) && info.text.length < 400) p.warn(rec, 'still loading after 3.5 s');
      await p.pageChecks(rec, { customer: false });
      if (v === 'errors') fs.writeFileSync(path.join(OUT, 'console-error-log.txt'), info.text);
      // tabs inside the page
      const tabs = await page.locator(SAFE_TAB).all(); let n = 0;
      for (const t of tabs.slice(0, 14)) { const label = (await t.innerText().catch(() => '')).trim().slice(0, 30); if (!label || /save|send|delete|approve|run|publish|start|apply|remove|reset|clear/i.test(label)) continue; await t.click({ timeout: 4000 }).catch(() => {}); await sleep(1600); n++; }
      if (n) rec.notes.push(`${n} tabs opened`);
      const next = page.locator('button:visible', { hasText: /^(next|next ›|›|older)$/i }).first(); if (await next.count() && await next.isEnabled()) { await next.click({ timeout: 4000 }).catch(() => {}); await sleep(1500); rec.notes.push('next page ok'); }
      const dl = page.locator('button:visible, a:visible', { hasText: /^(download|csv|excel|pdf)/i }).first();
      if (await dl.count()) { const d = await Promise.all([page.waitForEvent('download', { timeout: 15000 }).catch(() => null), dl.click({ timeout: 4000 }).catch(() => null)]); if (d[0]) rec.notes.push('download: ' + d[0].suggestedFilename()); else { const menu = page.locator('button:visible, a:visible', { hasText: /^csv|excel/i }).first(); if (await menu.count()) { const d2 = await Promise.all([page.waitForEvent('download', { timeout: 15000 }).catch(() => null), menu.click({ timeout: 4000 }).catch(() => null)]); rec.notes.push(d2[0] ? 'download: ' + d2[0].suggestedFilename() : 'download button gave no file'); } } }
    });
  }
  await p.step('function', 'find the QA test customers in Users (search)', async rec => {
    await page.evaluate(() => document.querySelector('[data-admin-view="people"]')?.click()); await sleep(3000);
    const s = page.locator('input[type=search]:visible, input[placeholder*="earch" i]:visible').first(); if (!(await s.count())) return p.warn(rec, 'no search box on Users');
    await s.fill('cvtara-qa'); await s.press('Enter'); await sleep(3000); const t = await page.innerText('main'); if (!/cvtara-qa|QA Tester/.test(t)) p.warn(rec, 'QA customers not found by search');
  });
  await p.step('function', 'console scripts load for the owner', async rec => {
    for (const n of ['owner', 'ops', 'growth', 'partners', 'reports']) { const s = await page.evaluate(async n => (await fetch(`/console/${n}.js`)).status, n); if (s !== 200) throw new Error(`${n}.js answered ${s}`); }
  });
});
