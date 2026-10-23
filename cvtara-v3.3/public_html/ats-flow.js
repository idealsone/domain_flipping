(() => {
  'use strict';
  // ATS Match: pick a saved resume, add a job (link, PDF/text file or pasted text), read the report, then choose what to build.
  // The review itself runs on the server: a scan is reserved and the report is built in one step, so the allowance cannot be skipped.
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  const host = document.getElementById('view-match');
  if (!host) return;
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (s, r = host) => r.querySelector(s);
  const st = { resumes: [], resumeId: '', source: 'text', fileText: '', fileName: '', allowance: null, scans: [], run: null, busy: false };
  const tone = n => n >= 75 ? 'good' : n >= 50 ? 'mid' : 'low';
  const verdict = n => n >= 75 ? 'Strong match — polish the details and apply.' : n >= 50 ? 'Partial match — close the gaps below before you apply.' : 'Low match — this resume needs work for this job.';
  const day = d => new Date(String(d).replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  const detailLabels = { location: 'Location', employmentType: 'Type', salary: 'Salary', experience: 'Experience', posted: 'Posted', expires: 'Apply by', industry: 'Industry', category: 'Category', skills: 'Skills listed' };

  function meter() {
    const a = st.allowance, el = $('#atsMeter');
    if (!el || !a) return;
    const s = a.credits?.scans;
    el.innerHTML = !a.paid
      ? `<b>Free account</b><span>${a.used.dailyMatches} of ${a.limits?.dailyMatches ?? 3} checks used today</span>`
      : s?.unlimited ? '<b>Unlimited</b><span>ATS scans</span>' : `<b>${s ? s.remaining : 0} of ${s ? s.total : 0}</b><span>ATS scans left</span>`;
  }

  function shell() {
    host.innerHTML = `<div class="admin-heading"><div><span class="eyebrow">ATS MATCH</span><h2>Check your resume against a real job.</h2><p>Pick one of your saved resumes, add the job, and see exactly where you are lacking — then build what you need.</p></div><div class="ats-meter" id="atsMeter" aria-live="polite"></div></div>
<ol class="ats-steps"><li><b>1</b><span>Choose resume</span></li><li><b>2</b><span>Add the job</span></li><li><b>3</b><span>Read your report</span></li><li><b>4</b><span>Build &amp; download</span></li></ol>
<div class="ats-grid"><section class="panel panel-pad ats-input" id="atsInput"></section><section class="ats-out" id="atsOut" aria-live="polite"></section></div>
<section class="panel panel-pad ats-history" id="atsHistory"></section>`;
  }

  const options = () => st.resumes.map(r => `<option value="${E(r.id)}" ${r.id === st.resumeId ? 'selected' : ''}>${r.is_primary ? '★ Primary — ' : ''}${E(r.title)}${r.ats_score != null ? ' · ATS-friendly ' + r.ats_score + '%' : ''}</option>`).join('');

  function inputPanel() {
    const box = $('#atsInput');
    if (!st.resumes.length) {
      box.innerHTML = '<h3>Start with your resume</h3><p class="ats-muted">ATS Match checks one of your saved resumes. Build your primary resume first — it takes a few minutes.</p><div class="ats-empty-actions"><button class="button primary" type="button" data-ats-upload>📤 Upload my resume</button><button class="button secondary" type="button" data-workspace-view="builder">Build one from a template →</button></div>';
      box.querySelector('[data-ats-upload]').onclick = () => window.CVTaraResumeImport?.pick();
      return;
    }
    const tab = (k, label) => `<button type="button" role="tab" aria-selected="${st.source === k}" data-src="${k}">${label}</button>`;
    box.innerHTML = `<label class="ats-field"><span>1 · Your resume</span><select id="atsResume">${options()}</select></label>
<div class="ats-field"><span>2 · The job</span><div class="ats-tabs" role="tablist">${st.allowance?.jobLinks ? tab('link', 'Job link') : ''}${tab('file', 'PDF / text file')}${tab('text', 'Paste text')}</div></div>
<div id="atsSrc"></div>
<div class="ats-two"><label class="ats-field"><span>Job title <small>(optional)</small></span><input id="atsTitle" maxlength="180" placeholder="Filled in from the job"></label><label class="ats-field"><span>Company <small>(optional)</small></span><input id="atsCompany" maxlength="180" placeholder="Filled in from the job"></label></div>
<button class="button primary full" id="atsRun" type="button">Run ATS check</button>
<p class="ats-muted" id="atsNote">Each check uses 1 ATS scan. The job text is saved with your report so you can build from it later.</p>
<p role="status" id="atsStatus" class="ats-status"></p>`;
    src();
  }

  function src() {
    const b = $('#atsSrc');
    if (!b) return;
    if (st.source === 'link') {
      b.innerHTML = '<label class="ats-field"><span class="sr-only">Job link</span><input id="atsUrl" type="url" inputmode="url" maxlength="1000" placeholder="https://company.com/careers/job-123"></label><p class="ats-muted">We open the link and read the posting again every time you run a check, so the report matches the current job, and we pick up the details it publishes (location, type, salary). Some portals hide postings behind a login — if a link cannot be read, use the file or paste option.</p>';
    } else if (st.source === 'file') {
      b.innerHTML = `<label class="ats-drop"><input id="atsFile" type="file" accept=".pdf,.txt,.docx,.md,text/plain,application/pdf" hidden><b>${st.fileName ? E(st.fileName) : 'Choose the job as PDF, DOCX or text file'}</b><small>Up to 5 MB · read on this device</small></label><label class="ats-field"><span class="sr-only">Job text</span><textarea id="atsText" rows="8" maxlength="40000" placeholder="The text read from your file appears here. Correct it if anything looks wrong.">${E(st.fileText)}</textarea></label>`;
    } else {
      b.innerHTML = '<label class="ats-field"><span class="sr-only">Job description</span><textarea id="atsText" rows="10" maxlength="40000" placeholder="Paste the full job description — responsibilities, requirements and qualifications."></textarea></label><button class="button secondary small" type="button" id="atsPaste">Paste from clipboard</button>';
    }
  }

  async function readJobFile(file) {
    if (!file) throw Error('Choose a file.');
    if (file.size > 5 * 1024 * 1024) throw Error('Choose a file smaller than 5 MB.');
    if (/\.(txt|md)$/i.test(file.name) || file.type === 'text/plain') {
      const t = (await file.text()).trim();
      if (t.length < 30) throw Error('That file has no readable text.');
      return t.slice(0, 40000);
    }
    if (!window.CVTaraImport) throw Error('The document reader did not load. Refresh the page.');
    return (await window.CVTaraImport.readFile(file)).slice(0, 40000);
  }

  function say(msg, bad) { const s = $('#atsStatus'); if (s) { s.textContent = msg || ''; s.classList.toggle('bad', !!bad); } }

  // A server or network hiccup is not the customer's fault: say so plainly and offer a retry.
  const friendly = e => !e?.status || e.status >= 500 ? 'ATS Match could not load just now. Your resumes and checks are safe — try again in a moment.' : (e.message || 'ATS Match could not load.');
  // The builder's copy and the saved copy, compared without key order, empty fields or private markers.
  const canon = v => Array.isArray(v) ? v.map(canon) : v && typeof v === 'object' ? Object.keys(v).sort().filter(k => !k.startsWith('_') && v[k] !== '' && v[k] != null && !(Array.isArray(v[k]) && !v[k].length)).reduce((o, k) => (o[k] = canon(v[k]), o), {}) : v;
  const wait = ms => new Promise(r => setTimeout(r, ms));

  // ATS Match reads the resume saved in the profile. When the builder holds edits to that resume that were never saved,
  // the check would quietly score the old text (and give the same score again), so ask first.
  async function unsavedBuilderChanges() {
    const local = window.CVTaraStudio?.getDraft?.();
    if (!local || local._profileId !== st.resumeId) return false;
    try {
      const saved = (await api('/api/resumes/' + encodeURIComponent(st.resumeId))).resume.data;
      if (JSON.stringify(canon(local)) === JSON.stringify(canon(saved))) return false;
    } catch { return false; }
    const s = $('#atsStatus'); s.classList.add('bad');
    s.innerHTML = 'Your Resume Builder has changes to this resume that are not saved to your profile yet. ATS Match checks the saved version. <span class="ats-inline-actions"><button class="button primary small" type="button" data-ats-save-run>Save my changes, then check</button><button class="button secondary small" type="button" data-ats-run-saved>Check the saved version</button></span>';
    return true;
  }

  async function run(opts = {}) {
    if (st.busy) return;
    const text = $('#atsText')?.value.trim() || '', url = $('#atsUrl')?.value.trim() || '';
    if (!st.resumeId) { say('Choose a resume first.', true); return; }
    if (st.source === 'link' && !/^https?:\/\//i.test(url)) { say('Paste the full link of the job posting, starting with https://', true); $('#atsUrl')?.focus(); return; }
    if (st.source !== 'link' && text.length < 80) { say('Add the job description first (at least a few lines).', true); return; }
    if (!opts.skipUnsaved && await unsavedBuilderChanges()) return;
    st.busy = true; const btn = $('#atsRun'); btn.disabled = true; $('#atsOut').innerHTML = '';
    let used = false;
    try {
      say(st.source === 'link' ? 'Opening the job link and reading the posting…' : 'Comparing your resume with the job…');
      const body = { resumeId: st.resumeId, source: st.source, title: $('#atsTitle').value.trim(), company: $('#atsCompany').value.trim() };
      if (st.source === 'link') body.url = url; else body.text = text;
      const s = await api('/api/ats/scan', { method: 'POST', body }); used = true;
      if (s.title && !$('#atsTitle').value) $('#atsTitle').value = s.title;
      if (s.company && !$('#atsCompany').value) $('#atsCompany').value = s.company;
      st.run = { scanId: s.scanId, report: s.report, url: s.jobUrl || '', title: s.title || '', company: s.company || '', resumeId: st.resumeId, resumeTitle: s.resumeTitle || '', sameContent: s.sameContent || null, fresh: true };
      report();
      say('Done — 1 ATS scan used.');
      $('#atsOut').scrollIntoView({ behavior: 'smooth', block: 'start' });
      refresh().catch(() => {});
    } catch (e) { say(used ? 'The report could not be shown. It is saved under Recent checks.' : (e.message || 'The check could not finish.') + (/scan/i.test(e.message || '') ? '' : ' No scan was used.'), true); } finally { st.busy = false; if ($('#atsRun')) $('#atsRun').disabled = false; }
  }

  const lacking = list => list.length ? `<ol class="ats-gaps">${list.map(x => `<li>${E(x)}</li>`).join('')}</ol>` : '<p class="ats-muted">No gaps were found by these rules. Review the evidence for accuracy before you apply.</p>';

  function jobFacts(d) {
    const rows = Object.entries(d || {}).filter(([k, v]) => v && detailLabels[k]);
    return rows.length ? `<h3>About this job</h3><dl class="ats-facts">${rows.map(([k, v]) => `<div><dt>${E(detailLabels[k])}</dt><dd>${E(v)}</dd></div>`).join('')}</dl>` : '';
  }

  function nextPanel() {
    const r = st.run;
    return `<section class="panel panel-pad ats-next" id="atsNext"><span class="eyebrow">STEP 4</span><h3>What do you want to build for this job?</h3>
<div class="ats-two"><label class="ats-field"><span>Job title</span><input id="nxRole" maxlength="160" value="${E(r.title)}" placeholder="e.g. Business Analyst"></label><label class="ats-field"><span>Company</span><input id="nxCompany" maxlength="160" value="${E(r.company)}" placeholder="e.g. Infosys"></label></div>
<div class="ats-choose"><label><input type="checkbox" id="nxResume" checked><b>Tailored resume</b><small>A copy of your resume, opened with this job's gaps beside it</small></label><label><input type="checkbox" id="nxLetter" checked><b>Cover letter</b><small>A full first draft from your resume for this role</small></label></div>
<p class="ats-muted">Building and saving drafts is free. A credit is used when you download: 1 resume credit for a resume, 1 cover-letter credit for each letter (a resume plus 2 letters uses 3 credits). We show a notice after every download, and each download is added to your Applications.</p>
<button class="button primary" id="nxGo" type="button">Start building →</button><p role="status" class="ats-status" id="nxStatus"></p><div id="nxReady"></div></section>`;
  }

  function report(saved) {
    const r = st.run, rep = r.report, res = rep.result, out = $('#atsOut'), c = res.counts, kw = rep.keywords || { total: 0, found: [], missing: [], pct: null };
    const picked = st.resumes.find(x => x.id === r.resumeId) || {}, name = r.resumeTitle || picked.title || '';
    const same = r.sameContent ? `<div class="ats-notice" role="note"><b>Why this score matches an earlier check:</b> ${r.sameContent.kind === 'unchanged_copy' ? `this tailored resume still has exactly the same text as “${E(r.sameContent.title)}”. Making a copy does not change the words, so the score cannot change. Use <b>Fix my resume for this job</b> to add what this job asks for, then check again.` : `“${E(name || 'This resume')}” has exactly the same text as “${E(r.sameContent.title)}”, which you already checked against this job. The same words always give the same score. If you edited it in the builder, save it to your profile, then check again.`}</div>` : '';
    const why = `<div class="ats-why"><div><b>${picked.ats_score != null ? E(picked.ats_score) + '%' : '—'}</b><span>ATS-friendly</span><p>Can any tracking system read this resume? Layout, sections, contact details, dates, action verbs and numbers. It does not look at a job, so it stays the same for every job.</p></div><div><b>${rep.score}%</b><span>Match for this job</span><p>How much of <i>this</i> job's requirements and keywords your resume shows. It changes with every job: a well-built resume can still match a job it was not written for poorly.</p></div></div>`;
    const oneClick = `<div class="ats-fix"><button class="button primary" type="button" data-ats-fix>Fix my resume for this job →</button><button class="button secondary" type="button" data-ats-letter>Write my cover letter for this job →</button><p class="ats-muted">Fixing opens your resume with this job's missing keywords beside it: tick the ones you really have, add them in one click, then re-check. The cover letter is a full first draft from your own resume for this role — review it, then download.</p><p role="status" class="ats-status" id="atsFixStatus"></p></div>`;
    out.innerHTML = `<section class="panel panel-pad ats-report">${same}<div class="ats-score ${tone(rep.score)}" style="--v:${rep.score}"><div class="ats-ring"><b>${rep.score}%</b><span>match</span></div><div><h3>${E(verdict(rep.score))}</h3><p class="ats-muted">${E(r.title || 'This job')}${r.company ? ' · ' + E(r.company) : ''}${saved ? ' · checked ' + E(day(saved)) : ''}${name ? ' · resume: <b>' + E(name) + '</b>' : ''}. This estimate combines how well each requirement is backed by evidence in your resume with how many of the job's keywords it contains. It is guidance, not a real employer ATS result.</p><div class="ats-counts"><span><b>${c.evidenced}</b> requirements evidenced</span><span><b>${c.mentioned}</b> mentioned only</span><span><b>${c.not_found + c.review}</b> missing / to review</span>${kw.pct === null ? '' : `<span><b>${kw.pct}%</b> keyword coverage</span>`}</div></div></div>
${why}${oneClick}
${jobFacts(rep.details)}
${kw.total ? `<h3>Keywords from the job</h3><div class="ats-kw">${kw.found.map(k => `<span class="ok">✓ ${E(k)}</span>`).join('')}${kw.missing.map(k => `<span class="no">✗ ${E(k)}</span>`).join('')}</div>` : ''}
<h3>Where you are lacking</h3>${lacking(rep.gaps || [])}
<details class="ats-detail"><summary>Full report — every requirement, evidence and checks</summary><div class="ats-full" id="atsFull"></div></details></section>${nextPanel()}`;
    const full = $('#atsFull');
    full.innerHTML = window.CVTaraEvidence.report(res, 'ats');
    full.querySelectorAll('[data-import-builder]').forEach(b => b.remove());
    full.querySelectorAll('a[href*="view=letter"]').forEach(a => a.remove());
    $('#nxGo').onclick = build;
  }

  const fixUrl = (id, scanId) => `/workspace.html?view=builder&draft=${encodeURIComponent(id)}&fix=${encodeURIComponent(scanId)}`;

  // One click: a tailored copy when the plan allows one (or the resume already is one), otherwise this resume itself,
  // opened in the builder with the job's gaps beside it.
  async function fixForJob() {
    const r = st.run, s = $('#atsFixStatus'), picked = st.resumes.find(x => x.id === r.resumeId) || {};
    const role = $('#nxRole')?.value.trim() || r.title || 'This job', company = $('#nxCompany')?.value.trim() || r.company || 'Company';
    let id = r.resumeId, note = '';
    s.classList.remove('bad'); s.textContent = 'Opening your resume with this job beside it…';
    if (!picked.parent_id && picked.source !== 'tailored' && st.allowance?.paid) {
      try { id = (await api('/api/resumes/' + encodeURIComponent(r.resumeId) + '/variant', { method: 'POST', body: { company, role, scanId: r.scanId } })).id; }
      catch (e) { if (e.status !== 422) { s.textContent = e.message; s.classList.add('bad'); return; } note = e.message; }
    }
    if (id === r.resumeId) note = note || 'Changes go into this resume. Your other resumes are not touched.';
    try { sessionStorage.setItem('cvtara-fix-note', note); } catch { /* ignore */ }
    location.href = fixUrl(id, r.scanId);
  }

  // One click: the cover-letter studio writes a complete first letter from this resume and this job, then opens it.
  function letterForJob() {
    const r = st.run;
    location.href = `/workspace.html?view=letter&write=1&resume=${encodeURIComponent(r.resumeId)}&scan=${encodeURIComponent(r.scanId)}`;
  }

  async function build() {
    const r = st.run, role = $('#nxRole').value.trim(), company = $('#nxCompany').value.trim(), wantResume = $('#nxResume').checked, wantLetter = $('#nxLetter').checked, s = $('#nxStatus'), ready = $('#nxReady');
    s.classList.remove('bad'); ready.innerHTML = '';
    if (!wantResume && !wantLetter) { s.textContent = 'Choose a tailored resume, a cover letter, or both.'; s.classList.add('bad'); return; }
    if (!role || !company) { s.textContent = 'Add the job title and company so your drafts and your application tracker are labelled correctly.'; s.classList.add('bad'); return; }
    const go = $('#nxGo'); go.disabled = true; s.textContent = 'Preparing your drafts…';
    try {
      let resumeId = null;
      if (wantResume) {
        const v = await api('/api/resumes/' + encodeURIComponent(r.resumeId) + '/variant', { method: 'POST', body: { company, role, scanId: r.scanId } });
        resumeId = v.id;
      }
      s.textContent = '';
      const steps = [];
      if (resumeId) steps.push(`<li><b>Tailor your resume</b><span>Opens with this job's missing keywords and gaps beside it. Add what you really have, then re-check and download.</span><a class="button primary small" href="${fixUrl(resumeId, r.scanId)}">Open tailored resume →</a></li>`);
      if (wantLetter) steps.push(`<li><b>Your cover letter</b><span>A full first draft for ${E(role)} at ${E(company)}, written from your resume. Need a second letter? Make another from the cover-letter page.</span><button class="button ${resumeId ? 'secondary' : 'primary'} small" type="button" id="nxLetterGo">Open cover letter →</button></li>`);
      ready.innerHTML = `<h4>Your drafts are ready</h4><ol class="ats-ready">${steps.join('')}</ol><p class="ats-muted">Downloading a clean resume or letter uses 1 credit of its own kind and adds the job to your Applications automatically.</p>`;
      $('#nxLetterGo')?.addEventListener('click', letterForJob);
    } catch (e) { s.textContent = e.message || 'Could not create the drafts.'; s.classList.add('bad'); } finally { go.disabled = false; }
  }

  function history() {
    const h = $('#atsHistory');
    h.innerHTML = '<h3>Recent checks</h3>' + (st.scans.length ? `<div class="ats-hist">${st.scans.map(x => `<button type="button" class="ats-hrow" data-scan="${E(x.id)}"><b class="${tone(x.score)}">${x.score}%</b><span>${E(x.title || 'Job check')}${x.company ? ' · ' + E(x.company) : ''}${x.resumeTitle ? `<small class="ats-hres">Resume: ${E(x.resumeTitle)}</small>` : ''}</span><small>${E(day(x.createdAt))}</small></button>`).join('')}</div>` : '<p class="ats-muted">Your checks will appear here.</p>');
  }

  // The meter and the history load on their own: if one of them fails, the check itself still works.
  async function refresh() {
    const [a, s] = await Promise.allSettled([api('/api/allowance'), api('/api/ats/scans')]);
    if (a.status === 'fulfilled') { st.allowance = a.value; meter(); }
    if (s.status === 'fulfilled') { st.scans = s.value.scans || []; history(); }
    else if (!st.scans.length) $('#atsHistory').innerHTML = '<h3>Recent checks</h3><p class="ats-muted">Your recent checks could not be loaded just now. They are safe — refresh the page to see them.</p>';
    if (a.status === 'rejected' && s.status === 'rejected') throw a.reason;
  }

  async function load(attempt = 0) {
    shell();
    try {
      const list = await api('/api/resumes');
      st.resumes = (list.resumes || []).slice().sort((a, b) => (b.is_primary ? 1 : 0) - (a.is_primary ? 1 : 0));
      st.resumeId = st.resumes.some(r => r.id === st.resumeId) ? st.resumeId : st.resumes[0]?.id || '';
      let want = ''; try { want = sessionStorage.getItem('cvtara-ats-resume') || ''; sessionStorage.removeItem('cvtara-ats-resume'); } catch { /* ignore */ }
      if (want && st.resumes.some(r => r.id === want)) st.resumeId = want;
    } catch (e) {
      // shared hosting sometimes drops one request when the workspace opens: try once more before showing anything
      if (attempt < 2 && (!e.status || e.status >= 500 || e.status === 429)) { await wait(900 * (attempt + 1)); return load(attempt + 1); }
      $('#atsInput').innerHTML = `<p role="alert">${E(friendly(e))}</p><button class="button secondary" type="button" data-ats-retry>Try again</button>`;
      return;
    }
    inputPanel();
    refresh().catch(() => {});
  }

  host.addEventListener('change', async e => {
    if (e.target.id === 'atsResume') st.resumeId = e.target.value;
    if (e.target.id === 'atsFile') {
      const f = e.target.files[0]; if (!f) return;
      say('Reading the file…');
      try { st.fileText = await readJobFile(f); st.fileName = f.name; src(); say('File read. Check the text, then run the check.'); } catch (err) { say(err.name === 'PasswordException' ? 'Password-protected PDFs cannot be read. Paste the text instead.' : err.message, true); }
    }
  });
  host.addEventListener('click', async e => {
    const t = e.target.closest('[data-src]');
    if (t) { st.source = t.dataset.src; host.querySelectorAll('[data-src]').forEach(b => b.setAttribute('aria-selected', String(b === t))); src(); return; }
    if (e.target.closest('#atsRun')) { run(); return; }
    if (e.target.closest('[data-ats-retry]')) { load(); return; }
    if (e.target.closest('[data-ats-run-saved]')) { run({ skipUnsaved: true }); return; }
    if (e.target.closest('[data-ats-save-run]')) {
      say('Saving your changes…');
      if (await window.CVTaraStudio?.saveToProfile?.()) run({ skipUnsaved: true });
      else say('Your changes could not be saved. Open the Resume Builder, press Save to profile, then check again.', true);
      return;
    }
    if (e.target.closest('[data-ats-fix]')) { fixForJob(); return; }
    if (e.target.closest('[data-ats-letter]')) { letterForJob(); return; }
    if (e.target.closest('#atsPaste')) {
      try { const clip = await navigator.clipboard.readText(); if (clip) { $('#atsText').value = clip.slice(0, 40000); say('Pasted from your clipboard.'); } else say('Your clipboard is empty.', true); } catch { say('Your browser did not allow clipboard access — press Ctrl+V in the box instead.', true); }
      return;
    }
    const d = e.target.closest('[data-scan]');
    if (d) {
      try {
        const x = (await api('/api/ats/scans/' + d.dataset.scan)).scan;
        st.run = { scanId: x.id, report: x.report, url: x.jobUrl || '', title: x.title || '', company: x.company || '', resumeId: x.resumeId, resumeTitle: x.resumeTitle || '', sameContent: null, fresh: false };
        report(x.createdAt); $('#atsOut').scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (err) { say(err.message, true); }
      return;
    }
    const drop = e.target.closest('.ats-drop');
    if (drop && e.target.tagName !== 'INPUT') { $('#atsFile')?.click(); }
  });
  window.CVTaraAts = { select(id) { if (st.resumes.some(r => r.id === id)) { st.resumeId = id; const el = $('#atsResume'); if (el) el.value = id; } } };
  // coming back to ATS Match (for example after saving in the builder) re-reads the resume list, so scores and titles are current
  document.addEventListener('click', e => { if (e.target.closest('[data-workspace-view="match"]')) { if (st.resumes.length && !st.busy) api('/api/resumes').then(l => { st.resumes = (l.resumes || []).slice().sort((a, b) => (b.is_primary ? 1 : 0) - (a.is_primary ? 1 : 0)); const el = $('#atsResume'); if (el) el.innerHTML = options(); }).catch(() => {}); refresh().catch(() => {}); } });
  load();
})();
