// Persona 6: a fresher practising interviews and tracking job applications.
const { run, accounts, sleep } = require('../lib.cjs');
run('p06-interview-tracker', 'Fresher with Job Hunt: interview practice (start, answer, submit), question pages, application tracker', async p => {
  const me = accounts().customers[5]; let sid, app;
  await p.step('function', 'sign in', async () => p.login(me.email, me.password), { critical: true });
  for (const v of ['interview', 'tracker']) await p.step('page', `workspace: ${v}`, async rec => { await p.wsView(rec, v); });
  let occ = 'customer-support';
  await p.step('function', 'interview roles list', async rec => { const j = await p.call(rec, 'GET', 'interviews/roles'); const o = j.occupations || []; rec.notes.push(`${o.length} roles`); if (o.length && !o.find(x => (x.key || x.id || x.slug) === occ)) occ = o[0].key || o[0].id || o[0].slug; });
  await p.step('function', 'sample questions for a role', async rec => { const j = await p.call(rec, 'GET', `interviews/questions?occupation=${occ}&band=fresher`); rec.notes.push(`${(j.questions || []).length} questions shown of ${j.total}`); if (!(j.questions || []).length) throw new Error('no questions'); });
  await p.step('function', 'start a mock interview', async rec => { const j = await p.call(rec, 'POST', 'interviews/start', { occupation: occ, band: 'fresher', objective: 'first_job' }); sid = j.session?.id || j.id; if (!sid) throw new Error('no session id'); });
  await p.step('function', 'answer two questions', async rec => {
    if (!sid) return 'skip'; const j = await p.call(rec, 'GET', 'interviews/' + sid); const qs = (j.session?.answers || j.session?.questions || j.questions || []).slice(0, 2);
    if (!qs.length) throw new Error('session has no questions');
    for (const q of qs) await p.call(rec, 'POST', `interviews/${sid}/answer`, { position: q.position ?? 0, answer: 'In my college project I led a team of four, split the work by strengths and we delivered the attendance app two days early.' });
  });
  await p.step('function', 'submit for the automatic evaluation', async rec => { if (!sid) return 'skip'; const j = await p.call(rec, 'POST', `interviews/${sid}/submit`, {}, [200, 422]); rec.notes.push('status ' + (j.session?.status || j.error || '')); });
  await p.step('function', 'interview history', async rec => { await p.call(rec, 'GET', 'interviews'); });
  await p.step('function', 'interview practice screen in the UI', async rec => { await p.wsView(rec, 'interview'); const b = p.page.locator('main button:visible:not([disabled])', { hasText: /start|practi/i }).first(); if (await b.count()) { await b.click({ timeout: 5000 }).catch(e => p.warn(rec, 'start button not clickable: ' + e.message.split('\n')[0])); await sleep(2500); } await p.pageChecks(rec); });
  await p.step('function', 'add, move and delete a job application', async rec => {
    const j = await p.call(rec, 'POST', 'applications', { company: 'QA Test Co', role: 'Data Analyst', stage: 'saved', remindersOff: true }); app = j.id; const rev = 1;
    await p.call(rec, 'POST', 'applications', { company: 'QA Test Co', role: 'Data Analyst', stage: 'applied', id: app, revision: rev, remindersOff: true });
    const l = await p.call(rec, 'GET', 'applications'); if (!JSON.stringify(l).includes('applied')) p.warn(rec, 'stage change not visible');
    await p.call(rec, 'DELETE', 'applications/' + app, { revision: 2 });
  });
  for (const u of ['/interview-questions/']) await p.step('page', u, async rec => { await p.goto(u); await p.pageChecks(rec); });
});
