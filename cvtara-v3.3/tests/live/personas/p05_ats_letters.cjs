// Persona 5: a Job Hunt customer checking a resume against a job, tailoring it and writing the cover letter.
const { run, accounts, sleep } = require('../lib.cjs'); const { RESUME_TEXT, JD } = require('./common.cjs');
run('p05-ats-letters', 'Job Hunt customer: ATS Match, scans history, one-click tailoring, cover letters', async p => {
  const me = accounts().customers[4]; let rid, scan, lid;
  await p.step('function', 'sign in', async () => p.login(me.email, me.password), { critical: true });
  await p.step('function', 'plan shows Job Hunt (allowance)', async rec => { const j = await p.call(rec, 'GET', 'allowance'); rec.notes.push(JSON.stringify(j).slice(0, 160)); if (!/job-hunt|Job Hunt/i.test(JSON.stringify(j).slice(0, 400))) p.warn(rec, 'Job Hunt plan not visible in allowance'); });
  for (const v of ['match', 'letter']) await p.step('page', `workspace: ${v}`, async rec => { await p.wsView(rec, v); });
  await p.step('function', 'resume ready for checking', async rec => { const j = await p.call(rec, 'POST', 'resumes/import', { text: RESUME_TEXT, fileName: 'qa-ats.pdf' }); rid = j.id || j.resume?.id; if (!rid) throw new Error('no id'); });
  await p.step('function', '"Is this the right job?" reads pasted job text', async rec => { const j = await p.call(rec, 'POST', 'ats/read', { source: 'text', text: JD }); rec.notes.push(`title "${j.title || j.job?.title || ''}" company "${j.company || j.job?.company || ''}"`); });
  await p.step('function', 'ATS Match scan (pasted job text)', async rec => {
    const j = await p.call(rec, 'POST', 'ats/scan', { resumeId: rid, source: 'text', text: JD }); scan = j.scanId || j.scan?.id || j.id;
    const score = j.report?.score ?? j.scan?.score ?? j.score; rec.notes.push(`score ${score}`); if (score === undefined) p.warn(rec, 'no score in the answer');
    if (typeof score === 'number' && score < 40) p.warn(rec, `low score ${score} for a strongly matching resume (fairness check)`);
  });
  await p.step('function', 'ATS scans history and one scan', async rec => { const j = await p.call(rec, 'GET', 'ats/scans'); const list = j.scans || j.items || []; if (!scan && list[0]) scan = list[0].id; if (scan) await p.call(rec, 'GET', 'ats/scans/' + scan); });
  await p.step('function', 'unknown resume is refused', async rec => { await p.call(rec, 'POST', 'ats/scan', { resumeId: '00000000-0000-0000-0000-000000000000', source: 'text', text: JD }, [422]); }, { expect: /^422/ });
  await p.step('function', 'one-click tailoring for this job', async rec => { if (!scan) return 'skip'; const j = await p.call(rec, 'POST', `resumes/${rid}/tailor`, { scanId: scan, consent: true }, [200, 422, 503]); rec.notes.push(Object.keys(j.tailored || j).slice(0, 8).join(',')); if (!j.tailored) p.warn(rec, 'no tailored resume: ' + JSON.stringify(j).slice(0, 160)); });
  await p.step('function', 'ATS Match screen in the UI (paste job, run check)', async rec => {
    await p.wsView(rec, 'match'); const ta = p.page.locator('textarea:visible').first();
    if (!(await ta.count())) return p.warn(rec, 'no visible job-text box on the ATS Match screen');
    await ta.fill(JD); const btn = p.page.locator('main button:visible:not([disabled])', { hasText: /check|scan|match|continue|next/i }).first(); if (await btn.count()) { await btn.click({ timeout: 5000 }).catch(e => p.warn(rec, 'check button not clickable: ' + e.message.split('\n')[0])); await sleep(6000); } await p.pageChecks(rec);
  });
  await p.step('function', 'one-click cover letter for this job', async rec => { const j = await p.call(rec, 'POST', 'letters/draft', { resumeId: rid, scanId: scan, role: 'Data Analyst', company: 'Acme Analytics' }, [200, 422, 503]); const txt = JSON.stringify(j); rec.notes.push(`${txt.length} chars`); if (!j.ok) p.warn(rec, txt.slice(0, 200)); if (/\b(AI|Claude|Anthropic)\b/.test(txt)) p.warn(rec, 'letter answer mentions AI/vendor'); });
  await p.step('function', 'save, list and delete a cover letter', async rec => {
    const data = { version: '1', title: 'QA Letter', template: 'letter-prism', accent: '#1557d2', font: 'Arial', personal: { name: 'Asha Verma' }, company: 'Acme Analytics', role: 'Data Analyst', opening: 'I am applying for the Data Analyst role.', evidence: 'I built 12 dashboards used by 40 store managers.', closing: 'Thank you.' };
    const j = await p.call(rec, 'POST', 'letters', { data }); lid = j.id; const l = await p.call(rec, 'GET', 'letters'); if (!JSON.stringify(l).includes(lid)) throw new Error('saved letter not listed');
    await p.call(rec, 'DELETE', 'letters/' + lid);
  });
  await p.step('function', 'paid download of the resume works (Job Hunt)', async rec => {
    const r = await p.page.evaluate(async id => { const t = (await (await fetch('/api/csrf')).json()).token; const x = await fetch(`/export/resume/${id}/pdf`, { method: 'POST', headers: { 'X-CSRF-TOKEN': t } }); return { s: x.status, ct: x.headers.get('content-type'), n: (await x.arrayBuffer()).byteLength }; }, rid);
    rec.notes.push(`${r.s} ${r.ct} ${r.n} bytes`); if (r.s !== 200 || !/pdf/.test(r.ct || '')) throw new Error('download failed');
  });
});
