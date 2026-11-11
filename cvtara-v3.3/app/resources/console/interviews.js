/* Owner console: Interview practice (3.49.0). Approve the question bank (nothing reaches customers until approved), edit a
   question as a new version, retire one, switch mock interviews on, see recent sessions and customer challenges, and give
   complimentary mock interviews with a reason. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const post = (path, body) => api(path, { method: 'POST', body });
  const BANNED = /\b(ai|a\.i\.|artificial intelligence|anthr[o]pic|cl[a]ude|ch[a]tgpt|op[e]nai|gpt)\b/i;
  let S = null, F = { occupation: '', status: '' };

  async function load() {
    const h = $('#admin-interviews'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    try { S = await api('/api/admin/interviews?' + new URLSearchParams(F)); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    draw();
  }
  const roleName = slug => slug ? ((S.occupations.find(o => o.slug === slug) || {}).name || slug) : 'Every role';

  function draw() {
    const h = $('#admin-interviews'), c = S.counts || {}, st = S.settings;
    h.innerHTML = `<p class="muted" style="margin:0 0 12px;max-width:820px">Customers practise interview questions for non-technical roles and take mock interviews: they answer in writing and get written feedback prepared automatically against a fixed rubric. Job Hunt includes 4 mock interviews and Pro 12 for the whole plan. Only <b>approved</b> questions are ever shown. A credit is given back automatically if feedback fails or the customer does not submit within a day.</p>
      <div class="ops-kpis"><div class="kpi"><span>Approved questions</span><b>${c.approved || 0}</b></div><div class="kpi"><span>Waiting for approval</span><b>${c.draft || 0}</b></div><div class="kpi"><span>Retired</span><b>${c.retired || 0}</b></div><div class="kpi"><span>Mock interviews</span><b>${S.available ? 'Open' : 'Off'}</b></div></div>
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Switch and limits</h3>
        ${S.feedbackSwitchedOn ? '' : '<p role="alert" style="color:var(--danger)">Written feedback needs the writing service switched on in Settings > Integrations > AI. Until then customers cannot start mock interviews.</p>'}
        <form class="ops-form" id="ivSet"><label>Mock interviews<select name="enabled"><option value="1" ${st.enabled ? 'selected' : ''}>On</option><option value="0" ${st.enabled ? '' : 'selected'}>Off</option></select></label>
        <label>Questions per mock interview<input type="number" name="questions" min="3" max="8" value="${st.questions}"></label>
        <label>Mock interviews one customer may start a day<input type="number" name="dailySessions" min="1" max="20" value="${st.dailySessions}"></label>
        <button class="button primary" type="submit">Save</button></form></div>
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Question bank</h3>
        <div class="ops-form" style="margin-bottom:10px"><label>Role<select id="ivRole"><option value="">All</option><option value="general" ${F.occupation === 'general' ? 'selected' : ''}>Every role (general)</option>${S.occupations.map(o => `<option value="${E(o.slug)}" ${F.occupation === o.slug ? 'selected' : ''}>${E(o.name)}</option>`).join('')}</select></label>
        <label>Status<select id="ivStatus"><option value="">All</option>${['draft', 'approved', 'retired'].map(s => `<option ${F.status === s ? 'selected' : ''}>${s}</option>`).join('')}</select></label>
        <button class="button secondary" id="ivApproveAll" ${F.occupation ? '' : 'disabled title="Pick a role first"'}>Approve all drafts for this role</button>
        ${(c.draft || c.approved || c.retired) ? '' : '<button class="button secondary" id="ivSeed">Load the starter questions</button>'}</div>
        <div style="overflow:auto"><table class="ops-table"><thead><tr><th>Code</th><th>Role</th><th>Question</th><th>Skill</th><th>Experience</th><th>Status</th><th></th></tr></thead><tbody>
        ${S.questions.map(q => `<tr><td>${E(q.code)}<br><small class="muted">v${E(q.version)}</small></td><td>${E(roleName(q.occupation))}</td><td style="min-width:280px">${E(q.text)}<br><small class="muted">${E(q.guidance)}</small></td><td>${E(q.competency)}</td><td>${E(q.min_band)} to ${E(q.max_band)}</td><td>${E(q.status)}${q.approvedBy ? `<br><small class="muted">${E(q.approvedBy)}</small>` : ''}</td>
          <td style="white-space:nowrap">${q.status === 'draft' ? `<button class="button small primary" data-iv-approve="${q.id}">Approve</button> ` : ''}<button class="button small secondary" data-iv-edit="${q.id}">Edit</button> ${q.status !== 'retired' ? `<button class="button small secondary" data-iv-retire="${q.id}">Retire</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="7">No questions match.</td></tr>'}
        </tbody></table></div></div>
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Give complimentary mock interviews</h3>
        <form class="ops-form" id="ivGrant"><label>Customer email<input type="email" name="email" required></label><label>How many<input type="number" name="count" min="1" max="50" value="1" required></label><label class="wide">Reason (kept in the record)<input name="reason" required minlength="3" maxlength="300" placeholder="Feedback failed twice, goodwill"></label><button class="button primary" type="submit">Give</button></form></div>
      <div class="panel panel-pad"><h3 style="margin-top:0">Recent mock interviews</h3><div style="overflow:auto"><table class="ops-table"><thead><tr><th>When</th><th>Customer</th><th>Role</th><th>Status</th><th>Level</th><th>Customer's challenge</th></tr></thead><tbody>
        ${S.sessions.map(s => `<tr><td>${E(K.dmy ? K.dmy(s.created_at) : s.created_at)}</td><td>${E(s.email)}</td><td>${E(roleName(s.occupation))} · ${E(s.band)}</td><td>${E(s.status)}${s.error ? `<br><small class="muted">${E(s.error)}</small>` : ''}</td><td>${E(s.level || '')}</td><td>${E(s.challenge || '')}</td></tr>`).join('') || '<tr><td colspan="6">None yet.</td></tr>'}
      </tbody></table></div></div>`;

    $('#ivRole', h).onchange = e => { F.occupation = e.target.value; load(); };
    $('#ivStatus', h).onchange = e => { F.status = e.target.value; load(); };
    const seed = $('#ivSeed', h); if (seed) seed.onclick = () => act(() => post('/api/admin/interviews/seed', {}), 'Starter questions loaded as drafts');
    $('#ivApproveAll', h).onclick = () => { if (confirm(`Approve every draft question for ${roleName(F.occupation === 'general' ? null : F.occupation)}? Customers will see them once mock interviews are on.`)) act(() => post('/api/admin/interviews/approve', { occupation: F.occupation }), 'Approved'); };
    $('#ivSet', h).onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      if (!(await ensureRecent())) return;
      act(() => post('/api/admin/interviews/settings', { enabled: f.enabled.value === '1', questions: +f.questions.value, dailySessions: +f.dailySessions.value }), 'Saved', true);
    };
    $('#ivGrant', h).onsubmit = ev => {
      ev.preventDefault(); const f = ev.target;
      act(() => post('/api/admin/interviews/grant', { email: f.email.value.trim(), count: +f.count.value, reason: f.reason.value.trim() }).then(j => { toast(`Given. They now have ${j.left} left.`); f.reset(); }), null, true);
    };
    h.querySelectorAll('[data-iv-approve]').forEach(b => { b.onclick = () => act(() => post('/api/admin/interviews/approve', { ids: [+b.dataset.ivApprove] }), 'Approved'); });
    h.querySelectorAll('[data-iv-retire]').forEach(b => { b.onclick = () => { if (confirm('Retire this question? It will no longer be shown.')) act(() => post(`/api/admin/interviews/questions/${b.dataset.ivRetire}/retire`, {}), 'Retired'); }; });
    h.querySelectorAll('[data-iv-edit]').forEach(b => { b.onclick = () => edit(S.questions.find(q => q.id === +b.dataset.ivEdit)); });
  }

  async function act(fn, msg, keep) {
    try { const j = await fn(); if (msg) toast(msg); if (!keep && j && j.questions) { await load(); } else if (keep) { await load(); } } catch (e) { toast(err(e), true); }
  }

  function edit(q) {
    const m = modal('Edit question ' + q.code, `<form class="ops-form" id="ivEdit">
      <label class="wide">Question<textarea name="text" rows="3" required minlength="10" maxlength="600">${E(q.text)}</textarea></label>
      <label class="wide">What a good answer covers<textarea name="guidance" rows="4" required minlength="10" maxlength="1200">${E(q.guidance)}</textarea></label>
      <label class="wide">Source<input name="source" maxlength="160" value="${E(q.source || 'CVTara original')}"></label>
      <label>Rights<select name="rights">${['original', 'licensed', 'public'].map(r => `<option ${r === q.rights ? 'selected' : ''}>${r}</option>`).join('')}</select></label>
      <p class="muted wide" style="font-size:13px;margin:0">Saving creates a new version that waits for approval again. Earlier mock interviews keep the wording they used.</p>
      <p class="wide" role="alert" id="ivErr" style="color:var(--danger);margin:0"></p>
      <button class="button primary" type="submit">Save as new version</button></form>`, { wide: true });
    $('#ivEdit', m.el).onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      const v = { text: f.text.value.trim(), guidance: f.guidance.value.trim(), source: f.source.value.trim(), rights: f.rights.value };
      if (BANNED.test(v.text + ' ' + v.guidance)) { $('#ivErr', m.el).textContent = 'Customer-facing text never mentions AI or the tools behind it. Please reword.'; return; }
      try { await post(`/api/admin/interviews/questions/${q.id}`, v); m.close(); toast('Saved. Approve it to show it again.'); load(); } catch (e) { $('#ivErr', m.el).textContent = err(e); }
    };
  }

  K.register('interviews', load, ['🎤', 'Interview practice', 'Question bank approval, mock interview switch, sessions and complimentary credits.']);
})();
