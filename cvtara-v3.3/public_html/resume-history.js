/* Resume version history (3.52.0). "History" on a resume card lists earlier versions (every save keeps the previous
   content; the copy used for a job application is frozen) and lets the customer look at one or put it back. */
(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace') return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const when = s => { const d = new Date(String(s).replace(' ', 'T') + 'Z'); return isNaN(d) ? '' : d.toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  async function api(path, body) {
    const o = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (body !== undefined) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.headers['X-CSRF-TOKEN'] = await window.CVTaraCSRF(); o.body = JSON.stringify(body); }
    const r = await fetch('/api/' + path, o); let j = {}; try { j = await r.json(); } catch { /* not JSON */ }
    if (!r.ok || j.ok === false) throw Error(j.error || j.message || 'That did not work. Please try again.');
    return j;
  }
  const KIND = { edit: 'Before an edit', application: 'Sent with an application', restore: 'Before a restore' };
  const text = d => [d.personal?.name, d.personal?.role, d.summary, ...(d.experience || []).map(x => [x.role, x.company].filter(Boolean).join(' at ') + '\n' + (x.bullets || '')), d.skills].filter(Boolean).join('\n\n');
  let dlg;
  function dialog(html) {
    if (!dlg) { dlg = document.createElement('dialog'); dlg.className = 'export-dialog'; dlg.style.maxWidth = '720px'; document.body.append(dlg); dlg.addEventListener('click', e => { if (e.target === dlg || e.target.closest('[data-rv-close]')) dlg.close(); }); }
    dlg.innerHTML = html + '<p style="margin-top:14px"><button class="button secondary" data-rv-close>Close</button></p>';
    if (!dlg.open) dlg.showModal();
  }
  async function list(rid) {
    dialog('<p>Loading versions…</p>');
    try {
      const { versions } = await api(`resumes/${rid}/versions`);
      dialog(`<h2 style="margin-top:0">Version history</h2><p class="muted">Every time you save, the version before it is kept here (the last 30 edits). The copy you used for a job application is kept as it was, even after you edit the resume.</p>
        ${versions.length ? `<ul style="list-style:none;padding:0;margin:0">${versions.map(v => `<li style="padding:10px 0;border-top:1px solid var(--line,#e2e8f0)"><b>${E(when(v.savedAt))}</b> · ${E(KIND[v.kind] || v.kind)}${v.usedFor ? ` (${E(v.usedFor)})` : ''}<br><small>${E(v.title)}</small>
          <div style="display:flex;gap:8px;margin-top:6px"><button class="button secondary small" data-rv-view="${E(v.id)}" data-rid="${E(rid)}">View</button><button class="button secondary small" data-rv-restore="${E(v.id)}" data-rid="${E(rid)}">Put this version back</button></div></li>`).join('')}</ul>` : '<p>No earlier versions yet. They appear after your next save.</p>'}`);
    } catch (e) { dialog(`<p role="alert">${E(e.message)}</p>`); }
  }
  document.addEventListener('click', async e => {
    const h = e.target.closest('[data-history]'); if (h) { e.preventDefault(); list(h.dataset.history); return; }
    const v = e.target.closest('[data-rv-view]');
    if (v) {
      try { const { version } = await api(`resumes/${v.dataset.rid}/versions/${v.dataset.rvView}`); dialog(`<h2 style="margin-top:0">Saved ${E(when(version.savedAt))}</h2><pre style="white-space:pre-wrap;font:inherit;max-height:60vh;overflow:auto">${E(text(version.data || {}))}</pre><p><button class="button secondary small" data-history="${E(v.dataset.rid)}">Back to the list</button></p>`); } catch (err) { alert(err.message); }
      return;
    }
    const r = e.target.closest('[data-rv-restore]');
    if (r) {
      if (!confirm('Put this version back? What you have now is kept in the history, so you can undo this.')) return;
      r.disabled = true;
      try { await api(`resumes/${r.dataset.rid}/versions/${r.dataset.rvRestore}/restore`, {}); dlg.close(); location.href = '/workspace.html?view=builder&draft=' + encodeURIComponent(r.dataset.rid); } catch (err) { r.disabled = false; alert(err.message); }
    }
  });
})();
