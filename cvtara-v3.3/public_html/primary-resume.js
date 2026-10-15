(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  const $ = (s, r = document) => r.querySelector(s);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const when = d => new Date(String(d).replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  const open = id => { location.href = `/workspace.html?view=builder&draft=${encodeURIComponent(id)}`; };

  async function render() {
    const host = $('#view-resumes');
    if (!host) return;
    host.innerHTML = '<div class="pr-panel"><p>Loading your resumes…</p></div>';
    let list, al;
    try { [list, al] = await Promise.all([api('/api/resumes'), api('/api/allowance')]); } catch (e) { host.innerHTML = `<div class="pr-panel" role="alert">${E(e.message)}</div>`; return; }
    const rows = list.resumes || [];
    const primary = rows.find(r => r.is_primary);
    const variants = rows.filter(r => !r.is_primary);
    const cr = list.credits || { resumes: { used: 0, total: 0, remaining: 0 } };
    const full = rows.length >= list.limit;
    host.innerHTML = `<div class="admin-heading"><div><h2>My resumes</h2><p>Keep one premium primary resume. Create a tailored version for each job description.</p></div><button class="button primary" data-new>+ New resume</button></div>
      <div class="pr-panel"><h2>👑 Primary resume</h2><p>Your master copy — the single source every tailored version starts from.</p>
      ${primary ? `<div class="pr-main"><i>👑</i><div><b>${E(primary.title)}</b><small>Updated ${when(primary.updated_at)} · ${E(primary.template)}</small></div><div class="pr-actions"><button class="button primary small" data-open="${E(primary.id)}">Edit</button><button class="button secondary small" data-export="${E(primary.id)}">Download</button></div></div>`
        : `<div class="pr-main"><i>✨</i><div><b>Create your primary resume</b><small>Pick a template and build your master copy. It is saved as your primary resume automatically.</small></div><button class="button primary small" data-new>Start building</button></div>`}
      </div>
      <div class="pr-panel"><h2>🎯 Job-tailored versions <span class="ad-pill mid">${variants.length}</span></h2><p>Saving versions is free. A clean download of a tailored version uses 1 resume download credit — ${cr.resumes.remaining} of ${cr.resumes.total} left. Your first purchase includes one primary resume download free, once for life.${full ? ' You have reached the saved-draft limit; delete an old draft to save a new one.' : ''}</p>
      <div class="pr-actions"><button class="button primary" data-tailor ${primary && !full ? '' : 'disabled'}>+ Create tailored version</button>${full ? '<button class="button secondary" data-workspace-view="billing">Add more downloads</button>' : ''}</div>
      <form class="pr-form" data-tailor-form><label>Company<input name="company" maxlength="160" required placeholder="e.g. Infosys"></label><label>Job title<input name="role" maxlength="160" required placeholder="e.g. Business Analyst"></label><label>Job description (optional, for your reference)<textarea name="jobDescription" rows="5" maxlength="40000" placeholder="Paste the job description here"></textarea></label><div class="pr-actions"><button class="button primary">Create from my primary resume</button><button class="button secondary" type="button" data-cancel>Cancel</button></div><p role="status" data-status></p></form>
      <div class="pr-list">${variants.length ? variants.map(v => `<div class="pr-row"><div><b>${E(v.title)}</b><small>${E(v.company || '')}${v.company && v.role ? ' · ' : ''}${E(v.role || '')} · updated ${when(v.updated_at)}</small></div><div class="pr-actions"><button class="button secondary small" data-open="${E(v.id)}">Edit</button><button class="button secondary small" data-export="${E(v.id)}">Download</button><button class="button secondary small" data-workspace-view="match">ATS check</button><button class="button secondary small" data-primary="${E(v.id)}">Make primary</button><button class="button secondary small" data-del="${E(v.id)}" style="color:#b91c1c">Delete</button></div></div>`).join('') : '<p style="color:var(--muted)">No tailored versions yet. Create one for your next application.</p>'}</div></div>`;
    $$('[data-new]').forEach(b => b.onclick = () => { location.href = '/workspace.html?view=builder'; });
    $$('[data-open]').forEach(b => b.onclick = () => open(b.dataset.open));
    $$('[data-export]').forEach(b => b.onclick = () => window.CVTaraExport ? window.CVTaraExport('resume', b.dataset.export) : open(b.dataset.export));
    $$('[data-primary]').forEach(b => b.onclick = async () => { try { await api(`/api/resumes/${b.dataset.primary}/primary`, { method: 'POST', body: {} }); render(); } catch (e) { alert(e.message); } });
    $$('[data-del]').forEach(b => b.onclick = async () => { if (!confirm('Delete this resume? This cannot be undone.')) return; try { await api(`/api/resumes/${b.dataset.del}`, { method: 'DELETE' }); render(); } catch (e) { alert(e.message); } });
    const form = $('[data-tailor-form]', host);
    $('[data-tailor]', host).onclick = () => form.classList.add('open');
    $('[data-cancel]', host).onclick = () => form.classList.remove('open');
    form.onsubmit = async e => {
      e.preventDefault();
      const v = Object.fromEntries(new FormData(form)), st = $('[data-status]', form);
      st.textContent = 'Creating…';
      try { const r = await api(`/api/resumes/${primary.id}/variant`, { method: 'POST', body: v }); open(r.id); } catch (err) { st.textContent = err.message; }
    };
  }
  function $$(s, r = document) { return [...r.querySelectorAll(s)]; }
  document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'resumes') render(); });
  addEventListener('load', () => { if (new URLSearchParams(location.search).get('view') === 'resumes') render(); });
})();
