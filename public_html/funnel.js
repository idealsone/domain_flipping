(() => {
  'use strict';
  // Landing funnel: upload/paste resume + job description -> ATS check -> build -> packages.
  const form = document.getElementById('fnForm');
  if (!form) return;
  const $ = (s, r = document) => r.querySelector(s);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const track = (step, detail = '') => { try { api('/api/funnel', { method: 'POST', body: { step, detail } }).catch(() => {}); } catch { /* analytics must never block the page */ } };
  const status = $('#fnStatus');
  const say = (msg, bad) => { status.textContent = msg; status.classList.toggle('is-error', !!bad); };
  const signedIn = () => (document.querySelector('meta[name=cvtara-user]')?.content || 'guest') !== 'guest';
  let tab = 'file', fileText = '', last = null;

  const SAMPLE_RESUME = 'Priya Nair\npriya.nair@example.com | +91 98765 43210 | Pune\n\nProfile\nHR executive with 3 years of experience in recruitment and onboarding for IT and operations roles.\n\nExperience\nHR Executive, Acme Technologies, Pune (2022 - Present)\n- Screened 400+ candidates and coordinated interviews with hiring managers across 6 teams.\n- Prepared weekly hiring reports in Excel and shared them with department heads.\n- Reduced average time to schedule interviews from 5 days to 2 days.\n\nEducation\nMBA (Human Resources), Pune University, 2021\n\nSkills\nRecruitment, Excel, communication, onboarding';
  const SAMPLE_JD = 'Recruiter - IT hiring. Responsibilities: candidate sourcing on LinkedIn and Naukri, candidate screening, interview coordination, stakeholder management with hiring managers, reporting in Excel, onboarding new joiners. Requirements: 2+ years of recruitment experience, strong communication, ATS tool experience.';

  /* ---------- Reading a PDF/DOCX in the browser ---------- */
  async function readFile(file) {
    if (!file) throw Error('Choose one PDF or DOCX file.');
    if (file.size > 5 * 1024 * 1024) throw Error('Choose a file smaller than 5 MB.');
    const bytes = new Uint8Array(await file.arrayBuffer());
    let text = '';
    if (/\.pdf$/i.test(file.name) && new TextDecoder().decode(bytes.slice(0, 5)) === '%PDF-') {
      await import('/pdf-compat.mjs');
      const pdfjs = await import('/assets/vendor/pdf.min.mjs');
      pdfjs.GlobalWorkerOptions.workerSrc = '/pdf-worker-compat.mjs';
      const task = pdfjs.getDocument({ data: bytes, isEvalSupported: false });
      try {
        const doc = await task.promise;
        if (doc.numPages > 30) throw Error('Use a resume with 30 pages or fewer.');
        const pages = [];
        for (let i = 1; i <= doc.numPages; i++) { const c = await (await doc.getPage(i)).getTextContent(); pages.push(c.items.map(x => x.str + (x.hasEOL ? '\n' : ' ')).join('')); }
        text = pages.join('\n\n');
      } finally { await task.destroy(); }
    } else if (/\.docx$/i.test(file.name) && bytes[0] === 80 && bytes[1] === 75) {
      if (!window.JSZip) await new Promise((res, rej) => { const s = document.createElement('script'); s.src = '/assets/vendor/jszip.min.js'; s.onload = res; s.onerror = () => rej(Error('Could not load the document reader. Retry.')); document.head.append(s); });
      const zip = await JSZip.loadAsync(bytes);
      if (zip.file('word/vbaProject.bin')) throw Error('Macro-enabled files are not supported.');
      const part = zip.file('word/document.xml');
      if (!part) throw Error('This file is not a valid DOCX document.');
      const xml = await part.async('string');
      if (xml.length > 2000000) throw Error('The document contains too much content.');
      const doc = new DOMParser().parseFromString(xml, 'application/xml');
      if (doc.querySelector('parsererror')) throw Error('This document could not be read.');
      text = Array.from(doc.getElementsByTagNameNS('*', 'p')).map(p => Array.from(p.getElementsByTagNameNS('*', 't')).map(t => t.textContent).join('')).join('\n');
    } else throw Error('Choose a valid PDF or DOCX file.');
    text = text.trim();
    if (text.length < 30) throw Error('No readable text was found. For a scanned PDF, use Paste text.');
    if (text.length > 120000) throw Error('This document contains too much text.');
    return text;
  }

  function loadEngine() {
    if (window.CVTaraMatch) return Promise.resolve();
    return new Promise((res, rej) => { const s = document.createElement('script'); s.src = '/match-engine.js'; s.onload = res; s.onerror = () => rej(Error('Could not load the checker. Reload and retry.')); document.head.append(s); });
  }

  /* ---------- Scoring (transparent, based on the engine's own checks) ---------- */
  function score(r) {
    const checks = r.parameters.filter(p => p.status === 'Detected' || p.status === 'Review');
    const good = checks.filter(p => p.status === 'Detected').length;
    const ats = checks.length ? Math.round(100 * good / checks.length) : 0;
    const c = r.counts || {};
    const total = (c.evidenced || 0) + (c.mentioned || 0) + (c.not_found || 0);
    const match = total ? Math.round(100 * ((c.evidenced || 0) + 0.5 * (c.mentioned || 0)) / total) : null;
    return { ats, match, total };
  }
  const tone = n => n >= 75 ? 'good' : n >= 50 ? 'mid' : 'low';
  function ring(id, numId, value) {
    const el = $(id), n = $(numId);
    el.className = 'fn-ring' + (value == null ? '' : ' ' + tone(value));
    el.style.setProperty('--p', value ?? 0);
    n.textContent = value == null ? '–' : value + '%';
  }

  function render(r, s, hasJD) {
    ring('#fnAtsRing', '#fnAtsNum', s.ats);
    ring('#fnMatchRing', '#fnMatchNum', hasJD && s.total ? s.match : null);
    $('#fnMatchSmall').textContent = hasJD ? (s.total ? 'How many of the job’s requirements your resume shows' : 'No recognisable requirements were found in this job text') : 'Add a job description to see this';
    const fixes = [];
    (r.actions || []).slice(0, 4).forEach(a => fixes.push(`<li><b>${E(a.name)}</b><span>${E(a.advice || 'Add a truthful example if you have relevant experience.')}</span></li>`));
    r.parameters.filter(p => p.status === 'Review').slice(0, 4).forEach(p => fixes.push(`<li class="fmt"><b>${E(p.name)}</b><span>${E(p.action || p.evidence || '')}</span></li>`));
    $('#fnFixes').innerHTML = fixes.length ? fixes.join('') : '<li class="fmt"><b>No major gaps found</b><span>Review each section in the builder and keep every claim accurate.</span></li>';
    $('#fnReqs').innerHTML = hasJD && r.requirements.length ? r.requirements.filter(x => x.status !== 'review').map(x => `<span class="fn-chip ${x.status === 'evidenced' ? 'ok' : x.status === 'mentioned' ? 'part' : 'miss'}">${E(x.name)}</span>`).join('') || '<span class="fn-chip">No recognised requirements</span>' : '<span class="fn-chip">Paste a job description to see its requirements</span>';
    $('#fnChecks').innerHTML = r.parameters.map(p => `<li><span class="${p.status === 'Detected' ? 'y' : p.status === 'Review' ? 'n' : 'm'}">${p.status === 'Detected' ? '✓' : p.status === 'Review' ? '!' : '•'}</span>${E(p.name)}</li>`).join('');
    $('#fnBuildNote').textContent = signedIn() ? 'Opens the builder with your resume and this job.' : 'Free to start. Create an account to save your work.';
    const box = $('#fnResult');
    box.hidden = false;
    box.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    $('#fnResultTitle').focus({ preventScroll: true });
  }

  /* ---------- Form behaviour ---------- */
  document.querySelectorAll('[data-fn-tab]').forEach(b => b.addEventListener('click', () => {
    tab = b.dataset.fnTab;
    document.querySelectorAll('[data-fn-tab]').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
    document.querySelectorAll('[data-fn-pane]').forEach(p => p.hidden = p.dataset.fnPane !== tab);
  }));
  const drop = $('#fnDrop'), fileInput = $('#fnFile');
  async function takeFile(file) {
    fileText = ''; $('#fnFileLabel').textContent = file ? file.name : 'Choose a PDF or DOCX, or drop it here';
    if (!file) return;
    say('Reading your file…');
    try { fileText = await readFile(file); say('Resume read. Add the job description, then check.'); } catch (e) { fileText = ''; $('#fnFileLabel').textContent = 'Choose a PDF or DOCX, or drop it here'; say(e.message, true); }
  }
  fileInput.addEventListener('change', () => takeFile(fileInput.files[0]));
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-over'); }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
  drop.addEventListener('drop', e => { const f = e.dataTransfer?.files?.[0]; if (f) takeFile(f); });
  $('#fnSample').addEventListener('click', () => {
    document.querySelector('[data-fn-tab=text]').click();
    $('#fnText').value = SAMPLE_RESUME; $('#fnJD').value = SAMPLE_JD; say('Sample loaded. Press “Check my resume”.');
  });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const resume = (tab === 'file' ? fileText : $('#fnText').value).trim(), jd = $('#fnJD').value.trim();
    if (resume.length < 30) return say(tab === 'file' ? 'Choose your resume file first.' : 'Paste your resume text first (at least a few lines).', true);
    if (jd && jd.length < 40) return say('The job description looks too short. Paste the full posting or leave it empty.', true);
    const btn = $('#fnCheck'); btn.disabled = true; say('Checking…');
    try {
      await loadEngine();
      const r = CVTaraMatch.analyse(resume, jd), s = score(r);
      last = { resume, jd, ats: s.ats, match: jd && s.total ? s.match : null };
      render(r, s, !!jd);
      say('');
      track('ats_check', jd ? 'with_jd' : 'no_jd');
    } catch (err) { say(err.message || 'The check could not run. Please retry.', true); } finally { btn.disabled = false; }
  });

  $('#fnBuild').addEventListener('click', () => {
    if (!last) return;
    try { localStorage.setItem('cvtara-funnel', JSON.stringify({ resume: last.resume, jd: last.jd, ats: last.ats, match: last.match, at: Date.now() })); }
    catch { say('Your browser blocked saving. Allow site storage and retry.', true); return; }
    track('build_click');
    setTimeout(() => { location.href = signedIn() ? '/workspace?view=builder&from=check' : '/register?from=check'; }, 80);
  });

  /* ---------- Packages come from the owner console; rupee amounts stay behind sign-in ---------- */
  const plansBox = $('#fnPlans');
  async function plans() {
    try {
      const d = signedIn() ? await api('/api/checkout/catalog').catch(() => api('/api/business-public')) : await api('/api/business-public');
      const p = d.plans || {}, passes = ['d30', 'd60', 'd90'].map(k => [k, p[k]]).filter(([, x]) => x);
      const free = p.free || {};
      const authed = signedIn();
      const card = (k, x, feat) => `<article class="fn-plan${feat ? ' is-featured' : ''}">${x.tag ? `<span class="fn-badge">${E(x.tag)}</span>` : ''}<h3>${E(x.name)}</h3><div class="fn-price">After sign-in<small>Price is shown at checkout</small></div><ul><li>${E(x.kits)} job kits (resume + cover letter for one job)</li><li>${E(x.exports)} clean PDF or Word downloads</li>${x.accessDays ? `<li>${E(x.accessDays)} days of access</li>` : ''}<li>No automatic renewal</li></ul><a class="button ${feat ? 'primary' : 'secondary'} full" data-plan="${E(k)}" href="/checkout?plan=${encodeURIComponent(k)}">${authed ? 'Choose ' + E(x.name) : 'Sign in to continue'}</a></article>`;
      const cards = [`<article class="fn-plan"><h3>Free</h3><div class="fn-price">Free to start</div><ul><li>ATS check and job match</li><li>Build and preview your resume</li><li>${E(free.savedResumes ?? 2)} saved drafts</li><li class="no">No clean download</li></ul><a class="button secondary full" href="#fnForm">Start free</a></article>`];
      passes.forEach(([k, x], i) => cards.push(card(k, x, i === 1)));
      cards.push(`<article class="fn-plan"><h3>Need more kits?</h3><p>Add-on packs appear at checkout after you have an active pass, with a coupon field before you confirm and pay.</p><a class="button secondary full" href="/checkout?plan=d30">${authed ? 'Open checkout' : 'Sign in to continue'}</a></article>`);
      plansBox.innerHTML = cards.join('');
    } catch { plansBox.innerHTML = '<p>Packages could not load. <a href="/checkout?plan=d30">See packages</a></p>'; }
  }
  plansBox.addEventListener('click', e => { const a = e.target.closest('[data-plan]'); if (a) track('plan_click', a.dataset.plan.replace(/[^a-z0-9_-]/gi, '').slice(0, 40)); });
  plans();

  // Owner's "Website & design" settings (Admin > Website & design > Homepage content & colour) drive this page.
  const DEFAULT_TITLE = 'Will your resume pass the ATS? Find out in 30 seconds.';
  function applySettings(s) {
    if (!s) return;
    const title = $('#fnTitle'), lead = $('.fn-lead'), build = $('#fnBuild'), check = $('#fnCheck');
    if (title && s.heroTitle && s.heroTitle !== DEFAULT_TITLE) title.textContent = s.heroTitle;
    if (lead && s.heroDescription) lead.textContent = s.heroDescription;
    if (build && s.primaryCTA) build.textContent = s.primaryCTA + ' \u2192';
    if (check && s.secondaryCTA) check.textContent = s.secondaryCTA;
    if (s.accent && /^#[0-9a-f]{6}$/i.test(s.accent) && s.accent.toLowerCase() !== '#1557d2') document.documentElement.style.setProperty('--blue', s.accent);
  }
  applySettings(window.CVTaraSettings);
  document.addEventListener('cvtara-settings', e => applySettings(e.detail));
})();
