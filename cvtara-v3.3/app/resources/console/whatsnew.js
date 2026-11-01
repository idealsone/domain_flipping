/* Owner console: What's new — dated notes customers see once after an update, and in the workspace any time. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const post = async (path, body) => { if (!(await ensureRecent())) throw new Error('Please confirm your identity to continue.'); return api(path, { method: 'POST', body }); };
  const day = d => new Date(d + 'T12:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  const today = () => new Date(Date.now() + 330 * 60000).toISOString().slice(0, 10);
  const BANNED = /\b(ai|a\.i\.|artificial intelligence|anthr[o]pic|cl[a]ude|ch[a]tgpt|op[e]nai|gpt)\b/i;
  let S = null;

  async function whatsnew() {
    const h = $('#admin-whatsnew'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    try { S = await api('/api/admin/whats-new'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    draw();
  }
  function draw() {
    const h = $('#admin-whatsnew'), list = S.entries, shown = list.filter(e => e.published).length;
    h.innerHTML = `<p class="muted" style="margin:0 0 12px;max-width:780px">Short, dated notes about what you improved for customers. When a signed-in customer opens their workspace after you publish a note, a small window shows the notes they have not seen yet, once. They can open the full list any time from the <b>What's new</b> button in the top bar. Write in plain words, sign as the CVTara team, and leave out owner-only changes.</p>
      <div class="ops-kpis"><div class="kpi"><span>Notes shown to customers</span><b>${shown}</b></div><div class="kpi"><span>Hidden notes</span><b>${list.length - shown}</b></div><div class="kpi"><span>Customers who read updates (30 days)</span><b>${S.stats.readers30}</b></div></div>
      <section class="panel panel-pad"><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px"><h3 style="margin:0;margin-right:auto">Notes</h3><button class="button secondary small" data-wn="preview">Preview as a customer</button><button class="button primary small" data-wn="add">Add a note</button></div>
      <div class="cv-grid-wrap"><table class="ad-table"><thead><tr><th>Date</th><th>Type</th><th>Note</th><th>Shown</th><th></th></tr></thead><tbody>
      ${list.map((e, i) => `<tr data-i="${i}"><td style="white-space:nowrap">${E(day(e.date))}</td><td>${E(S.tags[e.tag] || e.tag)}</td><td><b>${E(e.title)}</b><br><span class="muted" style="font-size:13px">${E(e.body)}</span></td><td>${e.published ? '<span class="pill st-paid">shown</span>' : '<span class="pill st-free">hidden</span>'}</td>
        <td style="white-space:nowrap"><button class="button secondary small" data-wn="edit">Edit</button> <button class="button secondary small" data-wn="toggle">${e.published ? 'Hide' : 'Show'}</button> <button class="button secondary small" data-wn="delete" aria-label="Delete note">Delete</button></td></tr>`).join('') || '<tr><td colspan="5">No notes yet. Add the first one.</td></tr>'}
      </tbody></table></div><p role="status" id="wnMsg" class="muted"></p></section>`;
    h.onclick = e => {
      const b = e.target.closest('[data-wn]'); if (!b) return;
      const i = b.closest('[data-i]') ? +b.closest('[data-i]').dataset.i : -1, act = b.dataset.wn;
      if (act === 'add') return edit(-1);
      if (act === 'preview') return preview();
      if (act === 'edit') return edit(i);
      if (act === 'toggle') { const next = S.entries.map((x, k) => k === i ? { ...x, published: !x.published } : x); return save(next, next[i].published ? 'Note is shown to customers' : 'Note hidden'); }
      if (act === 'delete' && confirm('Delete this note? Customers will no longer see it.')) return save(S.entries.filter((x, k) => k !== i), 'Note deleted');
    };
  }
  async function save(entries, msg) {
    const say = m => { const el = $('#wnMsg'); if (el) el.textContent = m; };
    try {
      const d = await post('/api/admin/whats-new/save', { entries: entries.map(({ id, date, tag, title, body, published }) => ({ id, date, tag, title, body, published: !!published })) });
      S.entries = d.entries; S.stats = d.stats; draw(); toast(msg || 'Saved'); return true;
    } catch (x) { say(err(x)); toast(err(x), true); return false; }
  }
  function edit(i) {
    const e = i >= 0 ? S.entries[i] : { date: today(), tag: 'improved', title: '', body: '', published: true };
    const m = modal(i >= 0 ? 'Edit note' : 'Add a note', `<form class="ops-form" id="wnForm">
      <label>Date<input type="date" name="date" required value="${E(e.date)}"></label>
      <label>Type<select name="tag">${Object.entries(S.tags).map(([k, v]) => `<option value="${k}" ${k === e.tag ? 'selected' : ''}>${E(v)}</option>`).join('')}</select></label>
      <label class="wide">Title (what changed, in a few words)<input name="title" required minlength="3" maxlength="120" value="${E(e.title)}" placeholder="Faster resume downloads"></label>
      <label class="wide">Description (why it helps the customer, one or two sentences)<textarea name="body" required minlength="3" maxlength="600" rows="4" placeholder="Your PDF is now ready in a few seconds, even when the site is busy.">${E(e.body)}</textarea></label>
      <label>Show to customers<select name="published"><option value="1" ${e.published ? 'selected' : ''}>Yes, show it</option><option value="0" ${e.published ? '' : 'selected'}>No, keep it hidden</option></select></label>
      <p class="muted wide" style="font-size:13px;margin:0">Customers who have not seen a newly shown note get it in the small window on their next visit. Editing a note that is already shown does not show it again.</p>
      <p class="wide" role="alert" id="wnErr" style="color:var(--danger);margin:0"></p>
      <button class="button primary" type="submit">Save note</button></form>`);
    $('#wnForm', m.el).onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      const v = { ...e, date: f.date.value, tag: f.tag.value, title: f.title.value.trim(), body: f.body.value.trim(), published: f.published.value === '1' };
      if (BANNED.test(v.title + ' ' + v.body)) { $('#wnErr', m.el).textContent = 'Customer-facing text says "CVTara team" and never mentions AI or the tools behind it. Please reword.'; return; }
      const next = i >= 0 ? S.entries.map((x, k) => k === i ? v : x) : [v, ...S.entries];
      if (await save(next, 'Note saved')) m.close();
    };
  }
  function preview() {
    const TAG = S.tags, list = S.entries.filter(e => e.published);
    modal("What's new on CVTara (customer view)", `<p class="muted" style="margin-top:0">Improvements the CVTara team made for you. We keep working on the site every week.</p>${list.map(e => `<div style="padding:10px 0;border-bottom:1px solid var(--line)"><small class="muted"><b>${E(TAG[e.tag] || '')}</b> · ${E(day(e.date))}</small><div><b>${E(e.title)}</b></div><div class="muted" style="font-size:14px">${E(e.body)}</div></div>`).join('') || '<p>No notes are shown.</p>'}`, { wide: true });
  }

  K.register('whatsnew', whatsnew, ['🔔', "What's new", 'Dated notes about your improvements, shown to customers after each update.']);
})();
