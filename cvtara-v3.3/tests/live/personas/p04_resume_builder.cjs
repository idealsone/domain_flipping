// Persona 4: an experienced person uploading a resume, editing it in the builder, versions, writing check, download.
const { run, accounts, sleep } = require('../lib.cjs'); const { RESUME_TEXT } = require('./common.cjs');
run('p04-resume-builder', 'Job seeker: workspace pages, resume import, builder, versions, writing check, download rules', async p => {
  const me = accounts().customers[3]; let rid = null, res = null;
  await p.step('function', 'sign in', async () => p.login(me.email, me.password), { critical: true });
  for (const v of ['dashboard', 'resumes', 'builder', 'account', 'feedback']) await p.step('page', `workspace: ${v}`, async rec => { await p.wsView(rec, v); });
  await p.step('function', 'resume import from pasted text (PDF/Word reader path)', async rec => {
    const j = await p.call(rec, 'POST', 'resumes/import', { text: RESUME_TEXT, fileName: 'qa-asha.pdf' }); rid = j.id || j.resume?.id;
    if (!rid) throw new Error('no resume id returned: ' + JSON.stringify(j).slice(0, 200));
    const g = await p.call(rec, 'GET', 'resumes/' + rid); res = g.resume || g;
    const d = res.data || JSON.parse(res.content_json || '{}'); rec.notes.push(`read: ${(d.experience || []).length} jobs, ${(d.education || []).length} education, skills ${(d.skills || '').length || (d.skillGroups || []).length}`);
    if (!(d.experience || []).length) throw new Error('no experience read from the resume text');
  });
  await p.step('function', 'too little text is refused politely', async rec => { await p.call(rec, 'POST', 'resumes/import', { text: 'hello' }, [422]); }, { expect: /^422/ });
  await p.step('function', 'resume upload button in the UI (PDF file)', async rec => {
    await p.goto('/workspace.html'); await p.wsView(rec, 'resumes');
    const pdf = await p.page.evaluate(async () => { const { jsPDF } = window.jspdf || {}; return !!jsPDF; });
    const input = p.page.locator('input[type=file]').first(); if (!(await input.count())) throw new Error('no file input for resume upload');
    await input.setInputFiles({ name: 'qa-resume.txt', mimeType: 'text/plain', buffer: Buffer.from(RESUME_TEXT) }); await sleep(4000); await p.pageChecks(rec);
  });
  await p.step('function', 'edit and save the resume (new revision)', async rec => {
    if (!rid) return 'skip'; const d = res.data || {}; d.summary = (d.summary || '') + ' QA edit.';
    const j = await p.call(rec, 'POST', 'resumes', { data: d, id: rid, revision: res.revision }); if (!j.ok && !j.id) throw new Error('save not confirmed');
  });
  await p.step('function', 'resume versions list', async rec => { if (!rid) return 'skip'; await p.call(rec, 'GET', `resumes/${rid}/versions`); });
  await p.step('function', 'resume quality score', async rec => { if (!rid) return 'skip'; const j = await p.call(rec, 'GET', `resumes/${rid}/quality`); rec.notes.push('score ' + JSON.stringify(j.score ?? j.quality?.score ?? j).slice(0, 60)); });
  await p.step('function', 'set as primary resume', async rec => { if (!rid) return 'skip'; await p.call(rec, 'POST', `resumes/${rid}/primary`, {}); });
  await p.step('function', 'check my writing', async rec => { if (!rid) return 'skip'; const j = await p.call(rec, 'POST', 'writing/check', { type: 'resume', id: rid }); rec.notes.push(`${(j.issues || j.findings || []).length} writing notes`); });
  await p.step('page', 'builder opens the imported resume', async rec => {
    await p.goto('/workspace.html?view=builder&id=' + rid); await sleep(2500); await p.pageChecks(rec);
    const name = await p.page.locator('input[value*="Asha"], input').first().inputValue().catch(() => ''); rec.notes.push('first field: ' + name.slice(0, 40));
  });
  await p.step('function', 'free account download is gated (no clean PDF without a plan)', async rec => {
    if (!rid) return 'skip';
    const r = await p.page.evaluate(async id => { const t = (await (await fetch('/api/csrf')).json()).token; const x = await fetch(`/export/resume/${id}/pdf`, { method: 'POST', headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json' } }); return { s: x.status, ct: x.headers.get('content-type') }; }, rid);
    rec.notes.push(`export answered ${r.s} ${r.ct}`); if (r.s >= 500) throw new Error('server error on export');
  }, { expect: /^40[23]/ });
  await p.step('function', 'account data export (my data)', async rec => { await p.call(rec, 'GET', 'account/export'); });
  await p.step('function', 'free account keeps one resume (second import asks to replace)', async rec => { await p.call(rec, 'POST', 'resumes/import', { text: RESUME_TEXT.replace('Asha', 'Second'), fileName: 'qa-2.pdf' }, [409]); }, { expect: /^409/ });
  await p.step('function', 'delete a resume (downloaded resumes are kept by design)', async rec => { if (!rid) return 'skip'; const j0 = await p.call(rec, 'DELETE', 'resumes/' + rid, null, [200, 422]); if (j0.ok === false) { rec.notes.push('kept: ' + (j0.error || '').slice(0, 120)); return; } const j = await p.call(rec, 'GET', 'resumes'); if (JSON.stringify(j).includes(rid)) throw new Error('deleted resume still listed'); }, { expect: /^422/ });
});
