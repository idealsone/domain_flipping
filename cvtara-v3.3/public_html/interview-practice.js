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
        <div class="ip-form"><label>Role<select data-ip="occupation">${roleOptions()}</select></label>
        <label>Your experience<select data-ip="band">${opts(s.bands, 'fresher')}</select></label>
        <label>Your goal<select data-ip="objective">${opts(s.objectives, 'first_job')}</select></label>
        <label>Company (optional)<input data-ip="company" maxlength="120" placeholder="e.g. a bank or retail chain"></label></div>
        ${can ? '<button class="button mint" data-ip-start>Start mock interview</button>' : (!s.enabled ? '<p class="ip-muted">Mock interviews will open soon. You can practise questions below in the meantime.</p>' : '<p class="ip-muted">Job Hunt includes 4 mock interviews and Pro includes 12, for the whole plan.</p><button class="button secondary" data-workspace-view="billing">See plans</button>')}
        <button class="button secondary" data-ip-browse>Practise questions</button></div><div data-ip-bank></div>`;
    }
    if (s.past.length) {
      out += `<div class="ip-card"><h3>Your mock interviews</h3>${s.past.map(p => `<div class="ip-row" style="padding:6px 0"><span>${E(p.role || p.occupation)} · ${E(when(p.created_at))}</span><span class="ip-pill">${E(label(p.status, p.level))}</span>${p.status === 'evaluated' ? `<button class="button secondary small" data-ip-open="${E(p.id)}">Read feedback</button>` : ''}</div>`).join('')}</div>`;
    }
    h.innerHTML = out;
  }
  function progressHtml(p) {
    const lv = p.level ? p.levels.indexOf(p.level) : -1;
    const steps = p.levels.map((l, i) => `<span class="ip-step${i <= lv ? ' on' : ''}">${E(l)}</span>`).join('');
    const trend = p.level && p.previousLevel && p.level !== p.previousLevel ? (p.levels.indexOf(p.level) > p.levels.indexOf(p.previousLevel) ? ' Up from your last mock interview.' : ' A little lower than last time, which is normal with harder questions.') : '';
    return `<div class="ip-card"><h3>Your progress</h3>
      <div class="ip-steps">${steps}</div><p class="ip-muted">${p.level ? `Latest level: <b>${E(p.level)}</b>.${trend}` : 'Finish a mock interview to see your level.'} ${E(LEVEL_NOTE)}</p>
      <p style="margin:10px 0 4px"><b>Practice streak: ${p.streak} day${p.streak === 1 ? '' : 's'}</b>${p.bestStreak > p.streak ? ` <span class="ip-muted">(best ${p.bestStreak})</span>` : ''}</p>
      <p class="ip-muted" style="margin:0">A practice day is any day you open practice questions or finish a mock interview. You can rest ${p.restDays} day between practice days without losing your streak.${p.practisedToday ? ' You have practised today.' : ''}</p>
      <div class="ip-badges">${p.badges.map(b => `<span class="ip-badge${b.earned ? ' on' : ''}" title="${E(b.about)}"><b>${b.earned ? '★' : '☆'} ${E(b.name)}</b><small>${E(b.about)}</small></span>`).join('')}</div>
      <p class="ip-muted" style="margin:8px 0 0">Your progress is private to you. There are no rankings.</p></div>`;
  }
  const label = (st, lvl) => ({ in_progress: 'In progress', submitted: 'Preparing feedback', evaluating: 'Preparing feedback', evaluated: lvl || 'Feedback ready', failed: 'Credit returned', abandoned: 'Not finished, credit returned' }[st] || st);

  function sessionHtml(x) {
    let out = `<div class="ip-card" data-ip-session="${E(x.id)}"><h3>${E(x.role)} · ${E(x.bandLabel)}</h3>`;
    if (x.status === 'in_progress') {
      out += `<p class="ip-muted">Answer each question as you would say it in the interview, in a few sentences. Your answers save as you type. Submit when you are done; the session closes after a day without submitting and your credit is returned.</p>`;
      out += x.answers.map(a => `<div class="ip-q"><b>${a.position}. ${E(a.question)}</b><textarea data-ip-answer="${a.position}" maxlength="3000">${E(a.answer)}</textarea><details><summary class="ip-guide">What a good answer covers</summary><p class="ip-guide">${E(a.guidance)}</p></details></div>`).join('');
      out += `<button class="button mint" data-ip-submit>Submit for feedback</button>`;
    } else if (x.status === 'submitted' || x.status === 'evaluating') {
      out += `<p class="ip-muted">Your feedback is being prepared. This usually takes a minute or two; we will also let you know in your notifications.</p><button class="button secondary" data-ip-refresh>Check again</button>`;
    } else if (x.feedback) {
      out += feedbackHtml(x);
    }
    return out + '</div>';
  }

  function feedbackHtml(x) {
    const f = x.feedback, r = f.result || {};
    let out = `<p><span class="ip-pill">Level: ${E(f.level)}</span></p><p class="ip-muted">${E(LEVEL_NOTE)}</p><p>${E(r.summary)}</p>`;
    (r.answers || []).forEach((a, i) => {
      const q = x.answers[i] || {};
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
    saveTimers[pos] = setTimeout(() => api(`interviews/${id}/answer`, { position: pos, answer: t.value }).catch(() => {}), 800);
    const a = state.open.answers.find(x => x.position === pos); if (a) a.answer = t.value;
  });

  document.addEventListener('click', async e => {
    const h = host(); if (!h || !h.contains(e.target)) return;
    const b = e.target.closest('button'); if (!b) return;
    const val = k => h.querySelector(`[data-ip="${k}"]`)?.value || '';
    try {
      if (b.matches('[data-ip-start]')) {
        b.disabled = true;
        const j = await api('interviews/start', { occupation: val('occupation'), band: val('band'), objective: val('objective'), company: val('company') || null });
        state.open = j.session; render();
      } else if (b.matches('[data-ip-submit]')) {
        b.disabled = true;
        const id = state.open.id;
        for (const a of state.open.answers) await api(`interviews/${id}/answer`, { position: a.position, answer: a.answer });
        state.open = (await api(`interviews/${id}/submit`, {})).session; render();
      } else if (b.matches('[data-ip-refresh]')) {
        await load();
      } else if (b.matches('[data-ip-open]')) {
        state.open = (await api('interviews/' + b.dataset.ipOpen)).session; render();
      } else if (b.matches('[data-ip-back]')) {
        await load(); state.open = null; render();
      } else if (b.matches('[data-ip-challenge]')) {
        const note = h.querySelector('[data-ip-note]')?.value || '';
        state.open = (await api(`interviews/${state.open.id}/challenge`, { note })).session; render();
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
})();
