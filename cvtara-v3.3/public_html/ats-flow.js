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
  const st = { resumes: [], resumeId: '', source: 'link', fileText: '', fileName: '', allowance: null, scans: [], run: null, busy: false };
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

  function inputPanel() {
    const box = $('#atsInput');
    if (!st.resumes.length) {
      box.innerHTML = '<h3>Start with your resume</h3><p class="ats-muted">ATS Match checks one of your saved resumes. Build your primary resume first — it takes a few minutes.</p><div class="ats-empty-actions"><button class="button primary" type="button" data-ats-upload>📤 Upload my resume</button><button class="button secondary" type="button" data-workspace-view="builder">Build one from a template →</button></div>';
      box.querySelector('[data-ats-upload]').onclick = () => window.CVTaraResumeImport?.pick();
      return;
    }
    const tab = (k, label) => `<button type="button" role="tab" aria-selected="${st.source === k}" data-src="${k}">${label}</button>`;
    box.innerHTML = `<label class="ats-field"><span>1 · Your resume</span><select id="atsResume">${st.resumes.map(r => `<option value="${E(r.id)}" ${r.id === st.resumeId ? 'selected' : ''}>${r.is_primary ? '★ Primary — ' : ''}${E(r.title)}${r.ats_score != null ? ' · ATS-friendly ' + r.ats_score + '%' : ''}</option>`).join('')}</select></label>
<div class="ats-field"><span>2 · The job</span><div class="ats-tabs" role="tablist">${tab('link', 'Job link')}${tab('file', 'PDF / text file')}${tab('text', 'Paste text')}</div></div>
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

  async function run() {
    if (st.busy) return;
    const text = $('#atsText')?.value.trim() || '', url = $('#atsUrl')?.value.trim() || '';
    if (!st.resumeId) { say('Choose a resume first.', true); return; }
    if (st.source === 'link' && !/^https?:\/\//i.test(url)) { say('Paste the full link of the job posting, starting with https://', true); $('#atsUrl')?.focus(); return; }
    if (st.source !== 'link' && text.length < 80) { say('Add the job description first (at least a few lines).', true); return; }
    st.busy = true; const btn = $('#atsRun'); btn.disabled = true; $('#atsOut').innerHTML = '';
    try {
      say(st.source === 'link' ? 'Opening the job link and reading the posting…' : 'Comparing your resume with the job…');
      const body = { resumeId: st.resumeId, source: st.source, title: $('#atsTitle').value.trim(), company: $('#atsCompany').value.trim() };
      if (st.source === 'link') body.url = url; else body.text = text;
      const s = await api('/api/ats/scan', { method: 'POST', body });
      if (s.title && !$('#atsTitle').value) $('#atsTitle').value = s.title;
      if (s.company && !$('#atsCompany').value) $('#atsCompany').value = s.company;
      st.run = { scanId: s.scanId, report: s.report, url: s.jobUrl || '', title: s.title || '', company: s.company || '', resumeId: st.resumeId, fresh: true };
      await refresh();
      report();
      say('Done — 1 ATS scan used.');
      $('#atsOut').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) { say((e.message || 'The check could not finish.') + (/scan/i.test(e.message || '') ? '' : ' No scan was used.'), true); } finally { st.busy = false; if ($('#atsRun')) $('#atsRun').disabled = false; }
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
<div class="ats-choose"><label><input type="checkbox" id="nxResume" checked><b>Tailored resume</b><small>A copy of your resume for this job</small></label><label><input type="checkbox" id="nxLetter" checked><b>Cover letter</b><small>Starts with this role and company</small></label></div>
<p class="ats-muted">Building and saving drafts is free. A credit is used when you download: 1 resume credit for a resume, 1 cover-letter credit for each letter (a resume plus 2 letters uses 3 credits). We show a notice after every download, and each download is added to your Applications.</p>
<button class="button primary" id="nxGo" type="button">Start building →</button><p role="status" class="ats-status" id="nxStatus"></p><div id="nxReady"></div></section>`;
  }

  function report(saved) {
    const r = st.run, rep = r.report, res = rep.result, out = $('#atsOut'), c = res.counts, kw = rep.keywords || { total: 0, found: [], missing: [], pct: null };
    out.innerHTML = `<section class="panel panel-pad ats-report"><div class="ats-score ${tone(rep.score)}" style="--v:${rep.score}"><div class="ats-ring"><b>${rep.score}%</b><span>match</span></div><div><h3>${E(verdict(rep.score))}</h3><p class="ats-muted">${E(r.title || 'This job')}${r.company ? ' · ' + E(r.company) : ''}${saved ? ' · checked ' + E(day(saved)) : ''}. This estimate combines how well each requirement is backed by evidence in your resume with how many of the job's keywords it contains. It is guidance, not a real employer ATS result.</p><div class="ats-counts"><span><b>${c.evidenced}</b> requirements evidenced</span><span><b>${c.mentioned}</b> mentioned only</span><span><b>${c.not_found + c.review}</b> missing / to review</span>${kw.pct === null ? '' : `<span><b>${kw.pct}%</b> keyword coverage</span>`}</div></div></div>
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
      if (resumeId) steps.push(`<li><b>Tailor your resume</b><span>Edit it for ${E(role)} at ${E(company)}, then download.</span><a class="button primary small" href="/workspace.html?view=builder&draft=${encodeURIComponent(resumeId)}">Open tailored resume →</a></li>`);
      if (wantLetter) steps.push(`<li><b>Write your cover letter</b><span>Starts with ${E(role)} at ${E(company)}. Need a second letter? Make another from the cover-letter page.</span><button class="button ${resumeId ? 'secondary' : 'primary'} small" type="button" id="nxLetterGo">Open cover letter →</button></li>`);
      ready.innerHTML = `<h4>Your drafts are ready</h4><ol class="ats-ready">${steps.join('')}</ol><p class="ats-muted">Downloading a clean resume or letter uses 1 credit of its own kind and adds the job to your Applications automatically.</p>`;
      $('#nxLetterGo')?.addEventListener('click', () => {
        try {
          const uid = document.querySelector('meta[name=cvtara-user]')?.content || 'guest';
          localStorage.setItem('cvtara-cover-letter-v1-' + uid, JSON.stringify({ version: 1, title: `${role} — ${company}`.slice(0, 180), personal: { name: '', email: '', phone: '', city: '' }, role, company, jobUrl: r.url || '', id: null, revision: null }));
        } catch { /* private mode: the studio simply starts empty */ }
        location.href = '/workspace.html?view=letter';
      });
    } catch (e) { s.textContent = e.message || 'Could not create the drafts.'; s.classList.add('bad'); } finally { go.disabled = false; }
  }

  function history() {
    const h = $('#atsHistory');
    h.innerHTML = '<h3>Recent checks</h3>' + (st.scans.length ? `<div class="ats-hist">${st.scans.map(x => `<button type="button" class="ats-hrow" data-scan="${E(x.id)}"><b class="${tone(x.score)}">${x.score}%</b><span>${E(x.title || 'Job check')}${x.company ? ' · ' + E(x.company) : ''}</span><small>${E(day(x.createdAt))}</small></button>`).join('')}</div>` : '<p class="ats-muted">Your checks will appear here.</p>');
  }

  async function refresh() {
    const [a, s] = await Promise.all([api('/api/allowance'), api('/api/ats/scans')]);
    st.allowance = a; st.scans = s.scans || []; meter(); history();
  }

  async function load() {
    shell();
    try {
      const list = await api('/api/resumes');
      st.resumes = (list.resumes || []).slice().sort((a, b) => (b.is_primary ? 1 : 0) - (a.is_primary ? 1 : 0));
      st.resumeId = st.resumes[0]?.id || '';
      let want = ''; try { want = sessionStorage.getItem('cvtara-ats-resume') || ''; sessionStorage.removeItem('cvtara-ats-resume'); } catch { /* ignore */ }
      if (want && st.resumes.some(r => r.id === want)) st.resumeId = want;
      await refresh();
    } catch (e) { $('#atsInput').innerHTML = `<p role="alert">${E(e.message)}</p>`; return; }
    inputPanel();
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
    if (e.target.closest('#atsPaste')) {
      try { const clip = await navigator.clipboard.readText(); if (clip) { $('#atsText').value = clip.slice(0, 40000); say('Pasted from your clipboard.'); } else say('Your clipboard is empty.', true); } catch { say('Your browser did not allow clipboard access — press Ctrl+V in the box instead.', true); }
      return;
    }
    const d = e.target.closest('[data-scan]');
    if (d) {
      try {
        const x = (await api('/api/ats/scans/' + d.dataset.scan)).scan;
        st.run = { scanId: x.id, report: x.report, url: x.jobUrl || '', title: x.title || '', company: x.company || '', resumeId: x.resumeId, fresh: false };
        report(x.createdAt); $('#atsOut').scrollIntoView({ behavior: 'smooth', block: 'start' });
      } catch (err) { say(err.message, true); }
      return;
    }
    const drop = e.target.closest('.ats-drop');
    if (drop && e.target.tagName !== 'INPUT') { $('#atsFile')?.click(); }
  });
  window.CVTaraAts = { select(id) { if (st.resumes.some(r => r.id === id)) { st.resumeId = id; const el = $('#atsResume'); if (el) el.value = id; } } };
  document.addEventListener('click', e => { if (e.target.closest('[data-workspace-view="match"]') && st.resumes.length) { refresh().catch(() => {}); } });
  load();
})();
