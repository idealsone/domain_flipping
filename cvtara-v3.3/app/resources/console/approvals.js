/* Owner console: Approval Centre and tasks (3.42.0). One list of everything waiting for a decision (SEO changes, refunds,
   Business assistant actions, agent and team tasks), the task board, and recent decisions. The server checks every
   permission again; this screen only shows what the person may do. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const when = s => { if (!s) return ''; const d = new Date(String(s).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z')); return isNaN(d) ? String(s) : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  const post = (path, body) => api(path, { method: 'POST', body });
  let tab = 'waiting', A = null, T = null;

  K.register('approvals', render, ['✅', 'Approvals & tasks', 'Everything waiting for a decision in one place, and the tasks the SEO and Growth agents and the team are working on.']);

  async function render() {
    const h = $('#admin-approvals'); if (!h) return;
    h.innerHTML = `<div class="sr-tabs" role="tablist" style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px">${[['waiting', 'Waiting for you'], ['tasks', 'Tasks'], ['decided', 'Decided']].map(([k, l]) => `<button type="button" role="tab" class="button ${tab === k ? 'primary' : 'secondary'} small" data-ap-tab="${k}" aria-selected="${tab === k}">${l}</button>`).join('')}</div><div id="apBody"><div class="ops-skel"></div></div>`;
    h.querySelectorAll('[data-ap-tab]').forEach(b => b.onclick = () => { tab = b.dataset.apTab; render(); });
    const body = $('#apBody');
    try {
      if (tab === 'tasks') { T = await api('/api/admin/tasks'); tasks(body); }
      else { A = await api('/api/admin/approvals'); count(); tab === 'waiting' ? waiting(body) : decided(body); }
    } catch (e) { body.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }
  function count() { const n = $('#apprCount'); if (n && A) n.textContent = A.items.length ? String(A.items.length) : ''; }

  function waiting(h) {
    if (!A.items.length) { h.innerHTML = '<div class="panel panel-pad"><p style="margin:0">Nothing is waiting for you.</p></div>'; return; }
    h.innerHTML = `<div class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>Kind</th><th>What</th><th>Submitted by</th><th>Cost</th><th>Waiting since</th><th></th></tr></thead><tbody>${A.items.map((x, i) => `<tr><td>${E(x.categoryLabel)}</td><td>${E(x.action)}${x.where && x.where.length ? `<br><small class="muted">${E(x.where[1] || '')}</small>` : ''}</td><td>${E(x.submittedBy)}</td><td>${E(x.cost)}</td><td>${E(when(x.createdAt))}${x.waitingLong ? ' <b style="color:#b45309">· over 48 h</b>' : ''}</td><td><button type="button" class="button secondary small" data-ap-open="${i}">Review</button></td></tr>`).join('')}</tbody></table></div>`;
    h.querySelectorAll('[data-ap-open]').forEach(b => b.onclick = () => review(A.items[+b.dataset.apOpen]));
  }
  // 3.45.0: a readable before / after (FAQ questions, outlines and link lists as lists, not raw JSON).
  const LBL = { title: 'Title', description: 'Description', h1: 'Main heading', items: 'Questions', type: 'Structured data', types: 'Structured data now', count: 'Questions now', label: 'Link text', from: 'Linked from', topic: 'Topic', keyword: 'Target search', outline: 'Outline', notes: 'Notes', cluster: 'Topic group' };
  const val = v => Array.isArray(v) ? (v.length ? `<ul style="margin:2px 0;padding-left:18px">${v.map(x => `<li>${Array.isArray(x) ? `<b>${E(x[0])}</b><br><small>${E(x[1] || '')}</small>` : E(typeof x === 'object' ? JSON.stringify(x) : x)}</li>`).join('')}</ul>` : '<span class="muted">none</span>') : E(v === '' ? '—' : typeof v === 'object' && v ? JSON.stringify(v) : v);
  const json = v => v == null ? '' : typeof v === 'string' ? `<p style="margin:0">${E(v)}</p>` : `<dl style="margin:0;font-size:13px">${Object.entries(v).filter(([k]) => k !== 'by').map(([k, x]) => `<dt class="muted" style="font-size:12px">${E(LBL[k] || k)}</dt><dd style="margin:0 0 6px">${val(x)}</dd>`).join('') || '<span class="muted">—</span>'}</dl>`;
  function review(x) {
    const m = modal(x.categoryLabel + ': review', `<div style="display:grid;gap:10px">
      <p style="margin:0"><b>${E(x.action)}</b></p>
      <dl style="display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;margin:0"><dt>ID</dt><dd style="margin:0"><code>${E(x.id)}</code></dd><dt>Submitted by</dt><dd style="margin:0">${E(x.submittedBy)}</dd><dt>Agent</dt><dd style="margin:0">${E(x.agent)}</dd>${x.where && x.where.length ? `<dt>Where</dt><dd style="margin:0">${x.where.map(w => E(w)).join('<br>')}</dd>` : ''}<dt>Why</dt><dd style="margin:0;white-space:pre-wrap">${E(x.justification)}</dd><dt>Cost</dt><dd style="margin:0">${E(x.cost)}</dd><dt>Impact</dt><dd style="margin:0">${E(x.impact)}</dd><dt>Risk</dt><dd style="margin:0">${E(x.risk)}</dd><dt>Evidence</dt><dd style="margin:0">${x.evidence.length ? x.evidence.map(e => E(e)).join('<br>') : '—'}</dd></dl>
      ${x.changes ? `<details open><summary><b>Proposed change</b></summary><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px"><div><small class="muted">Before</small>${json(x.changes.before)}</div><div><small class="muted">After</small>${json(x.changes.after)}</div></div></details>` : ''}
      <label style="display:grid;gap:4px">Reason (needed to reject)<textarea rows="2" maxlength="1000" data-ap-reason></textarea></label>
      <p role="alert" class="muted" data-ap-err></p>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="button primary" data-ap-do="approve">${x.verify ? 'Verify' : 'Approve'}</button><button type="button" class="button secondary" data-ap-do="reject">${x.verify ? 'Send back' : 'Reject'}</button></div></div>`, { wide: true });
    m.el.querySelectorAll('[data-ap-do]').forEach(b => b.onclick = async () => {
      const reason = m.el.querySelector('[data-ap-reason]').value.trim(), out = m.el.querySelector('[data-ap-err]');
      if (b.dataset.apDo === 'reject' && reason.length < 5) { out.textContent = 'Give a short reason.'; return; }
      if (x.stepUp && !(await ensureRecent())) { out.textContent = 'Please confirm your identity to continue.'; return; }
      b.disabled = true;
      try { await post('/api/admin/approvals/decide', { category: x.category, id: x.id, decision: b.dataset.apDo, reason }); m.close(); toast('Done: ' + (b.dataset.apDo === 'approve' ? (x.verify ? 'verified' : 'approved') : (x.verify ? 'sent back' : 'rejected'))); render(); }
      catch (e) { out.textContent = err(e); b.disabled = false; }
    });
  }
  function decided(h) {
    h.innerHTML = A.history.length ? `<div class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>When</th><th>Kind</th><th>What</th><th>Decision</th><th>By</th><th>Reason</th></tr></thead><tbody>${A.history.map(x => `<tr><td>${E(when(x.created_at))}</td><td>${E(x.categoryLabel)}</td><td>${E(x.summary)}</td><td>${x.decision === 'approve' ? 'Approved' : 'Rejected'}</td><td>${E(x.by)}</td><td>${E(x.reason)}</td></tr>`).join('')}</tbody></table></div>` : '<div class="panel panel-pad"><p style="margin:0">No decisions yet.</p></div>';
  }

  // ---- tasks ----
  function tasks(h) {
    const agents = Object.entries(T.agents);
    h.innerHTML = `<div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px">${T.canCreate.length ? '<button type="button" class="button primary small" data-tk-new>New task</button>' : ''}<span class="muted" style="align-self:center">Approved and Rejected are decided under Waiting for you. Verified needs evidence and someone other than the person who did the work.</span></div>
      ${T.tasks.length ? `<div class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>Task</th><th>Area</th><th>Status</th><th>Priority</th><th>Due</th><th>Who</th><th></th></tr></thead><tbody>${T.tasks.map(t => `<tr><td>${E(t.title)}${t.byAgent ? ' <small class="muted">· from the agent</small>' : ''}</td><td>${E(t.agentLabel)}</td><td>${E(t.statusLabel)}</td><td>${E(t.priority)}</td><td>${E(t.due_at ? String(t.due_at).slice(0, 10) : '')}</td><td>${E(t.assignee || '')}</td><td><button type="button" class="button secondary small" data-tk-open="${E(t.id)}">Open</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="panel panel-pad"><p style="margin:0">No open tasks.</p></div>'}`;
    h.querySelectorAll('[data-tk-open]').forEach(b => b.onclick = () => task(b.dataset.tkOpen));
    const n = h.querySelector('[data-tk-new]'); if (n) n.onclick = () => newTask(agents.filter(([a]) => T.canCreate.includes(a)));
  }
  function newTask(agents) {
    const m = modal('New task', `<form style="display:grid;gap:10px"><label style="display:grid;gap:4px">Area<select name="agent">${agents.map(([a, l]) => `<option value="${E(a)}">${E(l)}</option>`).join('')}</select></label><label style="display:grid;gap:4px">Title<input name="title" required minlength="5" maxlength="200"></label><label style="display:grid;gap:4px">Details<textarea name="detail" rows="4" maxlength="8000"></textarea></label><label style="display:grid;gap:4px">Priority<select name="priority"><option>normal</option><option>low</option><option>high</option><option>urgent</option></select></label><label style="display:grid;gap:4px">Due date<input type="date" name="dueAt"></label><label><input type="checkbox" name="submit" checked> Submit for approval now</label><p role="alert" class="muted" data-err></p><button class="button primary" type="submit">Create</button></form>`);
    const f = m.el.querySelector('form');
    f.onsubmit = async e => {
      e.preventDefault(); const v = Object.fromEntries(new FormData(f)); v.submit = f.submit.checked; if (!v.dueAt) delete v.dueAt;
      try { const j = await post('/api/admin/tasks', v); m.close(); toast('Task created'); render(); task(j.id); } catch (x) { f.querySelector('[data-err]').textContent = err(x); }
    };
  }
  const LABEL = { submitted: 'Submit for approval', under_review: 'Mark under review', in_progress: 'Start / continue', completed: 'Mark completed', blocked: 'Blocked', failed: 'Failed', cancelled: 'Cancel', approved: 'Approve', rejected: 'Reject', verified: 'Verify' };
  async function task(id) {
    const m = modal('Task', '<div class="ops-skel"></div>', { wide: true });
    const draw = d => {
      const t = d.task;
      m.body.querySelector('.ops-skel, [data-tk]')?.remove();
      const box = document.createElement('div'); box.dataset.tk = '1'; box.style.cssText = 'display:grid;gap:10px';
      box.innerHTML = `<p style="margin:0"><b>${E(t.title)}</b> · ${E(t.agentLabel)} · <b>${E(t.statusLabel)}</b> · priority ${E(t.priority)}${t.due_at ? ' · due ' + E(String(t.due_at).slice(0, 10)) : ''}</p>
        ${t.detail ? `<div style="white-space:pre-wrap;border:1px solid var(--line);border-radius:8px;padding:8px 10px">${E(t.detail)}</div>` : ''}
        <div><b>Evidence</b>${d.evidence.length ? `<ul style="margin:4px 0;padding-left:18px">${d.evidence.map(v => `<li>${v.kind === 'link' ? `<a href="${E(v.value)}" target="_blank" rel="noopener noreferrer">${E(v.value)}</a>` : E(v.value)} <small class="muted">${E(v.by || '')} · ${E(when(v.created_at))}</small></li>`).join('')}</ul>` : ' <span class="muted">none yet</span>'}</div>
        ${d.canAddEvidence ? `<form data-ev style="display:flex;gap:6px;flex-wrap:wrap"><select name="kind"><option value="note">Note</option><option value="link">Link</option><option value="metric">Number</option></select><input name="value" required minlength="3" maxlength="1000" placeholder="What shows it worked" style="flex:1;min-width:200px"><button class="button secondary small" type="submit">Add evidence</button></form>` : ''}
        ${(d.moves.length || d.decisions.length) ? `<label style="display:grid;gap:4px">Note<input data-note maxlength="1000" placeholder="Why (needed for blocked, failed, cancelled and rejected)"></label><div style="display:flex;gap:6px;flex-wrap:wrap">${[...d.decisions, ...d.moves].map(s => `<button type="button" class="button ${d.decisions.includes(s) ? 'primary' : 'secondary'} small" data-mv="${E(s)}">${E(LABEL[s] || s)}</button>`).join('')}</div>` : ''}
        <p role="alert" class="muted" data-err></p>
        <details><summary>History</summary><ul style="margin:4px 0;padding-left:18px">${d.events.map(e => `<li>${E(when(e.created_at))}: ${E(e.from_status || 'new')} → ${E(e.to_status)}${e.actor ? ' by ' + E(e.actor) : ''}${e.note ? ' · ' + E(e.note) : ''}</li>`).join('')}</ul></details>`;
      m.body.append(box);
      box.querySelectorAll('[data-mv]').forEach(b => b.onclick = async () => {
        b.disabled = true;
        try { draw(await post(`/api/admin/tasks/${id}/move`, { to: b.dataset.mv, note: box.querySelector('[data-note]')?.value || '' })); toast('Task updated'); }
        catch (e) { box.querySelector('[data-err]').textContent = err(e); b.disabled = false; }
      });
      const f = box.querySelector('[data-ev]');
      if (f) f.onsubmit = async e => { e.preventDefault(); try { draw(await post(`/api/admin/tasks/${id}/evidence`, Object.fromEntries(new FormData(f)))); } catch (x) { box.querySelector('[data-err]').textContent = err(x); } };
    };
    try { draw(await api('/api/admin/tasks/' + id)); } catch (e) { m.body.querySelector('.ops-skel').outerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  // the menu count shows what is waiting, for anyone who can decide something
  setTimeout(() => api('/api/admin/approvals').then(j => { A = j; count(); }).catch(() => {}), 3000);
})();
