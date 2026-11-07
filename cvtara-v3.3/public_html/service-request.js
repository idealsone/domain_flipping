/* CVTara customer dashboard (3.41.0): "What would you like CVTara to help you with?", the career-goal question for new
   accounts, the resume + cover letter service with its real progress tracker, and the Downloads list in Account. Every
   state shown comes from the server; nothing here is simulated. */
(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace') return;
  const $ = (s, r = document) => r.querySelector(s);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const when = s => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z')); return isNaN(d) ? '' : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  async function api(path, body) {
    const o = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-TOKEN'] = await window.CVTaraCSRF(); o.body = JSON.stringify(body); }
    const r = await fetch('/api/' + path, o);
    let j = {}; try { j = await r.json(); } catch { /* not JSON */ }
    if (!r.ok || j.ok === false) throw Error(j.error || (j.errors && Object.values(j.errors)[0]?.[0]) || j.message || 'That did not work. Please try again.');
    return j;
  }
  const go = view => { const b = document.querySelector(`.side-nav [data-workspace-view="${view}"]`); if (b) b.click(); else location.href = '/workspace.html?view=' + view; };

  const css = document.createElement('style');
  css.textContent = `
  .sr-pick{margin:0 0 20px}.sr-pick h3{margin:0 0 12px;font-size:17px}
  .sr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px}
  .sr-tile{display:flex;flex-direction:column;gap:4px;text-align:left;padding:14px 16px;border:1px solid var(--line,#e2e8f0);border-radius:12px;background:var(--panel,#fff);color:inherit;cursor:pointer;font:inherit}
  .sr-tile:hover,.sr-tile:focus-visible{border-color:var(--blue,#2563eb);outline:none;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
  .sr-tile b{font-size:14px}.sr-tile small{color:var(--muted,#64748b);font-size:12.5px;line-height:1.4}
  .sr-tile.sr-main{border-color:var(--blue,#2563eb);background:rgba(37,99,235,.06)}
  .sr-card{border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:16px 18px;margin:0 0 16px;background:var(--panel,#fff)}
  .sr-card h3{margin:0 0 6px;font-size:16px}.sr-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .sr-list{display:grid;gap:6px;margin:8px 0 0;padding:0;list-style:none}.sr-list li{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
  .sr-dlg{border:0;border-radius:14px;padding:0;width:min(760px,calc(100vw - 24px));max-height:calc(100vh - 32px);color:inherit;background:var(--panel,#fff)}
  .sr-dlg::backdrop{background:rgba(15,23,42,.55)}.sr-in{padding:20px 22px;display:grid;gap:12px}
  .sr-in label{display:grid;gap:4px;font-weight:600;font-size:14px}.sr-in select,.sr-in textarea,.sr-in input{font:inherit;padding:8px 10px;border:1px solid var(--line,#cbd5e1);border-radius:8px;background:transparent;color:inherit}
  .sr-steps{list-style:none;margin:0;padding:0;display:grid;gap:4px}.sr-steps li{display:flex;gap:10px;align-items:center;font-size:14px;color:var(--muted,#64748b)}
  .sr-steps li i{width:20px;height:20px;border-radius:50%;border:2px solid currentColor;display:inline-grid;place-items:center;font-style:normal;font-size:12px;flex:none}
  .sr-steps li.done{color:inherit}.sr-steps li.done i{background:#16a34a;border-color:#16a34a;color:#fff}.sr-steps li.now i{border-color:var(--blue,#2563eb);color:var(--blue,#2563eb)}
  .sr-pre{white-space:pre-wrap;border:1px solid var(--line,#e2e8f0);border-radius:10px;padding:10px 12px;font-size:14px;max-height:260px;overflow:auto}
  .sr-bad{color:#b91c1c}.sr-ok{color:#15803d}.sr-x{position:absolute;right:10px;top:8px;border:0;background:transparent;font-size:22px;cursor:pointer;color:inherit}`;
  document.head.appendChild(css);

  let profile = null, chat = false;

  // ---- dashboard ----
  const TILES = [
    ['create', 'Create a resume', 'Start from a template or from your profile.'],
    ['improve', 'Improve my resume', 'Upload the resume you have; we fill the builder for you.'],
    ['letter', 'Create a cover letter', 'A complete letter from your resume and a job.'],
    ['both', 'Resume + cover letter for a job', 'Both tailored for one job, checked to match each other.'],
    ['ats', 'Check ATS compatibility', 'See your match for a job and what to fix.'],
    ['assist', 'Get career assistance', 'Ask Tara about your resume, interviews or your next step.'],
    ['track', 'Track my applications', 'Keep every job you applied to and when to follow up.'],
  ];
  function pick(k) {
    if (k === 'create') go('builder');
    else if (k === 'improve') { if (window.CVTaraResumeImport?.pick) window.CVTaraResumeImport.pick(); else go('resumes'); }
    else if (k === 'letter') go('letter');
    else if (k === 'both') openService();
    else if (k === 'ats') go('match');
    else if (k === 'assist') { if (window.CVTaraAssist) window.CVTaraAssist(); else go('match'); }
    else if (k === 'track') go('tracker');
  }
  const GOALS = [['first_job', 'Get my first job'], ['new_job', 'Find a new job'], ['switch_career', 'Change my career'], ['promotion', 'Grow in my current field'], ['abroad', 'Work abroad'], ['exploring', 'Just exploring']];

  async function dashboard() {
    const host = $('#view-dashboard');
    if (!host || $('#srDash')) return;
    const box = document.createElement('div'); box.id = 'srDash';
    const after = $('#wsDash'); if (after) after.after(box); else host.prepend(box);
    const goal = profile && profile.profile && !profile.profile.career_goal ? `<div class="sr-card" id="srGoal"><h3>What brings you to CVTara?</h3><p style="margin:0 0 10px;color:var(--muted)">One question, so your dashboard shows the right next step. You can change it later.</p>
      <div class="sr-row">${GOALS.map(([k, l]) => `<button type="button" class="button secondary small" data-goal="${k}">${E(l)}</button>`).join('')}</div>
      <div class="sr-row" style="margin-top:10px"><input name="role" maxlength="120" placeholder="The role you want (optional), e.g. Data Analyst" style="flex:1;min-width:220px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:transparent;color:inherit"></div></div>` : '';
    box.innerHTML = goal + `<section class="sr-pick" aria-labelledby="srPickH"><h3 id="srPickH">What would you like CVTara to help you with?</h3><div class="sr-grid">${TILES.filter(t => t[0] !== 'assist' || chat).map(([k, t, s]) => `<button type="button" class="sr-tile${k === 'both' ? ' sr-main' : ''}" data-pick="${k}"><b>${E(t)}</b><small>${E(s)}</small></button>`).join('')}</div></section><div id="srMine"></div>`;
    box.addEventListener('click', async e => {
      const p = e.target.closest('[data-pick]'); if (p) { pick(p.dataset.pick); return; }
      const g = e.target.closest('[data-goal]');
      if (g) {
        const role = $('#srGoal input[name=role]')?.value.trim() || '';
        g.disabled = true;
        try { await api('profile/goal', { goal: g.dataset.goal, role }); $('#srGoal').innerHTML = '<p class="sr-ok" style="margin:0">Thanks, noted. Your dashboard now starts from this.</p>'; setTimeout(() => $('#srGoal')?.remove(), 2500); } catch (err) { g.disabled = false; alert(err.message); }
        return;
      }
      const o = e.target.closest('[data-open-request]'); if (o) openService(o.dataset.openRequest);
    });
    mineList();
  }
  async function mineList() {
    const host = $('#srMine'); if (!host) return;
    let j; try { j = await api('service-requests'); } catch { return; }
    if (!j.requests.length) { host.innerHTML = ''; return; }
    host.innerHTML = `<div class="sr-card"><h3>Your resume + cover letter requests</h3><ul class="sr-list">${j.requests.map(r => `<li><span><b>${E(r.role || 'Job')}</b>${r.company ? ' · ' + E(r.company) : ''} <small style="color:var(--muted)">${E(when(r.createdAt))}</small></span><span>${E(r.label)} <button type="button" class="button secondary small" data-open-request="${E(r.id)}">Open</button></span></li>`).join('')}</ul></div>`;
  }

  // ---- the service ----
  let dlg = null, cur = null, timer = null;
  function dialog() {
    if (dlg) return dlg;
    dlg = document.createElement('dialog'); dlg.className = 'sr-dlg'; dlg.setAttribute('aria-label', 'Resume and cover letter for a job');
    document.body.appendChild(dlg);
    dlg.addEventListener('close', () => { clearTimeout(timer); cur = null; mineList(); });
    dlg.addEventListener('click', e => { if (e.target === dlg || e.target.closest('[data-sr-close]')) dlg.close(); });
    return dlg;
  }
  const head = t => `<div style="position:relative"><button type="button" class="sr-x" data-sr-close aria-label="Close">×</button><h2 style="margin:0;font-size:19px">${E(t)}</h2></div>`;

  async function openService(id) {
    const d = dialog(); if (!d.open) d.showModal();
    if (id) { cur = id; d.innerHTML = `<div class="sr-in">${head('Resume + cover letter')}<p>Loading…</p></div>`; return load(); }
    d.innerHTML = `<div class="sr-in">${head('Resume + cover letter for one job')}<p>Loading your resumes…</p></div>`;
    let rs, sc;
    try { [rs, sc] = await Promise.all([api('resumes'), api('ats/scans').catch(() => ({ scans: [] }))]); } catch (e) { d.querySelector('.sr-in').innerHTML = head('Resume + cover letter') + `<p class="sr-bad">${E(e.message)}</p>`; return; }
    if (!rs.resumes.length) {
      d.querySelector('.sr-in').innerHTML = head('First, your resume') + '<p>We tailor your own resume, so add it first: upload the one you have, or build it from a template.</p><div class="sr-row"><button type="button" class="button primary" data-first="improve">Upload my resume</button><button type="button" class="button secondary" data-first="create">Start from a template</button></div>';
      d.querySelectorAll('[data-first]').forEach(b => b.onclick = () => { d.close(); pick(b.dataset.first); });
      return;
    }
    d.querySelector('.sr-in').innerHTML = head('Resume + cover letter for one job') + `
      <p style="margin:0;color:var(--muted)">We tailor your resume to the job and write the cover letter, check that both tell the same story, check formatting and the ATS match, and show you a preview. You see the price before you pay anything.</p>
      <form class="sr-in" style="padding:0" id="srForm">
        <label>Your resume<select name="resumeId" required>${rs.resumes.map(r => `<option value="${E(r.id)}">${E(r.title || 'Untitled resume')}${r.isPrimary || r.is_primary ? ' (main)' : ''}</option>`).join('')}</select></label>
        ${sc.scans.length ? `<label>The job<select name="scanId"><option value="">Paste a new job below</option>${sc.scans.slice(0, 15).map(s => `<option value="${E(s.id)}">${E(s.title || 'Job')}${s.company ? ' · ' + E(s.company) : ''} (checked ${E(when(s.createdAt))})</option>`).join('')}</select></label>` : ''}
        <div id="srNewJob" style="display:grid;gap:10px"><div class="sr-row"><label style="flex:1;min-width:180px">Job title<input name="role" maxlength="160" placeholder="e.g. Business Analyst"></label><label style="flex:1;min-width:180px">Company<input name="company" maxlength="160" placeholder="e.g. Infosys"></label></div>
        <label>Job description<textarea name="jobText" rows="6" maxlength="40000" placeholder="Paste the full job description: responsibilities and requirements."></textarea></label><small style="color:var(--muted)">A new job uses one ATS scan from your allowance, as a normal ATS check does.</small></div>
        <p class="sr-bad" role="alert" id="srErr"></p>
        <div class="sr-row"><button class="button primary" type="submit">Start</button><button class="button secondary" type="button" data-sr-close>Cancel</button></div>
      </form>`;
    const f = $('#srForm', d), nj = $('#srNewJob', d);
    const sync = () => { nj.style.display = f.scanId && f.scanId.value ? 'none' : 'grid'; };
    if (f.scanId) { f.scanId.onchange = sync; f.scanId.value = sc.scans[0]?.id || ''; sync(); }
    f.onsubmit = async e => {
      e.preventDefault();
      const v = Object.fromEntries(new FormData(f)); const err = $('#srErr', d);
      if (!v.scanId) { delete v.scanId; if ((v.jobText || '').trim().length < 80) { err.textContent = 'Paste the full job description (at least a few lines).'; return; } } else { delete v.jobText; delete v.role; delete v.company; }
      const b = f.querySelector('[type=submit]'); b.disabled = true; b.textContent = 'Starting…'; err.textContent = '';
      try { const j = await api('service-requests', v); cur = j.request.id; draw(j.request); step(); } catch (x) { err.textContent = x.message; b.disabled = false; b.textContent = 'Start'; }
    };
  }
  async function load() { try { const j = await api('service-requests/' + cur); draw(j.request); if (j.request.working) step(); } catch (e) { if (dlg?.open) dlg.querySelector('.sr-in').innerHTML = head('Resume + cover letter') + `<p class="sr-bad">${E(e.message)}</p>`; } }
  async function step() {
    clearTimeout(timer);
    if (!cur || !dlg?.open) return;
    try { const j = await api('service-requests/' + cur + '/run', {}); draw(j.request); if (j.request.working) timer = setTimeout(step, 1200); }
    catch (e) { timer = setTimeout(load, 4000); }
  }
  function steps(r) {
    const idx = r.steps.findIndex(s => s.key === r.status);
    return `<ol class="sr-steps">${r.steps.map((s, i) => { const done = !!s.at; const now = !done && r.working && i === idx + 1; return `<li class="${done ? 'done' : now ? 'now' : ''}"><i aria-hidden="true">${done ? '✓' : now ? '…' : ''}</i><span>${E(s.label)}${done ? ` <small>${E(when(s.at))}</small>` : now ? ' <small>in progress</small>' : ''}</span></li>`; }).join('')}</ol>`;
  }
  function draw(r) {
    if (!dlg?.open || cur !== r.id) return;
    const job = `${r.job.role || 'Job'}${r.job.company ? ' · ' + r.job.company : ''}`;
    let body = steps(r);
    if (r.status === 'failed') body += `<p class="sr-bad">${E(r.error || 'This could not be finished.')}</p><div class="sr-row"><button type="button" class="button primary" data-sr="retry">Try again</button><a class="button secondary" href="/workspace.html?support=new">Contact support</a></div>`;
    else if (r.working) body += '<p style="color:var(--muted);margin:0">This takes a minute or two. You can close this window; we finish it and it stays on your dashboard.</p>';
    const p = r.preview;
    if (p) {
      const t = p.resume || {};
      body += `<div class="sr-card" style="margin:0"><h3>Preview</h3>
        <p style="margin:0 0 8px">ATS match for this job: ${r.job.scoreBefore != null ? `<b>${E(r.job.scoreBefore)}%</b> before → ` : ''}<b>${E(p.scoreAfter ?? '–')}%</b> with the tailored resume${p.quality ? ` · formatting score <b>${E(p.quality.score)}/100</b>` : ''}</p>
        <ul class="sr-list">${(p.checks || []).map(c => `<li><span class="${c.ok ? 'sr-ok' : 'sr-bad'}">${c.ok ? '✓' : '!'} ${E(c.text)}</span></li>`).join('')}</ul>
        ${(t.check || []).length ? `<p style="margin:10px 0 4px"><b>Please check before you send</b></p><ul style="margin:0;padding-left:18px">${t.check.map(x => `<li>${E(x)}</li>`).join('')}</ul>` : ''}
        <details style="margin-top:10px" open><summary><b>Tailored resume: summary and skills</b></summary><div class="sr-pre">${E(t.summary || '')}\n\nSkills: ${E(t.skills || '')}</div></details>
        ${(t.experience || []).length ? `<details style="margin-top:6px"><summary><b>Tailored experience bullets</b></summary><div class="sr-pre">${t.experience.map(x => E(x.bullets)).join('\n\n')}</div></details>` : ''}
        ${p.letter ? `<details style="margin-top:6px" open><summary><b>Cover letter</b></summary><div class="sr-pre">${E([p.letter.salutation, p.letter.opening, p.letter.evidence, p.letter.motivation, p.letter.closing, p.letter.signoff].filter(Boolean).join('\n\n'))}</div></details>` : ''}
        ${(p.missing || []).length ? `<p style="margin:8px 0 0;color:var(--muted)">Job keywords still not in your resume (add them only if true): ${E(p.missing.join(', '))}</p>` : ''}</div>`;
    }
    if (r.status === 'payment_pending' && r.order) {
      body += `<div class="sr-card" style="margin:0"><h3>Order summary</h3><p style="margin:0 0 6px">You get:</p><ul style="margin:0 0 8px;padding-left:18px">${r.order.deliverables.map(x => `<li>${E(x)}</li>`).join('')}</ul>
        ${r.order.plans.length ? `<div class="sr-grid">${r.order.plans.map(pl => `<a class="sr-tile" href="${E(pl.url)}"><b>${E(pl.name)} · ₹${E(pl.price)}</b><small>${E(pl.days)} days · ${E(pl.resumes)} resumes · ${E(pl.letters)} cover letters</small></a>`).join('')}</div>` : '<p>Plans are not on sale right now. Please contact support.</p>'}
        <p style="margin:8px 0 0;color:var(--muted)">${E(r.order.note)} After payment, come back here and save both to your account.</p></div>`;
    }
    if (r.status === 'payment_confirmed') body += `<div class="sr-card" style="margin:0"><h3>Save both to your account</h3><p style="margin:0 0 8px">We add the tailored resume as a new version for this job and save the cover letter. Downloads then use your plan's credits as usual.</p><div class="sr-row"><button type="button" class="button primary" data-sr="save">Save both</button><button type="button" class="button secondary" data-sr="save-update">Put the tailored text into my resume instead</button></div><p class="sr-bad" role="alert" id="srSaveErr"></p></div>`;
    if (r.status === 'download_available') body += `<div class="sr-card" style="margin:0"><h3>Ready to download</h3><div class="sr-row">${r.resumeId ? `<a class="button primary" href="/workspace.html?view=builder&draft=${encodeURIComponent(r.resumeId)}">Open the resume</a>` : ''}${r.letterId ? `<a class="button secondary" href="/workspace.html?view=letter&letter=${encodeURIComponent(r.letterId)}">Open the cover letter</a>` : ''}</div></div>`;
    dlg.innerHTML = `<div class="sr-in">${head('Resume + cover letter')}<p style="margin:0"><b>${E(job)}</b> · ${E(r.label)}</p>${body}</div>`;
    dlg.querySelectorAll('[data-sr]').forEach(b => b.onclick = async () => {
      b.disabled = true;
      try {
        const a = b.dataset.sr;
        const j = a === 'retry' ? await api(`service-requests/${cur}/retry`, {}) : await api(`service-requests/${cur}/save`, { mode: a === 'save-update' ? 'update' : 'version' });
        draw(j.request); if (j.request.working) step();
      } catch (e) { const x = $('#srSaveErr', dlg); if (x) x.textContent = e.message; else alert(e.message); b.disabled = false; }
    });
  }

  // ---- downloads (Account) ----
  async function downloads() {
    const acct = $('#view-account');
    if (!acct || $('#srDownloads')) return;
    const s = document.createElement('section'); s.id = 'srDownloads'; s.className = 'panel panel-pad'; s.style.marginTop = '18px';
    s.innerHTML = '<span class="eyebrow">DOWNLOADS</span><h3>Your downloads</h3><p style="color:var(--muted)">Loading…</p>';
    const priv = $('#privacyTools'); if (priv) priv.before(s); else acct.append(s);
    try {
      const j = await api('downloads');
      s.querySelector('p').outerHTML = j.downloads.length ? `<ul class="sr-list">${j.downloads.map(x => `<li><span>${x.kind === 'resume' ? 'Resume' : 'Cover letter'}: <b>${E(x.title)}</b> <small style="color:var(--muted)">version ${E(x.version)}</small></span><span><small>${E(when(x.at))}</small> ${x.exists ? `<a href="/workspace.html?view=${x.kind === 'resume' ? 'builder&draft=' : 'letter&letter='}${encodeURIComponent(x.id)}" style="color:var(--blue);font-weight:700">Open</a>` : ''}</span></li>`).join('')}</ul>` : '<p style="color:var(--muted)">No clean downloads yet. Each clean PDF or Word download you make shows here.</p>';
    } catch (e) { s.querySelector('p').textContent = e.message; }
  }

  async function boot() {
    try { profile = await api('profile'); } catch { profile = null; }
    try { chat = !!(await api('tara/status')).tara?.enabled; } catch { chat = false; }
    const a = $('.side-nav [data-assist]');
    if (a && chat) { a.style.display = ''; a.onclick = () => window.CVTaraAssist ? window.CVTaraAssist() : go('match'); }
    dashboard(); downloads();
    new MutationObserver(() => { if ($('#view-dashboard') && !$('#srDash')) dashboard(); if ($('#view-account') && !$('#srDownloads')) downloads(); }).observe($('.workspace-content') || document.body, { childList: true, subtree: true });
    const q = new URLSearchParams(location.search).get('service');
    if (q === 'new') openService(); else if (/^[a-f0-9-]{36}$/.test(q || '')) openService(q);
  }
  addEventListener('load', () => setTimeout(boot, 600));
})();
