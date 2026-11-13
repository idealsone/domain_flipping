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
  let S = null, F = { occupation: '', status: '' }, QG = null;

  async function load() {
    const h = $('#admin-interviews'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    try { S = await api('/api/admin/interviews'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    draw();
  }
  const roleName = slug => slug ? ((S.occupations.find(o => o.slug === slug) || {}).name || slug) : 'Every role';

  function draw() {
    const h = $('#admin-interviews'), c = S.counts || {}, st = S.settings;
    h.innerHTML = `<p class="muted" style="margin:0 0 12px;max-width:820px">Customers practise interview questions and sit ${st.minutes}-minute mock interviews with a named practice panel. A few questions come from their own resume (and a job-switch question for people changing jobs). Results, with a model answer for each question built from their resume and cover letter, are released ${st.releaseDelay} minutes after the interview. Job Hunt includes 4 mock interviews and Pro 12 for the whole plan. Only <b>approved</b> bank questions are ever shown. A credit is given back automatically if feedback fails or nothing was written.</p>
      <div class="ops-kpis"><div class="kpi"><span>Approved questions</span><b>${c.approved || 0}</b></div><div class="kpi"><span>Waiting for approval</span><b>${c.draft || 0}</b></div><div class="kpi"><span>Retired</span><b>${c.retired || 0}</b></div><div class="kpi"><span>Mock interviews</span><b>${S.available ? 'Open' : 'Off'}</b></div><div class="kpi"><span>Model answers made today</span><b>${S.modelAnswersToday || 0}</b></div></div>
      <details class="panel panel-pad" style="margin:12px 0"><summary><b>Approved questions per role</b> <small class="muted">(a role needs about 8 for a full interview)</small></summary><div class="ip-cov" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px;margin-top:8px">${[['', 'Every role (general)'], ...S.occupations.map(o => [o.slug, o.name])].map(([k, n]) => { const c = (S.coverage || {})[k] || 0; return `<span style="padding:6px 8px;border-radius:8px;background:${c < 3 ? 'rgba(220,38,38,.08)' : 'rgba(15,23,42,.04)'}">${E(n)}: <b>${c}</b></span>`; }).join('')}</div></details>
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Switch and limits</h3>
        ${S.feedbackSwitchedOn ? '' : '<p role="alert" style="color:var(--danger)">Written feedback needs the writing service switched on in Settings > Integrations > AI. Until then customers cannot start mock interviews.</p>'}
        <form class="ops-form" id="ivSet"><label>Mock interviews<select name="enabled"><option value="1" ${st.enabled ? 'selected' : ''}>On</option><option value="0" ${st.enabled ? '' : 'selected'}>Off</option></select></label>
        <label>Questions per mock interview<input type="number" name="questions" min="3" max="10" value="${st.questions}"></label>
        <label>Minutes per mock interview<input type="number" name="minutes" min="10" max="60" value="${st.minutes}"></label>
        <label>Release results this many minutes after the interview<input type="number" name="releaseDelay" min="0" max="120" value="${st.releaseDelay}"></label>
        <label>Model answers one customer may ask for a day<input type="number" name="modelAnswersDaily" min="0" max="50" value="${st.modelAnswersDaily}"></label>
        <label>Mock interviews one customer may start a day<input type="number" name="dailySessions" min="1" max="20" value="${st.dailySessions}"></label>
        <label>Full mock interview (about 60 minutes, 4 parts of 15 minutes, uses one credit; off by default since 13 Nov)<select name="fullMock"><option value="1" ${st.fullMock !== false ? 'selected' : ''}>Offered</option><option value="0" ${st.fullMock === false ? 'selected' : ''}>Not offered</option></select></label>
        <button class="button primary" type="submit">Save</button></form></div>
      ${bankPanel()}
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Question bank</h3>
        <div class="ops-form" style="margin-bottom:10px"><label>Role for "Approve all drafts for this role"<select id="ivRole"><option value="">Choose a role</option><option value="general" ${F.occupation === 'general' ? 'selected' : ''}>Every role (general)</option>${S.occupations.map(o => `<option value="${E(o.slug)}" ${F.occupation === o.slug ? 'selected' : ''}>${E(o.name)}</option>`).join('')}</select></label>
        <button class="button secondary" id="ivApproveAll" ${F.occupation ? '' : 'disabled title="Pick a role first"'}>Approve all drafts for this role</button>
        <button class="button primary" id="ivApproveEvery" ${c.draft ? '' : 'disabled title="No drafts waiting"'}>Approve all ${c.draft || 0} drafts (every role)</button>
        <button class="button secondary" id="ivSeed">Load any new starter questions</button></div>
        <details class="panel panel-pad" style="margin:0 0 12px" id="ivUp"><summary><b>Bulk upload questions (Excel or CSV)</b></summary>
          <p class="muted" style="margin:8px 0">One question per row. Columns: Role, Question, Guidance, Example, Skill, Difficulty (1 to 3), From and To (experience: ${Object.keys(S.bands || {}).join(', ') || 'fresher, 1-3, 3-5, 5-8, 8-12, 12+'}). Role can be a role name from the list above, or "general" for every role. A question already in the bank is skipped.</p>
          <div class="ops-form"><label class="grow">File<input type="file" id="ivFile" accept=".csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></label>
          <label>After upload<select id="ivUpMode"><option value="0">Keep as drafts for me to check</option><option value="1">Approve straight away</option></select></label>
          <button class="button secondary small" type="button" id="ivBlank">⬇ Download blank sheet</button><button class="button primary" type="button" id="ivUpload">Upload</button></div>
          <div id="ivUpRes" role="status" style="margin-top:8px"></div></details>
        <div id="ivQGrid"></div></div>
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Give complimentary mock interviews</h3>
        <form class="ops-form" id="ivGrant"><label>Customer email<input type="email" name="email" required></label><label>How many<input type="number" name="count" min="1" max="50" value="1" required></label><label class="wide">Reason (kept in the record)<input name="reason" required minlength="3" maxlength="300" placeholder="Feedback failed twice, goodwill"></label><button class="button primary" type="submit">Give</button></form></div>
      <div class="panel panel-pad"><h3 style="margin-top:0">Mock interviews</h3><div id="ivSGrid"></div></div>
      <div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Practice panel</h3><p class="muted" style="margin:0">Two of these made-up practice interviewers ask the questions in each mock interview, a different pair each time. Customers are told they are practice interviewers, not real people.</p><p style="margin:6px 0 0">${(S.panel || []).map(p => `${E(p.name)} (${E(p.title)})`).join(', ')}</p></div>`;

    QG = K.grid($('#ivQGrid', h), { id: 'interview-questions', url: '/api/admin/interviews/questions', rowsKey: 'rows', search: 'Code, question or guidance', sort: 'code', dir: 'asc', dates: 'Changed',
      filters: [{ key: 'occupation', label: 'Role', options: () => [['general', 'Every role (general)'], ...S.occupations.map(o => [o.slug, o.name])] }, { key: 'status', label: 'Status', options: [['draft', 'Draft'], ['approved', 'Approved'], ['retired', 'Retired']] }],
      columns: [{ label: 'Code', sort: 'code', html: q => `${E(q.code)}<br><small class="muted">v${E(q.version)}</small>` }, { label: 'Role', sort: 'role', html: q => E(roleName(q.occupation)) },
        { label: 'Question', html: q => `<div style="min-width:280px">${E(q.text)}<br><small class="muted">${E(q.guidance)}</small></div>` }, { key: 'competency', label: 'Skill' }, { label: 'Experience', html: q => `${E(q.min_band)} to ${E(q.max_band)}` },
        { label: 'Status', sort: 'status', html: q => `${E(q.status)}${q.approvedBy ? `<br><small class="muted">${E(q.approvedBy)}</small>` : ''}` },
        { label: '', html: q => `<span style="white-space:nowrap">${q.status === 'draft' ? `<button class="button small primary" data-iv-approve="${q.id}">Approve</button> ` : ''}<button class="button small secondary" data-iv-edit="${q.id}">Edit</button> ${q.status !== 'retired' ? `<button class="button small secondary" data-iv-retire="${q.id}">Retire</button>` : ''}</span>` }],
      onClick: (e, g) => {
        const a = e.target.closest('[data-iv-approve]'), r = e.target.closest('[data-iv-retire]'), ed = e.target.closest('[data-iv-edit]');
        if (a) act(() => post('/api/admin/interviews/approve', { ids: [+a.dataset.ivApprove] }), 'Approved');
        if (r && confirm('Retire this question? It will no longer be shown.')) act(() => post(`/api/admin/interviews/questions/${r.dataset.ivRetire}/retire`, {}), 'Retired');
        if (ed) edit(((g.data || {}).rows || []).find(q => q.id === +ed.dataset.ivEdit));
      } });
    K.grid($('#ivSGrid', h), { id: 'interview-sessions', url: '/api/admin/interviews/sessions', rowsKey: 'rows', search: 'Customer email or role', sort: 'when', dates: 'Started',
      filters: [{ key: 'status', label: 'Status', options: [['in_progress', 'In progress'], ['submitted', 'Waiting for feedback'], ['evaluated', 'Feedback written'], ['failed', 'Failed, credit returned'], ['abandoned', 'Not finished']] }, { key: 'occupation', label: 'Role', options: () => S.occupations.map(o => [o.slug, o.name]) }],
      columns: [{ label: 'When', sort: 'when', html: x => E(K.dmy ? K.dmy(K.parse(x.created_at)) : x.created_at) }, { key: 'email', label: 'Customer' }, { label: 'Role', html: x => `${E(x.role || x.occupation)} · ${E(x.band)}${x.track === 'switch' ? '<br><small class="muted">switching jobs</small>' : ''}` },
        { label: 'Status', sort: 'status', html: x => `${E(x.status)}${x.error ? `<br><small class="muted">${E(x.error)}</small>` : ''}` }, { label: 'Level', html: x => E(x.level || '') }, { label: "Customer's challenge", html: x => E(x.challenge || '') }] });

    drawBank(h);
    $('#ivRole', h).onchange = e => { F.occupation = e.target.value; draw(); };
    $('#ivSeed', h).onclick = () => act(() => post('/api/admin/interviews/seed', {}).then(j => { toast(j.added ? `${j.added} new starter questions loaded as drafts` : 'Every starter question is already loaded'); return j; }));
    $('#ivApproveEvery', h).onclick = () => { if (confirm(`Approve all ${c.draft || 0} draft questions for every role? Customers will see them once mock interviews are on.`)) act(() => post('/api/admin/interviews/approve', { occupation: '*' }).then(j => { toast(`${j.approved} questions approved`); return j; })); };
    $('#ivBlank', h).onclick = () => { location.href = '/api/admin/interviews/template'; };
    $('#ivUpload', h).onclick = async ev => {
      const f = $('#ivFile', h).files[0], out = $('#ivUpRes', h); if (!f) { out.textContent = 'Choose a file first.'; return; }
      if (!/\.(csv|txt|xlsx)$/i.test(f.name)) { out.textContent = 'Save the sheet as Excel .xlsx or as CSV, then choose that file.'; return; }
      if (f.size > 8e6) { out.textContent = 'That file is over 8 MB. Split it into smaller files.'; return; }
      const body = { approve: $('#ivUpMode', h).value === '1' };
      if (/\.xlsx$/i.test(f.name)) { const b = new Uint8Array(await f.arrayBuffer()); let bin = ''; for (let k = 0; k < b.length; k += 0x8000) bin += String.fromCharCode.apply(null, b.subarray(k, k + 0x8000)); body.sheet = btoa(bin); } else body.text = await f.text();
      ev.target.disabled = true; out.textContent = 'Reading the file…';
      try {
        const j = await post('/api/admin/interviews/import', body), r = j.import;
        S = j; draw(); const o = $('#ivUpRes'); $('#ivUp').open = true;
        o.innerHTML = `<b>${r.added} added</b>${r.approved ? ` and approved` : ' as drafts'} from ${r.received} rows. ${r.duplicate ? r.duplicate + ' already in the bank, skipped. ' : ''}${r.errors.length ? `<br>Not added:<ul>${r.errors.map(x => `<li>${E(x)}</li>`).join('')}</ul>` : ''}`;
      } catch (e) { out.textContent = err(e); ev.target.disabled = false; }
    };
    $('#ivApproveAll', h).onclick = () => { if (confirm(`Approve every draft question for ${roleName(F.occupation === 'general' ? null : F.occupation)}? Customers will see them once mock interviews are on.`)) act(() => post('/api/admin/interviews/approve', { occupation: F.occupation }), 'Approved'); };
    $('#ivSet', h).onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      if (!(await ensureRecent())) return;
      act(() => post('/api/admin/interviews/settings', { enabled: f.enabled.value === '1', questions: +f.questions.value, dailySessions: +f.dailySessions.value, fullMock: f.fullMock.value === '1', minutes: +f.minutes.value, releaseDelay: +f.releaseDelay.value, modelAnswersDaily: +f.modelAnswersDaily.value }), 'Saved', true);
    };
    $('#ivGrant', h).onsubmit = ev => {
      ev.preventDefault(); const f = ev.target;
      act(() => post('/api/admin/interviews/grant', { email: f.email.value.trim(), count: +f.count.value, reason: f.reason.value.trim() }).then(j => { toast(`Given. They now have ${j.left} left.`); f.reset(); }), null, true);
    };
  }

  // 3.72.0 the large question bank: about 5.8 lakh questions made from approved patterns, only when a customer needs one.
  const lakh = n => n >= 100000 ? (n / 100000).toFixed(2).replace(/\.?0+$/, '') + ' lakh' : Number(n || 0).toLocaleString('en-IN');
  const TIER = { entry: 'Fresher to 3 years', mid: '3 to 8 years', senior: '8 years or more' };
  function bankPanel() {
    const b = S.bank || {}, on = (b.settings || {}).enabled !== false;
    return `<div class="panel panel-pad" style="margin:12px 0"><h3 style="margin-top:0">Large question bank</h3>
      <p class="muted" style="margin:0 0 10px;max-width:820px">${b.families || 0} job families, ${b.roles || 0} roles with 10 skills each, and ${b.patterns || 0} question patterns written for CVTara in the standard interview categories (HR round, behavioural, situational, role skills, customer handling, leadership, integrity, motivation and career). Each pattern is worded for freshers, mid-level and senior candidates. A question is made only when a customer practises it, and only from patterns you approve here. Open a pattern to read sample questions before approving it.</p>
      <div class="ops-kpis"><div class="kpi"><span>Questions possible</span><b>${lakh(b.possible)}</b></div><div class="kpi"><span>Live for customers now</span><b>${lakh(b.live)}</b></div><div class="kpi"><span>Patterns approved</span><b>${b.approvedPatterns || 0} of ${b.patterns || 0}</b></div><div class="kpi"><span>Asked in mock interviews so far</span><b>${lakh(b.stored)}</b></div><div class="kpi"><span>Ticked as practised</span><b>${lakh(b.practised)}</b></div></div>
      <div class="ops-form" style="margin:10px 0"><label>Large bank<select id="ivBankOn"><option value="1" ${on ? 'selected' : ''}>On</option><option value="0" ${on ? '' : 'selected'}>Off (starter questions only)</option></select></label>
        <button class="button primary" type="button" id="ivBankAll" ${b.approvedPatterns === b.patterns ? 'disabled' : ''}>Approve every pattern</button>
        <button class="button secondary" type="button" id="ivBankNone" ${b.approvedPatterns ? '' : 'disabled'}>Withdraw every pattern</button>
        <button class="button secondary" type="button" id="ivBankSync">Add any new roles and patterns</button></div>
      <div id="ivPGrid"></div></div>`;
  }
  function drawBank(h) {
    const cats = (S.bank || {}).categories || {};
    K.grid($('#ivPGrid', h), { id: 'interview-patterns', url: '/api/admin/interviews/bank/patterns', rowsKey: 'rows', search: 'Pattern code or wording', sort: 'code', dir: 'asc', dates: 'Changed',
      filters: [{ key: 'status', label: 'Status', options: [['draft', 'Waiting for approval'], ['approved', 'Approved'], ['withdrawn', 'Withdrawn']] }, { key: 'category', label: 'Category', options: () => Object.entries(cats) }, { key: 'kind', label: 'Asked', options: [['skill', 'For each skill of a role'], ['role', 'Once per role']] }],
      columns: [{ key: 'code', label: 'Pattern', sort: 'code' }, { label: 'Category', sort: 'category', html: p => E(cats[p.category] || p.category) },
        { label: 'Wording (3 to 8 years)', html: p => `<div style="min-width:280px">${E(p.text)}</div>` }, { label: 'Questions it makes', html: p => lakh(p.size) },
        { label: 'Status', sort: 'status', html: p => `${E(p.status)}${p.approvedBy ? `<br><small class="muted">${E(p.approvedBy)}</small>` : ''}` },
        { label: '', html: p => `<span style="white-space:nowrap"><button class="button small secondary" data-iv-sample="${E(p.code)}">Samples</button> ${p.status === 'approved' ? `<button class="button small secondary" data-iv-pw="${E(p.code)}">Withdraw</button>` : `<button class="button small primary" data-iv-pa="${E(p.code)}">Approve</button>`}</span>` }],
      onClick: e => {
        const sm = e.target.closest('[data-iv-sample]'), a = e.target.closest('[data-iv-pa]'), w = e.target.closest('[data-iv-pw]');
        if (sm) samples(sm.dataset.ivSample);
        if (a) act(() => post('/api/admin/interviews/bank/status', { codes: [a.dataset.ivPa], status: 'approved' }), 'Approved');
        if (w && confirm('Withdraw this pattern? Customers stop getting new questions from it; questions already asked stay in their history.')) act(() => post('/api/admin/interviews/bank/status', { codes: [w.dataset.ivPw], status: 'withdrawn' }), 'Withdrawn');
      } });
    $('#ivBankAll', h).onclick = () => { if (confirm('Approve every question pattern? Customers will get questions from all of them.')) act(() => post('/api/admin/interviews/bank/status', { all: true, status: 'approved' }), 'Every pattern approved'); };
    $('#ivBankNone', h).onclick = () => { if (confirm('Withdraw every pattern? Customers will see the starter questions only.')) act(() => post('/api/admin/interviews/bank/status', { all: true, status: 'withdrawn' }), 'Every pattern withdrawn'); };
    $('#ivBankSync', h).onclick = () => act(() => post('/api/admin/interviews/bank/sync', {}).then(j => { toast(`${j.addedRoles} new roles and ${j.addedPatterns} new patterns added`); return j; }));
    $('#ivBankOn', h).onchange = async e => { if (!(await ensureRecent())) { draw(); return; } act(() => post('/api/admin/interviews/bank/settings', { enabled: e.target.value === '1' }), 'Saved'); };
  }
  async function samples(code) {
    let j; try { j = await api('/api/admin/interviews/bank/patterns/' + code); } catch (e) { toast(err(e), true); return; }
    const p = j.pattern, ok = p.status === 'approved';
    const m = modal('Pattern ' + code, `<p class="muted" style="margin-top:0">${p.kind === 'role' ? 'Asked once for each role' : 'Asked for each of the 10 skills of a role'}. Wording by experience:</p>
      <ul>${Object.entries(p.text).map(([t, x]) => `<li><b>${E(TIER[t] || t)}:</b> ${E(x)}</li>`).join('')}</ul>
      <p><b>What a good answer covers:</b> ${E(p.guidance)}</p><h4>Sample questions</h4>
      <ol>${j.samples.map(s => `<li>${E(s.text)}<br><small class="muted">${E(s.role)} · ${E(TIER[s.level] || s.level)}${s.skill ? ' · ' + E(s.skill) : ''}</small></li>`).join('')}</ol>
      <button class="button ${ok ? 'secondary' : 'primary'}" type="button" id="ivSampleAct">${ok ? 'Withdraw this pattern' : 'Approve this pattern'}</button>`, { wide: true });
    $('#ivSampleAct', m.el).onclick = () => { m.close(); act(() => post('/api/admin/interviews/bank/status', { codes: [code], status: ok ? 'withdrawn' : 'approved' }), ok ? 'Withdrawn' : 'Approved'); };
  }

  async function act(fn, msg, keep) {
    try { await fn(); if (msg) toast(msg); await load(); } catch (e) { toast(err(e), true); }
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
