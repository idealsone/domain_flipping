// Persona 7: a customer who asks for help: Tara chat, support request, feedback, contact form, notifications.
const { run, accounts, sleep } = require('../lib.cjs');
run('p07-tara-support', 'Customer asking for help: Tara chat, support requests, feedback/review, contact form, notifications, What\'s new', async p => {
  const me = accounts().customers[6]; let cid;
  await p.step('page', '/contact.html contact form (signed out)', async rec => {
    await p.goto('/contact.html'); await p.pageChecks(rec);
    const f = p.page.locator('form').filter({ has: p.page.locator('textarea') }).first(); if (!(await f.count())) throw new Error('no contact form');
    for (const inp of await f.locator('input:visible, textarea:visible, select:visible').all()) {
      const n = ((await inp.getAttribute('name')) || (await inp.getAttribute('type')) || '').toLowerCase(); const tag = await inp.evaluate(e => e.tagName);
      if (tag === 'SELECT') await inp.selectOption({ index: 1 }).catch(() => {}); else if (/checkbox/.test(await inp.getAttribute('type') || '')) await inp.check().catch(() => {});
      else if (tag === 'TEXTAREA') await inp.fill('QA TEST - automated site check, please ignore. This message checks that the contact form works.');
      else if (/mail/.test(n)) await inp.fill('cvtara-qa-tester-07@example.com'); else if (/phone|mobile/.test(n)) await inp.fill('9000000000'); else if (/file/.test(n) || /file/.test(await inp.getAttribute('type') || '')) {} else await inp.fill('QA Tester 07');
    }
    await f.locator('button[type=submit], button:not([type])').first().click(); await sleep(3000);
    const t = await p.page.innerText('body'); if (!/thank|received|sent|we will|we'll|reply/i.test(t)) p.warn(rec, 'no confirmation after sending the contact form');
  });
  await p.step('function', 'sign in', async () => p.login(me.email, me.password), { critical: true });
  await p.step('function', 'Tara chat status', async rec => { const j = await p.call(rec, 'GET', 'tara/status'); rec.notes.push(JSON.stringify(j.tara || j).slice(0, 120)); });
  await p.step('function', 'Tara chat: ask about my plan', async rec => {
    const j = await p.call(rec, 'POST', 'tara/message', { text: 'What plan am I on and how many resume downloads do I have left?' }, [200, 422, 429]); cid = j.conversationId || j.conversation?.id;
    const txt = JSON.stringify(j); rec.notes.push(txt.slice(0, 200)); if (/\b(AI|Claude|Anthropic|language model)\b/.test(txt)) p.warn(rec, 'Tara reply mentions AI/vendor');
    if (!j.ok) p.warn(rec, 'Tara did not answer: ' + (j.error || ''));
  });
  await p.step('function', 'Tara chat: writing help (bullets)', async rec => { const j = await p.call(rec, 'POST', 'tara/message', { text: 'Turn my duties into bullets: Responsible for hiring store staff across 14 stores in Pune.' }, [200, 422, 429]); if (!j.ok) p.warn(rec, 'no answer: ' + (j.error || '')); });
  await p.step('function', 'Tara chat window in the UI', async rec => {
    await p.goto('/workspace.html'); const open = p.page.locator('[data-tara-open], .tara-launcher, button[aria-label*="Tara" i], button:has-text("Tara")').first();
    if (!(await open.count())) return p.warn(rec, 'no Tara button found on the workspace');
    await open.click({ timeout: 5000 }); await sleep(1500); await p.pageChecks(rec);
  });
  await p.step('function', 'Tara conversations list and delete', async rec => { const j = await p.call(rec, 'GET', 'tara/conversations', null, [200, 404]); const id = cid || (j.conversations || [])[0]?.id; if (id) await p.call(rec, 'POST', `tara/conversations/${id}/delete`, {}); });
  await p.step('function', 'support request from the workspace', async rec => {
        const r = await p.page.evaluate(async () => { const t = (await (await fetch('/api/csrf')).json()).token; const o = await fetch('/support/options', { headers: { Accept: 'application/json' } }); const oj = await o.json().catch(() => ({})); const fd = new FormData(); fd.append('area', 'website'); fd.append('kind', 'question'); fd.append('subject', 'QA TEST - please ignore'); fd.append('message', 'QA TEST: automated check that support requests work. Please ignore or close.'); fd.append('body', 'QA TEST: automated check that support requests work. Please ignore or close.'); const x = await fetch('/support/report', { method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json' }, body: fd }); return { s: x.status, b: (await x.text()).slice(0, 300), o: JSON.stringify(oj).slice(0, 200) }; });
    rec.notes.push(`${r.s} ${r.b}`); if (r.s !== 200) throw new Error('support request not accepted');
    const mine = await p.call(rec, 'GET', 'support/mine'); const t = (mine.tickets || mine.items || mine.requests || [])[0]; if (t?.id) await p.call(rec, 'POST', `support/mine/${t.id}/reply`, { body: 'QA TEST follow-up, please ignore.' });
  });
  await p.step('function', 'leave a review (feedback)', async rec => { await p.call(rec, 'POST', 'feedback', { displayName: 'QA Tester', rating: 5, content: 'QA TEST automated review, please do not publish.', consent: false }, [200, 422]); const j = await p.call(rec, 'GET', 'feedback'); if (!(j.reviews || []).length) p.warn(rec, 'review not listed back'); });
  await p.step('function', 'notifications and What\'s new', async rec => { await p.call(rec, 'GET', 'notifications'); await p.call(rec, 'GET', 'whats-new'); await p.call(rec, 'POST', 'whats-new/seen', {}); });
  await p.step('page', '/workspace.html?whats-new=1', async rec => { await p.goto('/workspace.html?whats-new=1'); await p.pageChecks(rec); });
  await p.step('page', '/customer-guide.html', async rec => { await p.goto('/customer-guide.html'); await p.pageChecks(rec); });
});
