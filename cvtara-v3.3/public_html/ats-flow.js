(() => {
  'use strict';
  // ATS Match: pick a saved resume, add a job (link, PDF/text file or pasted text), get a report of what is missing,
  // then choose what to build. A scan is reserved on the server first, so the allowance is enforced in one place; if
  // the review cannot finish, the scan is given back.
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  const host = document.getElementById('view-match');
  if (!host) return;
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const $ = (s, r = host) => r.querySelector(s);
  const statusLabel = { evidenced: 'Evidence found', mentioned: 'Mentioned only', not_found: 'Not found', review: 'Needs your review' };
  const weight = { Required: 3, Preferred: 1 };
  const credit = { evidenced: 1, mentioned: 0.5, review: 0.25, not_found: 0 };
  const st = { resumes: [], resumeId: '', source: 'link', fileText: '', fileName: '', allowance: null, scans: [], run: null, busy: false };

  const bullets = t => String(t || '').split('\n').map(l => l.replace(/^\s*[•*-]\s*/, '').trim()).filter(Boolean);
  function resumeToText(d) {
    const p = d.personal || {}, out = [p.name, p.role, [p.email, p.phone, p.city, p.linkedin, p.portfolio].filter(Boolean).join(' | ')];
    const when = r => [r.start, r.current ? 'Present' : (r.end || r.date)].filter(Boolean).join(' - ');
    if (d.summary) out.push('SUMMARY', d.summary);
    if (d.skills) out.push('SKILLS', d.skills);
    if ((d.experience || []).length) { out.push('EXPERIENCE'); d.experience.forEach(r => out.push([r.role, r.company, r.city, when(r)].filter(Boolean).join(' | '), ...bullets(r.bullets || r.details).map(b => '• ' + b))); }
    if ((d.projects || []).length) { out.push('PROJECTS'); d.projects.forEach(r => out.push([r.name, r.tools, r.link].filter(Boolean).join(' | '), ...bullets(r.bullets || r.details).map(b => '• ' + b))); }
    if ((d.education || []).length) { out.push('EDUCATION'); d.education.forEach(r => out.push([r.degree, r.school, when(r)].filter(Boolean).join(' | '), ...bullets(r.details || r.bullets))); }
    if ((d.certifications || []).length) { out.push('CERTIFICATIONS'); d.certifications.forEach(r => out.push([r.name, r.issuer, r.date].filter(Boolean).join(' | '))); }
    if (d.languages) out.push('LANGUAGES', d.languages);
    if (d.awards) out.push('AWARDS', d.awards);
    return out.filter(x => x !== undefined && x !== null && String(x).trim() !== '').join('\n');
  }

  const STOP = new Set(('the a an and or of for to in on at by with as from be is are was were have has this that your our you we will must should required requires requirements about into over under their they them his her its it who whom which what when where while also able ability across all any can could may might more most other some such than then there these those through using use used within without would work working works role roles job jobs team teams candidate candidates company looking join looking skills skill strong good great excellent knowledge experience experienced years year plus etc including include includes ensure ensuring manage managing responsible responsibilities responsibility preferred nice have having bonus must apply application applications position opportunity opportunities benefits offer offers hybrid remote office full time part employee employees environment business new help support provide providing well one two three first based related relevant like both each every per via day days month months week weeks need needs needed make making get getting take taking high level highly ideal best build write present develop design deliver create lead drive own maintain participate collaborate communicate perform conduct provide identify improve analyse analyze understand deep proven track record seeking looking hire hiring join').split(/\s+/));
  const stem = w => w.length > 5 ? w.replace(/(ing|ed|es|ly|ions?|ment|s)$/, '') : w.replace(/s$/, '');
  function tokens(t) { return (String(t).match(/[A-Za-z][A-Za-z0-9+#.]*[A-Za-z0-9+#]|[A-Za-z]/g) || []); }
  function keywordCoverage(resumeText, jobText, title) {
    const have = new Set(tokens(resumeText).map(w => stem(w.toLowerCase())));
    const freq = new Map(), shown = new Map(), tl = String(title || '').toLowerCase(), src = String(jobText), re = /[A-Za-z][A-Za-z0-9+#.]*[A-Za-z0-9+#]|[A-Za-z]/g;
    let m;
    while ((m = re.exec(src))) {
      const raw = m[0], w = raw.toLowerCase();
      if (w.length < 3 || STOP.has(w)) continue;
      const before = src.slice(Math.max(0, m.index - 3), m.index).replace(/[ \t•*\-–]+$/, ''), starts = before === '' || /[.!?:\n]$/.test(before);
      // A capital letter inside a sentence, an acronym or a symbol marks a tool, product or skill name.
      const named = /[A-Z].*[A-Z]|[+#]/.test(raw) || (/^[A-Z][a-z]/.test(raw) && !starts);
      const k = stem(w), cur = freq.get(k) || { n: 0, bonus: 0 };
      cur.n++; if (named) cur.bonus = Math.max(cur.bonus, 2); if (tl.includes(w)) cur.bonus += 2;
      freq.set(k, cur); if (!shown.has(k) || named) shown.set(k, raw);
    }
    const ranked = [...freq.entries()].map(([k, v]) => [k, v.n + v.bonus, v]).filter(([, , v]) => v.n >= 2 || v.bonus > 0).sort((a, b) => b[1] - a[1]).slice(0, 30);
    const found = ranked.filter(([k]) => have.has(k)).map(([k]) => shown.get(k)), missing = ranked.filter(([k]) => !have.has(k)).map(([k]) => shown.get(k));
    return { total: ranked.length, found, missing, pct: ranked.length ? Math.round((found.length / ranked.length) * 100) : null };
  }
  function score(r, kw) {
    let got = 0, total = 0;
    for (const x of r.requirements) { const w = weight[x.priority] || 2; total += w; got += w * (credit[x.status] ?? 0); }
    const ev = total ? (got / total) * 100 : null;
    if (ev === null && kw.pct === null) return 0;
    if (ev === null) return kw.pct;
    if (kw.pct === null) return Math.round(ev);
    const w = Math.min(0.7, 0.1 * r.requirements.length);
    return Math.round(w * ev + (1 - w) * kw.pct);
  }
  function gaps(r, kw) {
    const order = { not_found: 0, mentioned: 1, review: 2 }, out = [];
    if (kw.missing.length) out.push(('Keywords · Missing from your resume: ' + kw.missing.slice(0, 12).join(', ') + '. Add them only where they truthfully describe your experience.').slice(0, 240));
    out.push(...r.requirements.filter(x => x.status !== 'evidenced')
      .sort((a, b) => ((weight[b.priority] || 2) - (weight[a.priority] || 2)) || ((order[a.status] ?? 3) - (order[b.status] ?? 3)))
      .slice(0, 30).map(x => `${x.priority} · ${x.name} — ${statusLabel[x.status]}. ${x.advice || ''}`.slice(0, 240)));
    out.push(...(r.parameters || []).filter(x => x.status === 'Review').slice(0, 6).map(x => `Resume format · ${x.name} — ${x.action}`.slice(0, 240)));
    return out.slice(0, 40);
  }
  const tone = n => n >= 75 ? 'good' : n >= 50 ? 'mid' : 'low';
  const verdict = n => n >= 75 ? 'Strong match — polish the details and apply.' : n >= 50 ? 'Partial match — close the gaps below before you apply.' : 'Low match — this resume needs work for this job.';

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
      box.innerHTML = '<h3>Start with your resume</h3><p class="ats-muted">ATS Match checks one of your saved resumes. Build your primary resume first — it takes a few minutes.</p><button class="button primary" type="button" data-workspace-view="builder">Build my resume →</button>';
      return;
    }
    const tab = (k, label) => `<button type="button" role="tab" aria-selected="${st.source === k}" data-src="${k}">${label}</button>`;
    box.innerHTML = `<label class="ats-field"><span>1 · Your resume</span><select id="atsResume">${st.resumes.map(r => `<option value="${E(r.id)}" ${r.id === st.resumeId ? 'selected' : ''}>${r.is_primary ? '★ Primary — ' : ''}${E(r.title)}</option>`).join('')}</select></label>
<div class="ats-field"><span>2 · The job</span><div class="ats-tabs" role="tablist">${tab('link', 'Job link')}${tab('file', 'PDF / text file')}${tab('text', 'Paste text')}</div></div>
<div id="atsSrc"></div>
<div class="ats-two"><label class="ats-field"><span>Job title <small>(optional)</small></span><input id="atsTitle" maxlength="180" placeholder="Filled in from the job"></label><label class="ats-field"><span>Company <small>(optional)</small></span><input id="atsCompany" maxlength="180" placeholder="Filled in from the job"></label></div>
<button class="button primary full" id="atsRun" type="button">Run ATS check</button>
<p class="ats-muted" id="atsNote">Each check uses 1 ATS scan. Your resume and the job are compared in your browser; the job text is saved with the report.</p>
<p role="status" id="atsStatus" class="ats-status"></p>`;
    src();
  }

  function src() {
    const b = $('#atsSrc');
    if (!b) return;
    if (st.source === 'link') {
      b.innerHTML = '<label class="ats-field"><span class="sr-only">Job link</span><input id="atsUrl" type="url" inputmode="url" maxlength="1000" placeholder="https://company.com/careers/job-123"></label><p class="ats-muted">We open the link and read the posting again every time you run a check, so the report matches the current job. Postings behind a login cannot be read — use the file or paste tab for those.</p>';
    } else if (st.source === 'file') {
      b.innerHTML = `<label class="ats-drop"><input id="atsFile" type="file" accept=".pdf,.txt,.docx,.md,text/plain,application/pdf" hidden><b>${st.fileName ? E(st.fileName) : 'Choose the job as PDF, DOCX or text file'}</b><small>Up to 5 MB · read on this device</small></label><label class="ats-field"><span class="sr-only">Job text</span><textarea id="atsText" rows="8" maxlength="40000" placeholder="The text read from your file appears here. Correct it if anything looks wrong.">${E(st.fileText)}</textarea></label>`;
    } else {
      b.innerHTML = '<label class="ats-field"><span class="sr-only">Job description</span><textarea id="atsText" rows="10" maxlength="40000" placeholder="Paste the full job description — responsibilities, requirements and qualifications."></textarea></label>';
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
    let scanId = null;
    try {
      say(st.source === 'link' ? 'Opening the job link and reading the posting…' : 'Reserving your scan…');
      const body = { resumeId: st.resumeId, source: st.source, title: $('#atsTitle').value.trim(), company: $('#atsCompany').value.trim() };
      if (st.source === 'link') body.url = url; else body.text = text;
      const s = await api('/api/ats/scan', { method: 'POST', body });
      scanId = s.scanId;
      if (s.title && !$('#atsTitle').value) $('#atsTitle').value = s.title;
      if (s.company && !$('#atsCompany').value) $('#atsCompany').value = s.company;
      say('Reading your resume and comparing it with the job…');
      const rr = await api('/api/resumes/' + encodeURIComponent(st.resumeId));
      const resumeText = resumeToText(rr.resume.data || {});
      if (resumeText.length < 60) throw Error('This resume has too little content to check. Add your experience and skills in the builder, then try again.');
      const result = await window.CVTaraEvidence.analyse(resumeText, s.jobText);
      const kw = keywordCoverage(resumeText, s.jobText, $('#atsTitle').value || s.title);
      if (!result.requirements.length && kw.total < 5) throw Error('No clear requirements were found in this job. Make sure the page or file contains the responsibilities and qualifications.');
      const sc = score(result, kw), g = gaps(result, kw);
      await api('/api/ats/scans/' + scanId + '/result', { method: 'POST', body: { score: sc, counts: { ...result.counts, keywordsFound: kw.found.length, keywordsMissing: kw.missing.length }, gaps: g, title: $('#atsTitle').value.trim(), company: $('#atsCompany').value.trim() } });
      scanId = null;
      st.run = { result, kw, score: sc, gaps: g, jobText: s.jobText, url: st.source === 'link' ? url : '', title: $('#atsTitle').value.trim(), company: $('#atsCompany').value.trim(), resumeId: st.resumeId };
      await refresh();
      report();
      say('Done — 1 ATS scan used.');
      $('#atsOut').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      if (scanId) { try { await api('/api/ats/scans/' + scanId + '/void', { method: 'POST', body: {} }); } catch { /* the server also frees unfinished scans */ } await refresh(); say((e.message || 'The check could not finish.') + ' No scan was used.', true); } else say(e.message || 'The check could not finish.', true);
    } finally { st.busy = false; if ($('#atsRun')) $('#atsRun').disabled = false; }
  }

  function lackingList(list) {
    return list.length ? `<ol class="ats-gaps">${list.map(x => `<li>${E(x)}</li>`).join('')}</ol>` : '<p class="ats-muted">No gaps were found by these rules. Review the evidence for accuracy before you apply.</p>';
  }

  function nextPanel() {
    const r = st.run;
    return `<section class="panel panel-pad ats-next" id="atsNext"><span class="eyebrow">STEP 4</span><h3>What do you want to build for this job?</h3>
<div class="ats-two"><label class="ats-field"><span>Job title</span><input id="nxRole" maxlength="160" value="${E(r.title)}" placeholder="e.g. Business Analyst"></label><label class="ats-field"><span>Company</span><input id="nxCompany" maxlength="160" value="${E(r.company)}" placeholder="e.g. Infosys"></label></div>
<div class="ats-choose"><label><input type="checkbox" id="nxResume" checked><b>Tailored resume</b><small>A copy of your resume for this job</small></label><label><input type="checkbox" id="nxLetter" checked><b>Cover letter</b><small>Starts with this role and company</small></label></div>
<p class="ats-muted">Building and saving drafts is free. A credit is used when you download: 1 resume credit for a resume, 1 cover-letter credit for each letter (a resume plus 2 letters uses 3 credits). We show a notice after every download, and each download is added to your Applications.</p>
<button class="button primary" id="nxGo" type="button">Start building →</button><p role="status" class="ats-status" id="nxStatus"></p><div id="nxReady"></div></section>`;
  }

  function report() {
    const r = st.run, out = $('#atsOut');
    out.innerHTML = `<section class="panel panel-pad ats-report"><div class="ats-score ${tone(r.score)}" style="--v:${r.score}"><div class="ats-ring"><b>${r.score}%</b><span>match</span></div><div><h3>${E(verdict(r.score))}</h3><p class="ats-muted">${E(r.title || 'This job')}${r.company ? ' · ' + E(r.company) : ''}. This estimate combines how well each requirement is backed by evidence in your resume with how many of the job's keywords it contains. It is guidance, not a real employer ATS result.</p><div class="ats-counts"><span><b>${r.result.counts.evidenced}</b> requirements evidenced</span><span><b>${r.result.counts.mentioned}</b> mentioned only</span><span><b>${r.result.counts.not_found + r.result.counts.review}</b> missing / to review</span>${r.kw.pct === null ? '' : `<span><b>${r.kw.pct}%</b> keyword coverage</span>`}</div></div></div>
${r.kw.total ? `<h3>Keywords from the job</h3><div class="ats-kw">${r.kw.found.map(k => `<span class="ok">✓ ${E(k)}</span>`).join('')}${r.kw.missing.map(k => `<span class="no">✗ ${E(k)}</span>`).join('')}</div>` : ''}
<h3>Where you are lacking</h3>${lackingList(r.gaps)}
<details class="ats-detail"><summary>Full report — every requirement, evidence and checks</summary><div class="ats-full" id="atsFull"></div></details></section>${nextPanel()}`;
    const full = $('#atsFull');
    full.innerHTML = window.CVTaraEvidence.report(r.result, 'ats');
    full.querySelectorAll('[data-import-builder]').forEach(b => b.remove());
    full.querySelectorAll('a[href*="view=letter"]').forEach(a => a.remove());
    $('#nxGo').onclick = build;
  }

  function savedSummary(s) {
    st.run = null;
    const out = $('#atsOut');
    out.innerHTML = `<section class="panel panel-pad ats-report"><div class="ats-score ${tone(s.score)}" style="--v:${s.score}"><div class="ats-ring"><b>${s.score}%</b><span>match</span></div><div><h3>${E(verdict(s.score))}</h3><p class="ats-muted">${E(s.title || 'Saved check')}${s.company ? ' · ' + E(s.company) : ''} · checked ${E(new Date(String(s.createdAt).replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }))}. Saved reports show the summary; run a new check to compare an updated resume.</p></div></div><h3>Where you were lacking</h3>${lackingList(s.result?.gaps || [])}</section>`;
    st.run = { result: null, score: s.score, gaps: s.result?.gaps || [], jobText: s.jobText, url: s.jobUrl || '', title: s.title || '', company: s.company || '', resumeId: s.resumeId };
    out.insertAdjacentHTML('beforeend', nextPanel());
    $('#nxGo').onclick = build;
    out.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
        const v = await api('/api/resumes/' + encodeURIComponent(r.resumeId) + '/variant', { method: 'POST', body: { company, role, jobDescription: (r.jobText || '').slice(0, 40000), ...(r.url ? { jobUrl: r.url } : {}) } });
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
    h.innerHTML = '<h3>Recent checks</h3>' + (st.scans.length ? `<div class="ats-hist">${st.scans.map(x => `<button type="button" class="ats-hrow" data-scan="${E(x.id)}"><b class="${tone(x.score)}">${x.score}%</b><span>${E(x.title || 'Job check')}${x.company ? ' · ' + E(x.company) : ''}</span><small>${E(new Date(String(x.createdAt).replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }))}</small></button>`).join('')}</div>` : '<p class="ats-muted">Your checks will appear here.</p>');
  }

  async function refresh() {
    const [a, s] = await Promise.all([api('/api/allowance'), api('/api/ats/scans')]);
    st.allowance = a; st.scans = s.scans || []; meter(); history();
  }

  async function load() {
    shell();
    try {
      const [list] = await Promise.all([api('/api/resumes')]);
      st.resumes = (list.resumes || []).slice().sort((a, b) => (b.is_primary ? 1 : 0) - (a.is_primary ? 1 : 0));
      st.resumeId = st.resumes[0]?.id || '';
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
    const d = e.target.closest('[data-scan]');
    if (d) { try { const x = await api('/api/ats/scans/' + d.dataset.scan); savedSummary(x.scan); } catch (err) { say(err.message, true); } return; }
    const drop = e.target.closest('.ats-drop');
    if (drop && e.target.tagName !== 'INPUT') { $('#atsFile')?.click(); }
  });
  document.addEventListener('click', e => { if (e.target.closest('[data-workspace-view="match"]') && st.resumes.length) { refresh().catch(() => {}); } });
  load();
})();
