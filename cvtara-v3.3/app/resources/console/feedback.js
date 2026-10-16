/* Owner console: Support Inbox — feedback, bug reports and screenshots sent from the "Feedback" button on the site. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, parse, modal, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const F = { status: 'open', area: 'all', kind: 'all', q: '', page: 1 };
  const STATUS = { new: 'New', seen: 'Seen', working: 'In progress', resolved: 'Resolved', closed: 'Closed' };
  const tone = s => s === 'new' ? 'bad' : s === 'resolved' || s === 'closed' ? 'ok' : 'mid';
  const when = s => { const d = parse(s); return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }); };
  let META = null;

  async function feedback() {
    const h = $('#admin-feedback'); if (!h) return;
    if (!META) h.innerHTML = '<div class="ops-skel"></div>';
    let sum;
    try { sum = await api('/api/admin/support/summary?days=30'); META = { areas: sum.areas, kinds: sum.kinds }; } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const b = sum.byStatus || {}, open = (b.new || 0) + (b.seen || 0) + (b.working || 0), top = Object.entries(sum.recent.byArea || {}).sort((x, y) => y[1] - x[1])[0];
    const nav = document.querySelector('[data-admin-view="feedback"] .nav-count'); if (nav) nav.textContent = b.new ? String(b.new) : '';
    const opt = (v, l, cur) => `<option value="${E(v)}" ${String(cur) === String(v) ? 'selected' : ''}>${E(l)}</option>`;
    h.innerHTML = `<div class="pg-head"><h2>Support Inbox</h2><p>Everything customers and visitors send from the Feedback button: what it is about, their message, screenshots, the page they were on and their plan. Work each report from New to Resolved.</p></div>
      <div class="ops-kpis"><div class="kpi"><span>New</span><b>${b.new || 0}</b></div><div class="kpi"><span>Open (new, seen, in progress)</span><b>${open}</b></div><div class="kpi"><span>Last 30 days</span><b>${sum.recent.total}</b></div><div class="kpi"><span>Average rating</span><b>${sum.recent.avgRating ?? '—'}</b></div><div class="kpi"><span>Most reported area</span><b style="font-size:15px">${top ? E(META.areas[top[0]] || top[0]) + ' · ' + top[1] : '—'}</b></div></div>
      <section class="panel panel-pad" data-nokit><div class="ops-filterbox">
        <label class="ops-f grow">Search<input id="fbq" type="search" value="${E(F.q)}" placeholder="Message, name, email or page…"></label>
        <label class="ops-f">Status<select id="fbs">${opt('open', 'Open (not finished)', F.status)}${opt('all', 'Everything', F.status)}${Object.entries(STATUS).map(([k, l]) => opt(k, l, F.status)).join('')}</select></label>
        <label class="ops-f">About<select id="fba">${opt('all', 'Everything', F.area)}${Object.entries(META.areas).map(([k, l]) => opt(k, l, F.area)).join('')}</select></label>
        <label class="ops-f">Type<select id="fbk">${opt('all', 'Everything', F.kind)}${Object.entries(META.kinds).map(([k, l]) => opt(k, l, F.kind)).join('')}</select></label>
        <button type="button" class="button secondary" id="fbr">Refresh</button></div></section>
      <section class="panel" data-nokit style="overflow:auto"><table class="ad-table" id="fbt"><tbody><tr><td>Loading…</td></tr></tbody></table></section><div class="ops-pager" id="fbp"></div>`;
    const reload = () => { F.page = 1; list(h); };
    let t; $('#fbq', h).oninput = e => { F.q = e.target.value; clearTimeout(t); t = setTimeout(reload, 300); };
    $('#fbs', h).onchange = e => { F.status = e.target.value; reload(); };
    $('#fba', h).onchange = e => { F.area = e.target.value; reload(); };
    $('#fbk', h).onchange = e => { F.kind = e.target.value; reload(); };
    $('#fbr', h).onclick = () => feedback();
    list(h);
  }

  async function list(h) {
    const tbl = $('#fbt', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(F.page), per: '20' }); if (F.q) qs.set('q', F.q); if (F.status !== 'all') qs.set('status', F.status); if (F.area !== 'all') qs.set('area', F.area); if (F.kind !== 'all') qs.set('kind', F.kind);
      const d = await api('/api/admin/support?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Received</th><th>From</th><th>About</th><th>Message</th><th>Status</th></tr></thead><tbody>${d.rows.map(r => `<tr data-id="${E(r.id)}" class="fb-row" tabindex="0" style="cursor:pointer">
        <td>${E(when(r.created_at))}</td><td><b>${E(r.name || 'Visitor')}</b><br><small>${E(r.email || 'no email')} · ${E(r.plan || '')}</small></td>
        <td>${E(META.areas[r.area] || r.area)}<br><small>${E(META.kinds[r.kind] || r.kind)}${r.rating ? ' · ' + '★'.repeat(r.rating) : ''}</small></td>
        <td style="max-width:420px">${E(r.message)}${r.images ? `<br><small>📎 ${r.images} screenshot${r.images > 1 ? 's' : ''}</small>` : ''}</td>
        <td><span class="ad-pill ${tone(r.status)}">${E(STATUS[r.status] || r.status)}</span></td></tr>`).join('')}</tbody>` : '<tbody><tr><td>No reports match these filters.</td></tr></tbody>';
      const pages = Math.max(1, Math.ceil(d.total / d.per));
      $('#fbp', h).innerHTML = d.total ? `<span>${d.total} report${d.total === 1 ? '' : 's'} · page ${d.page} of ${pages}</span> <button class="button secondary small" data-pg="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>← Prev</button> <button class="button secondary small" data-pg="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>Next →</button>` : '';
      tbl.onclick = e => { const tr = e.target.closest('.fb-row'); if (tr) detail(tr.dataset.id, h); };
      tbl.onkeydown = e => { if (e.key === 'Enter') { const tr = e.target.closest('.fb-row'); if (tr) detail(tr.dataset.id, h); } };
      $('#fbp', h).onclick = e => { const b = e.target.closest('[data-pg]'); if (b && !b.disabled) { F.page = +b.dataset.pg; list(h); } };
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  async function detail(id, h) {
    let d;
    try { d = await api('/api/admin/support/' + id); } catch (e) { toast(err(e), true); return; }
    const r = d.report, areas = META.areas, kinds = META.kinds;
    const m = modal(`${areas[r.area] || r.area} — ${kinds[r.kind] || r.kind}`, `
      <dl class="mail-dl" style="display:grid;grid-template-columns:120px 1fr;gap:6px 12px;margin:0 0 12px">
        <dt>From</dt><dd>${E(r.name || 'Visitor')}${r.email ? ` · <a href="mailto:${E(r.email)}?subject=${encodeURIComponent('Your CVTara feedback')}&body=${encodeURIComponent('Hello' + (r.name ? ' ' + r.name : '') + ',\n\nThank you for your feedback:\n\n"' + r.message.slice(0, 300) + '"\n\n')}">${E(r.email)}</a>` : ' (no email left)'}</dd>
        <dt>Plan</dt><dd>${E(r.plan || '—')}${d.earlierFromSamePerson ? ` · ${d.earlierFromSamePerson} earlier report${d.earlierFromSamePerson > 1 ? 's' : ''}` : ''}</dd>
        <dt>Page</dt><dd>${E(r.page || '—')}</dd><dt>Screen</dt><dd>${E(r.viewport || '—')}</dd><dt>Browser</dt><dd style="word-break:break-word">${E(r.user_agent || '—')}</dd>
        <dt>Rating</dt><dd>${r.rating ? '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating) : '—'}</dd><dt>Received</dt><dd>${E(when(r.created_at))}</dd></dl>
      <h4 style="margin:0 0 6px">Message</h4><p style="white-space:pre-wrap;margin:0 0 14px;line-height:1.55">${E(r.message)}</p>
      ${d.images.length ? `<h4 style="margin:0 0 6px">Screenshots (${d.images.length})</h4><div class="fb-shots" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:14px">${d.images.map(i => `<a href="/api/admin/support/file/${E(i.id)}" target="_blank" rel="noopener"><img src="/api/admin/support/file/${E(i.id)}" alt="Screenshot ${i.width}×${i.height}" loading="lazy" style="width:100%;height:110px;object-fit:cover;border:1px solid var(--line);border-radius:8px"></a>`).join('')}</div>` : ''}
      <form id="fbForm" class="ops-form" style="display:grid;gap:10px"><label>Status<select name="status">${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${k === r.status ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>Internal note (customers never see this)<textarea name="adminNote" rows="3" maxlength="3000">${E(r.admin_note || '')}</textarea></label>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" type="submit">Save</button><button class="button secondary" type="button" data-del>Delete report</button></div><p role="status" id="fbMsg"></p></form>`, { wide: true });
    const f = $('#fbForm', m.el);
    f.onsubmit = async e => { e.preventDefault(); try { await api('/api/admin/support/' + id, { method: 'POST', body: { action: 'update', status: f.status.value, adminNote: f.adminNote.value } }); toast('Saved'); m.close(); feedback(); } catch (x) { $('#fbMsg', m.el).textContent = err(x); } };
    f.querySelector('[data-del]').onclick = async () => { if (!confirm('Delete this report and its screenshots? This cannot be undone.')) return; try { await api('/api/admin/support/' + id, { method: 'POST', body: { action: 'delete' } }); toast('Deleted'); m.close(); feedback(); } catch (x) { $('#fbMsg', m.el).textContent = err(x); } };
    feedback.badge = true;
  }

  K.register('feedback', feedback, ['💬', 'Support Inbox', 'Feedback, bug reports and screenshots from customers and visitors.']);
  // Show the number of new reports in the menu without opening the page.
  setTimeout(() => { api('/api/admin/support/summary?days=7').then(s => { const n = document.querySelector('[data-admin-view="feedback"] .nav-count'); if (n && s.byStatus?.new) n.textContent = String(s.byStatus.new); }).catch(() => {}); }, 1500);
})();
