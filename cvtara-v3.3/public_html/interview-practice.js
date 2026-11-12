/* CVTara Interview practice (3.49.0). Question practice shows stored questions with what a good answer covers; a mock
   interview locks a set of questions, the customer answers in writing, submits, and reads the written feedback here.
   Every state comes from the server (/api/interviews…). Customer wording never mentions AI. */
(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace') return;
  const host = () => document.getElementById('view-interview');
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const when = s => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z')); return isNaN(d) ? '' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); };
  async function api(path, body) {
    const o = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-TOKEN'] = await window.CVTaraCSRF(); o.body = JSON.stringify(body); }
    const r = await fetch('/api/' + path, o);
    let j = {}; try { j = await r.json(); } catch { /* not JSON */ }
    if (!r.ok || j.ok === false) throw Error(j.error || (j.errors && Object.values(j.errors)[0]?.[0]) || j.message || 'That did not work. Please try again.');
    return j;
  }

  const css = document.createElement('style');
  css.textContent = `
.ip-badges .ip-badge small{display:none}.ip-badges .ip-badge{min-width:0}
  .ip-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0 8px}.ip-tiles div{display:grid;justify-items:center;gap:2px;padding:12px 6px;border-radius:14px;background:var(--soft,#f1f5f9);text-align:center}.ip-tiles span{font-size:20px}.ip-tiles b{font-size:22px}.ip-tiles small{font-size:12px;color:var(--muted,#64748b)}
  @media (max-width:600px){.ip-tiles{grid-template-columns:repeat(2,1fr)}}
  .ip-room{margin:12px 0;border-radius:18px;overflow:hidden;border:1px solid var(--line,#e2e8f0);background:linear-gradient(180deg,#0f2e2b 0,#0f2e2b 92px,var(--panel,#fff) 92px)}
  .ip-panel{display:flex;align-items:center;gap:12px;padding:16px 18px;color:#e6fffa}.ip-panel small{display:block;color:#99f6e4;font-size:12.5px}
  .ip-avatar{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;font-size:28px;background:#134e4a;box-shadow:0 0 0 3px #5eead4}
  .ip-live{margin-left:auto;font-size:12px;font-weight:700;color:#fda4af;animation:ipPulse 1.6s ease-in-out infinite}@keyframes ipPulse{50%{opacity:.45}}
  .ip-room > .ip-q,.ip-room .ip-q{display:none;padding:22px 20px 6px}.ip-room .ip-q.on{display:block}
  .ip-room .ip-q > b{display:block;font-size:19px;line-height:1.4;margin-bottom:12px}.ip-room .ip-q textarea{min-height:170px;width:100%}
  .ip-room > p,.ip-room > .ip-steps,.ip-room > .ip-row{padding:0 20px}
  .ip-nav{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 20px 18px}
  .ip-dots{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}.ip-dots button{width:11px;height:11px;border-radius:50%;border:0;padding:0;background:var(--line,#cbd5e1);cursor:pointer}
  .ip-dots button.done{background:#5eead4}.ip-dots button.on{background:#0f766e;transform:scale(1.35)}
  @media (prefers-reduced-motion:reduce){.ip-live{animation:none}}
  .ip-card{border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:16px 18px;margin:0 0 16px;background:var(--panel,#fff)}
  .ip-card h3{margin:0 0 6px;font-size:17px}.ip-muted{color:var(--muted,#64748b);font-size:13.5px;line-height:1.5}
  .ip-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0}
  .ip-form label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600}
  .ip-form select,.ip-form input,.ip-q textarea,.ip-card textarea{font:inherit;padding:9px 10px;border:1px solid var(--line,#cbd5e1);border-radius:8px;background:var(--panel,#fff);color:inherit}
  .ip-q{border-top:1px solid var(--line,#e2e8f0);padding:14px 0}.ip-q:first-of-type{border-top:0}
  .ip-q b{display:block;margin-bottom:6px}.ip-q textarea{width:100%;min-height:120px;box-sizing:border-box}
  .ip-guide{font-size:13px;color:var(--muted,#64748b);margin:6px 0 0}
  .ip-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .ip-pill{display:inline-block;padding:3px 10px;border-radius:999px;background:rgba(37,99,235,.08);font-size:12.5px;font-weight:700}
  .ip-scores{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:6px;margin:8px 0}
  .ip-scores span{font-size:12.5px;padding:6px 8px;border-radius:8px;background:rgba(15,23,42,.04)}
  .ip-fb ul{margin:4px 0 8px 18px;padding:0}.ip-fb li{margin:2px 0}
  .ip-rewrite{white-space:pre-wrap;background:rgba(16,185,129,.06);border-radius:8px;padding:10px;font-size:13.5px}
  .ip-err{color:#b91c1c;font-size:13.5px;margin:8px 0}
  .ip-steps{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0}.ip-step{padding:4px 10px;border-radius:999px;border:1px solid var(--line,#e2e8f0);font-size:12.5px;color:var(--muted,#64748b)}
  .ip-step.on{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.5);color:inherit;font-weight:700}
  .ip-badges{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;margin-top:12px}
  .ip-badge{display:flex;flex-direction:column;gap:2px;padding:8px 10px;border-radius:10px;border:1px dashed var(--line,#cbd5e1);opacity:.7}
  .ip-badge.on{border-style:solid;border-color:rgba(234,179,8,.6);background:rgba(234,179,8,.08);opacity:1}
  .ip-badge small{color:var(--muted,#64748b);font-size:12px}`;
  document.head.appendChild(css);

  let state = null;
  const RESEARCH_LABEL = 'These are CVTara practice questions based on publicly available company information; they are not confirmed questions from the employer.';
  // 3.57.0 optional answer timer: time on each answer is shown and saved with it; nothing is penalised.
  const clock = { on: false, start: {}, spent: {}, tick: null };
  const mmss = n => Math.floor(n / 60) + ':' + String(n % 60).padStart(2, '0');
  const secs = pos => Math.round(((clock.spent[pos] || 0) + (clock.start[pos] ? Date.now() - clock.start[pos] : 0)) / 1000);
  const LEVEL_NOTE = 'Levels show how your practice answers read against the rubric. They are a learning guide, not a prediction of any interview result.';

  function roleOptions(sel) {
    const fam = {};
    state.roles.forEach(r => { (fam[r.family] = fam[r.family] || []).push(r); });
    return Object.keys(fam).map(f => `<optgroup label="${E(f)}">${fam[f].map(r => `<option value="${E(r.slug)}"${r.slug === sel ? ' selected' : ''}>${E(r.name)}</option>`).join('')}</optgroup>`).join('');
  }
  const opts = (o, sel) => Object.entries(o).map(([k, v]) => `<option value="${E(k)}"${k === sel ? ' selected' : ''}>${E(v)}</option>`).join('');

  async function load() {
    const h = host(); if (!h) return;
    try { state = await api('interviews'); } catch (e) { h.innerHTML = `<p class="ip-err">${E(e.message)}</p>`; return; }
    render();
  }

  function render(msg) {
    const h = host(); const s = state;
    const open = s.open;
    let out = `<div class="ip-card"><h3>Interview practice</h3><p class="ip-muted">Practise common interview questions for your role, then try a mock interview: answer in writing and get feedback on what went well and what to improve. Written feedback is prepared automatically against a fixed checklist; it does not predict any interview result.</p>
      <div class="ip-row" style="margin-top:10px"><span class="ip-pill">Mock interviews left: ${s.left}</span>${s.progress.completed ? `<span class="ip-pill">Completed: ${s.progress.completed}</span>` : ''}</div></div>${progressHtml(s.progress)}`;
    if (msg) out += `<p class="ip-err">${E(msg)}</p>`;
    if (open) out += sessionHtml(open);
    else {
      const can = s.enabled && s.left > 0;
      out += `<div class="ip-card"><h3>Start a mock interview</h3>
        <div class="ip-form"><label>Find your role<input data-ip-find maxlength="60" placeholder="Type your job title, e.g. telecaller" autocomplete="off"></label><div data-ip-found class="ip-row" style="grid-column:1/-1"></div>
        <label>Role<select data-ip="occupation">${roleOptions(state.pick || new URLSearchParams(location.search).get('role') || '')}</select></label>
        <label>Your experience<select data-ip="band">${opts(s.bands, 'fresher')}</select></label>
        <label>Your goal<select data-ip="objective">${opts(s.objectives, 'first_job')}</select></label>
        <label>Company (optional)<input data-ip="company" maxlength="120" placeholder="e.g. a bank or retail chain"></label>
        ${s.fullMock ? `<label>Type<select data-ip="mode"><option value="standard">Standard mock interview (about 15 minutes)</option><option value="full">Full mock interview (about 60 minutes, 4 parts)</option></select></label>` : ''}</div>
        ${s.fullMock ? '<p class="ip-muted">A full mock interview has four parts of about 15 minutes each, like a real interview round: about you, the role, your past experience, and situations. Each uses one mock interview credit, the same as a standard one.</p>' : ''}
        ${can ? '<button class="button mint" data-ip-start>Start mock interview</button>' : (!s.enabled ? '<p class="ip-muted">Mock interviews will open soon. You can practise questions below in the meantime.</p>' : '<p class="ip-muted">Job Hunt includes 4 mock interviews and Pro includes 12, for the whole plan.</p><button class="button secondary" data-workspace-view="billing">See plans</button>')}
        <button class="button secondary" data-ip-browse>Practise questions</button>${s.progress.completed ? ' <button class="button secondary" data-ip-revise>Revision: practise your past questions</button>' : ''}</div><div data-ip-bank></div>
        <div class="ip-card"><h3>Research a company</h3><p class="ip-muted">Paste the company's own website (for example its About page). We read that one public page, show a short summary with its source, and give you practice questions about the company. ${E(RESEARCH_LABEL)}</p>
        <div class="ip-form"><label>Company name<input data-ip-rc="company" maxlength="160" placeholder="e.g. Acme Retail"></label><label>Company website<input data-ip-rc="url" maxlength="500" placeholder="https://"></label></div>
        <button class="button secondary" data-ip-research>Research this company</button><div data-ip-research-out></div></div>`;
    }
    if (s.past.length) {
      out += `<div class="ip-card"><h3>Your mock interviews</h3>${s.past.map(p => `<div class="ip-row" style="padding:6px 0"><span>${E(p.role || p.occupation)}${p.mode === 'full' ? ' · Full mock' : ''} · ${E(when(p.created_at))}</span><span class="ip-pill">${E(label(p.status, p.level))}</span>${p.status === 'evaluated' ? `<button class="button secondary small" data-ip-open="${E(p.id)}">Read feedback</button>` : ''}</div>`).join('')}</div>`;
    }
    h.innerHTML = out;
  }
  function progressHtml(p) {
    const lv = p.level ? p.levels.indexOf(p.level) : -1;
    const steps = p.levels.map((l, i) => `<span class="ip-step${i <= lv ? ' on' : ''}">${E(l)}</span>`).join('');
    const trend = p.level && p.previousLevel && p.level !== p.previousLevel ? (p.levels.indexOf(p.level) > p.levels.indexOf(p.previousLevel) ? ' Up from your last mock interview.' : ' A little lower than last time, which is normal with harder questions.') : '';
    return `<div class="ip-card"><h3>Your progress</h3>
      <div class="ip-steps">${steps}</div><p class="ip-muted">${p.level ? `Latest level: <b>${E(p.level)}</b>.${trend}` : 'Finish a mock interview to see your level.'} ${E(LEVEL_NOTE)}</p>
      <div class="ip-tiles"><div><span aria-hidden="true">🔥</span><b>${p.streak}</b><small>day streak${p.bestStreak > p.streak ? ` · best ${p.bestStreak}` : ''}</small></div><div><span aria-hidden="true">⏱</span><b>${(p.minutes || {}).today || 0}</b><small>minutes today</small></div><div><span aria-hidden="true">📅</span><b>${(p.minutes || {}).week || 0}</b><small>minutes this week</small></div><div><span aria-hidden="true">🎤</span><b>${p.completed}</b><small>mock interviews done</small></div></div>
      <p class="ip-muted" style="margin:0" title="A practice day is any day you open practice questions or finish a mock interview. You can rest ${p.restDays} day between practice days without losing your streak.">${p.practisedToday ? '✓ You have practised today.' : 'Practise today to keep your streak.'} Minutes count when the answer timer is on.</p>
      <div class="ip-badges">${p.badges.map(b => `<span class="ip-badge${b.earned ? ' on' : ''}" title="${E(b.about)}"><b>${b.earned ? '★' : '☆'} ${E(b.name)}</b><small>${E(b.about)}</small></span>`).join('')}</div>
      <p class="ip-muted" style="margin:8px 0 0">Your progress is private to you. There are no rankings.</p></div>`;
  }
  const label = (st, lvl) => ({ in_progress: 'In progress', submitted: 'Preparing feedback', evaluating: 'Preparing feedback', evaluated: lvl || 'Feedback ready', failed: 'Credit returned', abandoned: 'Not finished, credit returned' }[st] || st);

  // 3.67.0 Interview room: an interviewer panel, one question at a time, progress dots and Previous / Next, so a mock
  // interview looks and feels like the real thing instead of a long form. All answers stay in the page and save as before.
  const room = (x, inner) => `<div class="ip-room"><div class="ip-panel"><div class="ip-avatar" aria-hidden="true">🧑‍💼</div><div><b>Interview panel</b><small>${E(x.role)} · ${E(x.bandLabel)}</small></div><span class="ip-live">● Interview in progress</span></div>${inner}<div class="ip-nav"><button type="button" class="button secondary" data-ip-go="-1">← Previous</button><span class="ip-dots" aria-label="Questions"></span><button type="button" class="button mint" data-ip-go="1">Next question →</button></div></div>`;
  function sessionHtml(x) {
    let out = `<div class="ip-card" data-ip-session="${E(x.id)}"><h3>${E(x.role)} · ${E(x.bandLabel)}</h3>`;
    if (x.status === 'in_progress' && x.mode === 'full' && x.sections && x.sections.length) {
      out += room(x, fullHtml(x));
    } else if (x.status === 'in_progress') {
      out += `<p class="ip-muted">Answer each question as you would say it in the interview, in a few sentences. Your answers save as you type. Submit when you are done; the session closes after a day without submitting and your credit is returned.</p>`;
      out += `<label class="ip-row ip-muted" style="margin:6px 0"><input type="checkbox" data-ip-clock${clock.on ? ' checked' : ''}> Use a timer (aim for about 2 minutes per answer, as in a real interview)</label>`;
      out += room(x, x.answers.map(a => `<div class="ip-q"><b>${a.position}. ${E(a.question)}</b>${clock.on ? `<span class="ip-pill" data-ip-time="${a.position}">${mmss(secs(a.position))}</span>` : ''}<textarea data-ip-answer="${a.position}" maxlength="3000">${E(a.answer)}</textarea><details><summary class="ip-guide">What a good answer covers</summary><p class="ip-guide">${E(a.guidance)}</p></details></div>`).join(''));
      out += `<button class="button mint" data-ip-submit>Submit for feedback</button>`;
    } else if (x.status === 'submitted' || x.status === 'evaluating') {
      out += `<p class="ip-muted">Your feedback is being prepared. This usually takes a minute or two; we will also let you know in your notifications.</p><button class="button secondary" data-ip-refresh>Check again</button>`;
    } else if (x.feedback) {
      out += feedbackHtml(x);
    }
    return out + '</div>';
  }

  // 3.61.0 Full mock: one part at a time with a 15-minute guide clock per part. Moving on is the customer's choice;
  // running over is shown, never penalised, and answers in every part stay editable until submitted.
  const part = { idx: 0, ends: 0, tick: null };
  function fullHtml(x) {
    const secs = x.sections, i = Math.min(part.idx, secs.length - 1), sec = secs[i];
    if (!part.ends) part.ends = Date.now() + sec.minutes * 60000;
    clearInterval(part.tick);
    part.tick = setInterval(() => { const el = document.querySelector('[data-ip-part-clock]'); if (!el) { clearInterval(part.tick); return; } const n = Math.round((part.ends - Date.now()) / 1000); el.textContent = n > 0 ? mmss(n) + ' left in this part' : 'Time for this part is up. Move on when you are ready.'; el.style.background = n <= 0 ? 'rgba(234,179,8,.18)' : ''; }, 1000);
    const left = Math.max(0, Math.round((part.ends - Date.now()) / 1000));
    let out = `<p class="ip-muted">Full mock interview: ${secs.length} parts of about 15 minutes each. Answer as you would say it in the interview. Your answers save as you type, and you can go back to any part before you submit.</p>
      <div class="ip-steps">${secs.map((p, k) => `<span class="ip-step${k === i ? ' on' : ''}">${k + 1}. ${E(p.title)}</span>`).join('')}</div>
      <p><b>Part ${i + 1} of ${secs.length}: ${E(sec.title)}</b> <span class="ip-pill" data-ip-part-clock>${left > 0 ? mmss(left) + ' left in this part' : 'Time for this part is up. Move on when you are ready.'}</span></p>`;
    out += x.answers.filter(a => a.position >= sec.from && a.position <= sec.to).map(a => `<div class="ip-q"><b>${a.position}. ${E(a.question)}</b><textarea data-ip-answer="${a.position}" maxlength="3000">${E(a.answer)}</textarea><details><summary class="ip-guide">What a good answer covers</summary><p class="ip-guide">${E(a.guidance)}</p></details></div>`).join('');
    out += `<div class="ip-row">${i > 0 ? '<button class="button secondary" data-ip-part="-1">Previous part</button>' : ''}${i < secs.length - 1 ? '<button class="button mint" data-ip-part="1">Next part</button>' : '<button class="button mint" data-ip-submit>Submit for feedback</button>'}</div>`;
    return out;
  }

  function feedbackHtml(x) {
    const f = x.feedback, r = f.result || {};
    let out = `<p><span class="ip-pill">Level: ${E(f.level)}</span></p><p class="ip-muted">${E(LEVEL_NOTE)}</p><p>${E(r.summary)}</p>`;
    const parts = f.sections || [];
    if (parts.length) out += `<div class="ip-scores">${parts.map((p, k) => `<span>Part ${k + 1}, ${E(p.title)}: <b>${p.average == null ? '–' : p.average + '/4'}</b></span>`).join('')}</div>`;
    (r.answers || []).forEach((a, i) => {
      const q = x.answers[i] || {};
      const head = parts.find(p => p.from === a.position);
      if (head) out += `<h4 style="margin:16px 0 0">Part ${parts.indexOf(head) + 1}: ${E(head.title)}</h4>`;
      out += `<div class="ip-q ip-fb"><b>${a.position}. ${E(q.question)}</b><p class="ip-guide">Your answer: ${E(q.answer)}</p>
        <div class="ip-scores">${Object.entries(f.dimensions).map(([k, v]) => `<span>${E(v)}: <b>${a.scores[k]}/4</b></span>`).join('')}</div>
        ${a.strengths.length ? `<div>What worked<ul>${a.strengths.map(t => `<li>${E(t)}</li>`).join('')}</ul></div>` : ''}
        ${a.improve.length ? `<div>To improve<ul>${a.improve.map(t => `<li>${E(t)}</li>`).join('')}</ul></div>` : ''}
        ${a.missing ? `<p class="ip-guide">Not covered: ${E(a.missing)}</p>` : ''}
        ${a.rewrite ? `<p style="margin:6px 0 4px;font-weight:600">A stronger version using your own facts</p><div class="ip-rewrite">${E(a.rewrite)}</div>` : ''}</div>`;
    });
    if (r.next_step) out += `<p><b>Practise next:</b> ${E(r.next_step)}</p>`;
    out += f.challenged ? '<p class="ip-muted">Thanks, your note about this feedback was sent to our support team.</p>'
      : `<details><summary class="ip-guide">Think this feedback is wrong?</summary><textarea data-ip-note rows="3" style="width:100%;box-sizing:border-box;margin-top:6px" maxlength="1500" placeholder="Tell us what looks wrong"></textarea><button class="button secondary small" data-ip-challenge>Send to support</button></details>`;
    return out + '<p style="margin-top:12px"><button class="button secondary" data-ip-back>Back to interview practice</button></p>';
  }

  const saveTimers = {};
  document.addEventListener('input', e => {
    const t = e.target.closest('[data-ip-answer]'); if (!t || !state?.open) return;
    const pos = +t.dataset.ipAnswer; const id = state.open.id;
    clearTimeout(saveTimers[pos]);
    saveTimers[pos] = setTimeout(() => api(`interviews/${id}/answer`, { position: pos, answer: t.value, ...(clock.on ? { seconds: secs(pos) } : {}) }).catch(() => {}), 800);
    const a = state.open.answers.find(x => x.position === pos); if (a) a.answer = t.value;
  });

  document.addEventListener('focusin', e => { const t = e.target.closest?.('[data-ip-answer]'); if (!t || !clock.on) return; const pos = +t.dataset.ipAnswer; Object.keys(clock.start).forEach(k => { if (clock.start[k]) { clock.spent[k] = (clock.spent[k] || 0) + Date.now() - clock.start[k]; clock.start[k] = 0; } }); clock.start[pos] = Date.now(); });
  document.addEventListener('change', e => {
    if (!e.target.matches?.('[data-ip-clock]')) return; clock.on = e.target.checked; clearInterval(clock.tick);
    if (clock.on) clock.tick = setInterval(() => document.querySelectorAll('[data-ip-time]').forEach(el => { const n = secs(+el.dataset.ipTime); el.textContent = mmss(n); el.style.background = n > 150 ? 'rgba(234,179,8,.18)' : ''; }), 1000);
    render();
  });
  let findTimer = null;
  document.addEventListener('input', e => {
    const f = e.target.closest?.('[data-ip-find]'); if (!f) return; clearTimeout(findTimer);
    findTimer = setTimeout(async () => { const box = host()?.querySelector('[data-ip-found]'); if (!box) return; if (f.value.trim().length < 2) { box.innerHTML = ''; return; }
      try { const { roles } = await api('interviews/roles?q=' + encodeURIComponent(f.value.trim())); box.innerHTML = roles.length ? roles.map(r => `<button type="button" class="button secondary small" data-ip-pick="${E(r.slug)}">${E(r.name)}${r.matched ? ` <small>(${E(r.matched)})</small>` : ''}</button>`).join('') : '<span class="ip-muted">No match yet. Choose the closest role from the list.</span>'; } catch { box.innerHTML = ''; } }, 250);
  });

  document.addEventListener('click', async e => {
    const h = host(); if (!h || !h.contains(e.target)) return;
    const b = e.target.closest('button'); if (!b) return;
    const val = k => h.querySelector(`[data-ip="${k}"]`)?.value || '';
    try {
      if (b.matches('[data-ip-start]')) {
        b.disabled = true;
        const j = await api('interviews/start', { occupation: val('occupation'), band: val('band'), objective: val('objective'), company: val('company') || null, mode: val('mode') || 'standard' });
        state.open = j.session; part.idx = 0; part.ends = 0; render();
      } else if (b.matches('[data-ip-submit]')) {
        b.disabled = true;
        const id = state.open.id;
        for (const a of state.open.answers) await api(`interviews/${id}/answer`, { position: a.position, answer: a.answer });
        state.open = (await api(`interviews/${id}/submit`, {})).session; render();
      } else if (b.matches('[data-ip-part]')) {
        part.idx = Math.max(0, part.idx + +b.dataset.ipPart); part.ends = 0; render(); host()?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else if (b.matches('[data-ip-refresh]')) {
        await load();
      } else if (b.matches('[data-ip-open]')) {
        state.open = (await api('interviews/' + b.dataset.ipOpen)).session; render();
      } else if (b.matches('[data-ip-back]')) {
        await load(); state.open = null; render();
      } else if (b.matches('[data-ip-challenge]')) {
        const note = h.querySelector('[data-ip-note]')?.value || '';
        state.open = (await api(`interviews/${state.open.id}/challenge`, { note })).session; render();
      } else if (b.matches('[data-ip-pick]')) {
        const sel = h.querySelector('[data-ip="occupation"]'); if (sel) sel.value = b.dataset.ipPick; state.pick = b.dataset.ipPick; h.querySelector('[data-ip-found]').innerHTML = '';
      } else if (b.matches('[data-ip-research]')) {
        const out = h.querySelector('[data-ip-research-out]'); b.disabled = true; out.innerHTML = '<p class="ip-muted">Reading the page…</p>';
        try {
          const r = (await api('interviews/research', { company: h.querySelector('[data-ip-rc=company]').value, url: h.querySelector('[data-ip-rc=url]').value, occupation: val('occupation') || null })).research;
          out.innerHTML = r.status !== 'ready' ? `<p class="ip-muted">${E(r.message || r.note || 'That page could not be read.')}</p>` : `<div style="margin-top:12px"><p><b>${E(r.company)}</b> · <a href="${E(r.source)}" rel="nofollow noopener" target="_blank">source</a> · read ${E(when(r.readAt))}</p>${r.summary ? `<blockquote class="ip-guide" style="margin:6px 0 10px">${E(r.summary)}</blockquote>` : ''}<p class="ip-muted"><b>${E(r.label)}</b></p>${r.questions.map((q, i) => `<div class="ip-q"><b>${i + 1}. ${E(q.text)}</b><span class="ip-pill">${E(q.type)}</span><p class="ip-guide">${E(q.tip)}</p><textarea maxlength="3000" placeholder="Practise your answer here (not saved)"></textarea></div>`).join('')}</div>`;
        } catch (err) { out.innerHTML = `<p class="ip-err">${E(err.message)}</p>`; } finally { b.disabled = false; }
      } else if (b.matches('[data-ip-revise]')) {
        const j = await api('interviews/revision'); const box = h.querySelector('[data-ip-bank]');
        box.innerHTML = `<div class="ip-card"><h3>Revision</h3><p class="ip-muted">${E(j.note)}</p>${j.questions.map((q, i) => `<div class="ip-q"><b>${i + 1}. ${E(q.question)}</b><details><summary class="ip-guide">Your earlier answer and what to improve</summary><p class="ip-guide">${E(q.earlier)}</p>${q.improve.length ? `<ul class="ip-guide">${q.improve.map(t => `<li>${E(t)}</li>`).join('')}</ul>` : ''}</details><textarea maxlength="3000" placeholder="Write a better answer (not saved)"></textarea></div>`).join('') || '<p class="ip-muted">Finish a mock interview first; its questions appear here.</p>'}</div>`;
      } else if (b.matches('[data-ip-browse]')) {
        const j = await api(`interviews/questions?occupation=${encodeURIComponent(val('occupation'))}&band=${encodeURIComponent(val('band'))}`);
        const box = h.querySelector('[data-ip-bank]');
        box.innerHTML = `<div class="ip-card"><h3>Practice questions</h3>${j.questions.length ? j.questions.map((q, i) => `<div class="ip-q"><b>${i + 1}. ${E(q.text)}</b><p class="ip-guide">${E(q.guidance)}</p></div>`).join('') : '<p class="ip-muted">Questions for this role are being prepared. Please check back soon.</p>'}
          ${!j.full && j.total > j.questions.length ? `<p class="ip-muted">Showing ${j.questions.length} of ${j.total} questions. Job Hunt and Pro show every question and include mock interviews.</p><button class="button secondary small" data-workspace-view="billing">See plans</button>` : ''}</div>`;
      }
    } catch (err) { b.disabled = false; render(err.message); }
  });

  document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'interview') load(); });
  if (window.CVTaraWorkspaceView === 'interview') load();

  // Interview room: show one question at a time.
  const roomIdx = new WeakMap();
  const showQ = (r, i) => {
    const qs = [...r.querySelectorAll('.ip-q')]; if (!qs.length) return;
    i = Math.max(0, Math.min(qs.length - 1, i)); roomIdx.set(r, i);
    qs.forEach((q, k) => q.classList.toggle('on', k === i));
    const dots = r.querySelector('.ip-dots');
    if (dots) dots.innerHTML = qs.map((q, k) => `<button type="button" class="${k === i ? 'on' : ''}${q.querySelector('textarea')?.value.trim() ? ' done' : ''}" data-ip-dot="${k}" aria-label="Question ${k + 1}"></button>`).join('');
    const prev = r.querySelector('[data-ip-go="-1"]'), next = r.querySelector('[data-ip-go="1"]');
    if (prev) prev.disabled = i === 0;
    if (next) next.hidden = i === qs.length - 1;
    qs[i].querySelector('textarea')?.focus({ preventScroll: true });
  };
  new MutationObserver(() => document.querySelectorAll('.ip-room:not([data-ready])').forEach(r => { r.dataset.ready = '1'; showQ(r, 0); })).observe(document.body, { childList: true, subtree: true });
  document.addEventListener('click', e => {
    const r = e.target.closest('.ip-room'); if (!r) return;
    const go = e.target.closest('[data-ip-go]'), dot = e.target.closest('[data-ip-dot]');
    if (go) showQ(r, (roomIdx.get(r) || 0) + Number(go.dataset.ipGo));
    if (dot) showQ(r, Number(dot.dataset.ipDot));
  });
})();
