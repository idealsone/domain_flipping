(() => {
  'use strict';
  // Builder: "Tailor to a job" panel. Paste a job description and see, while you edit, which of its
  // requirements your resume already shows. It never writes text for you.
  const studio = document.getElementById('resumeStudio');
  if (!studio) return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const track = (step, detail = '') => { try { api('/api/funnel', { method: 'POST', body: { step, detail } }).catch(() => {}); } catch { /* ignore */ } };
  const KEY = 'cvtara-target-job';
  let job = {};
  try { job = JSON.parse(localStorage.getItem(KEY)) || {}; } catch { job = {}; }
  if (new URLSearchParams(location.search).get('from') === 'check' && job.fresh) { track('builder_open'); job.fresh = false; localStorage.setItem(KEY, JSON.stringify(job)); }

  const panel = document.createElement('section');
  panel.className = 'tp';
  panel.setAttribute('aria-label', 'Tailor this resume to a job');
  panel.innerHTML = `<div class="tp-head"><h3>Tailor to a job</h3><div class="tp-meter" aria-live="polite"><span>Job match</span><b id="tpScore">–</b><div class="tp-bar" aria-hidden="true"><i id="tpBar"></i></div></div></div>
<details id="tpDetails"><summary>Job description or job link</summary><textarea id="tpJD" placeholder="Paste the job posting here. Your match updates as you edit." maxlength="12000"></textarea><label class="tp-url">Job link <input id="tpUrl" type="url" maxlength="1000" placeholder="https://company.com/careers/role"></label></details>
<div class="tp-chips" id="tpChips"></div>
<ol class="tp-todo" id="tpTodo"></ol>
<div class="tp-plans" id="tpPlans" hidden><span>Ready to download?</span></div>
<p><button class="button secondary" type="button" id="tpLetter">Write the matching cover letter</button></p>`;
  studio.insertAdjacentElement('beforebegin', panel);
  const jdBox = panel.querySelector('#tpJD');
  const urlBox = panel.querySelector('#tpUrl');
  jdBox.value = job.jd || '';
  urlBox.value = job.url || '';
  if (!job.jd && !job.url) panel.querySelector('#tpDetails').open = true;

  function draftText(d) {
    const p = d.personal || {}, t = [];
    t.push([p.name, p.role].filter(Boolean).join('\n'));
    t.push([p.email, p.phone, p.city, p.linkedin, p.portfolio].filter(Boolean).join(' | '));
    if (d.summary) t.push('Summary\n' + d.summary);
    if (d.experience?.length) t.push('Experience\n' + d.experience.map(x => [x.role, x.company, [x.start, x.end].filter(Boolean).join(' - '), x.bullets].filter(Boolean).join('\n')).join('\n'));
    if (d.education?.length) t.push('Education\n' + d.education.map(x => [x.degree, x.school, [x.start, x.end].filter(Boolean).join(' - '), x.details].filter(Boolean).join('\n')).join('\n'));
    if (d.projects?.length) t.push('Projects\n' + d.projects.map(x => [x.name, x.role, x.tools, x.details].filter(Boolean).join('\n')).join('\n'));
    if (d.skills) t.push('Skills\n' + d.skills);
    if (d.certifications?.length) t.push('Certifications\n' + d.certifications.map(x => Object.values(x).filter(v => typeof v === 'string' && v.length < 300 && !/^[0-9a-f-]{36}$/.test(v)).join(' ')).join('\n'));
    if (d.languages) t.push('Languages\n' + d.languages);
    if (d.awards) t.push('Awards\n' + d.awards);
    return t.filter(Boolean).join('\n');
  }

  let engine = null, lastKey = '';
  function ensureEngine() {
    if (window.CVTaraMatch) return Promise.resolve();
    engine ||= new Promise((res, rej) => { const s = document.createElement('script'); s.src = '/match-engine.js'; s.onload = res; s.onerror = rej; document.head.append(s); });
    return engine;
  }
  async function refresh(force) {
    const jd = jdBox.value.trim();
    const d = window.CVTaraStudio?.getDraft?.();
    if (!d) return;
    const text = draftText(d), key = jd.length + ':' + text.length + ':' + text.slice(-80) + jd.slice(-40);
    if (!force && key === lastKey) return;
    lastKey = key;
    const chips = panel.querySelector('#tpChips'), todo = panel.querySelector('#tpTodo');
    if (jd.length < 40) { panel.querySelector('#tpScore').textContent = '–'; panel.querySelector('#tpBar').style.width = '0'; chips.innerHTML = ''; todo.innerHTML = '<li>Paste a job description above to see which requirements your resume covers.</li>'; return; }
    try { await ensureEngine(); } catch { return; }
    if (text.trim().length < 30) { todo.innerHTML = '<li>Choose a template and add your details. Your match appears here.</li>'; return; }
    const r = CVTaraMatch.analyse(text, jd), c = r.counts || {}, total = (c.evidenced || 0) + (c.mentioned || 0) + (c.not_found || 0);
    const pct = total ? Math.round(100 * ((c.evidenced || 0) + 0.5 * (c.mentioned || 0)) / total) : null;
    panel.querySelector('#tpScore').textContent = pct == null ? '–' : pct + '%';
    panel.querySelector('#tpBar').style.width = (pct || 0) + '%';
    chips.innerHTML = r.requirements.filter(x => x.status !== 'review').map(x => `<span class="fn-chip ${x.status === 'evidenced' ? 'ok' : x.status === 'mentioned' ? 'part' : 'miss'}" title="${E(x.status === 'evidenced' ? 'Shown with an example' : x.status === 'mentioned' ? 'Mentioned only' : 'Not found')}">${E(x.name)}</span>`).join('');
    const next = (r.actions || []).slice(0, 3);
    todo.innerHTML = next.length ? next.map(a => `<li><b>${E(a.name)}.</b> ${E(a.advice || '')}</li>`).join('') : '<li>Every recognised requirement is covered. Re-read the posting for anything else it asks for.</li>';
  }

  let tracked = false, timer;
  function persistJob() {
    job.jd = jdBox.value; job.url = urlBox.value.trim(); job.at = Date.now();
    try { localStorage.setItem(KEY, JSON.stringify(job)); } catch { /* ignore */ }
  }
  jdBox.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      persistJob();
      if (!tracked && jdBox.value.trim().length >= 40) { tracked = true; track('tailor_used'); }
      refresh(true);
    }, 600);
  });
  urlBox.addEventListener('change', persistJob);
  urlBox.addEventListener('change', async () => {
    const url = urlBox.value.trim();
    if (!/^https?:\/\//i.test(url) || !window.CVTaraAPI) return;
    try {
      const d = await api('/api/jobs/from-url', { method: 'POST', body: { url } });
      if (d.text && jdBox.value.trim().length < 40) {
        jdBox.value = d.text;
        persistJob();
        refresh(true);
      }
    } catch { /* paste remains available */ }
  });
  setInterval(() => { if (!document.hidden && panel.offsetParent !== null) refresh(false); }, 1500);

  // Packages are read from the owner console settings.
  (async () => {
    try {
      const signed = (document.querySelector('meta[name=cvtara-user]')?.content || 'guest') !== 'guest';
      const d = signed ? await api('/api/checkout/catalog') : await api('/api/business-public'), box = panel.querySelector('#tpPlans');
      const items = Object.entries(d.plans || {}).filter(([k, x]) => k !== 'free' && !x.addon);
      if (!items.length) return;
      const up = document.querySelector('a[href*="checkout"][href*="plan="]');
      if (up) { up.href = '/checkout?plan=d60'; up.textContent = 'Open checkout'; }
      box.insertAdjacentHTML('beforeend', items.map(([k, x]) => `<a class="button secondary small" data-plan="${E(k)}" href="/checkout?plan=${encodeURIComponent(k)}">${E(x.name)}</a>`).join(''));
      box.hidden = false;
      box.addEventListener('click', e => { const a = e.target.closest('[data-plan]'); if (a) track('plan_click', a.dataset.plan.replace(/[^a-z0-9_-]/gi, '').slice(0, 40)); });
    } catch { /* packages are also available from Account */ }
  })();
  panel.querySelector('#tpLetter')?.addEventListener('click', () => { location.href = '/workspace?view=letter&from=check'; });
  document.addEventListener('cvtara-resume-saved', e => {
    persistJob();
    const d = e.detail || {};
    if ((job.jd || '').trim().length < 30 && !(job.url || '').trim()) return;
    if (d.jobText || d.jobUrl) return;
    window.CVTaraTracker?.capture({
      jobText: job.jd || '',
      jobUrl: job.url || '',
      resumeId: d.resumeId || '',
      role: window.CVTaraStudio?.getDraft?.()?.personal?.role || '',
      company: window.CVTaraStudio?.getDraft?.()?.experience?.[0]?.company || ''
    });
  });
  refresh(true);
})();
