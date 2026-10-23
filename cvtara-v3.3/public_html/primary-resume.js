(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const when = d => new Date(String(d).replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  const open = id => { location.href = `/workspace.html?view=builder&draft=${encodeURIComponent(id)}`; };
  const level = n => n >= 85 ? ['good', 'Excellent'] : n >= 70 ? ['good', 'Good'] : n >= 50 ? ['mid', 'Needs work'] : ['low', 'Low'];
  const ring = (n, label = 'ATS-friendly') => { const [c] = level(n); return `<div class="rh-ring ${c}" style="--p:${n}" role="img" aria-label="${n} percent ${label}"><b>${n}%</b><span>${label}</span></div>`; };

  // ---- Upload an existing resume (PDF, Word, text) and turn it into an editable draft --------------------------------
  const importer = {
    input: null,
    pick() {
      if (!this.input) {
        this.input = Object.assign(document.createElement('input'), { type: 'file', accept: '.pdf,.docx,.doc,.txt,.md,text/plain,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document', hidden: true });
        this.input.addEventListener('change', () => { const f = this.input.files[0]; this.input.value = ''; if (f) this.run(f); });
        document.body.append(this.input);
      }
      this.input.click();
    },
    dialog(html) {
      let d = $('#rhDialog');
      if (!d) { d = document.createElement('dialog'); d.id = 'rhDialog'; d.className = 'rh-dialog'; document.body.append(d); d.addEventListener('click', e => { if (e.target.closest('[data-rh-close]')) d.close(); }); }
      d.innerHTML = html; if (!d.open) d.showModal(); return d;
    },
    async run(file) {
      this.dialog('<div class="rh-dlg"><h3>Reading your resume…</h3><p>This takes a few seconds. We never share your file.</p><div class="rh-spin" aria-hidden="true"></div></div>');
      try {
        if (!window.CVTaraImport) throw Error('The document reader did not load. Refresh the page and try again.');
        const text = await window.CVTaraImport.readFile(file);
        await this.send(text, file.name);
      } catch (e) { this.failed(e); }
    },
    async send(text, fileName, replaceId) {
      try {
        const r = await api('/api/resumes/import', { method: 'POST', body: { text: text.slice(0, 120000), fileName, paragraphs: window.CVTaraImport?.layout === 'rows', ...(replaceId ? { replaceId } : {}) } });
        const f = r.found || {}, q = r.quality || { score: 0, tips: [] };
        const rows = [['Jobs', f.experience], ['Education', f.education], ['Projects', f.projects], ['Skills', f.skills], ['Certificates', f.certifications]].filter(x => x[1]);
        this.dialog(`<div class="rh-dlg"><h3>Your resume is ready to review</h3>
          <div class="rh-sum">${ring(q.score)}<ul class="rh-found">${rows.map(([k, n]) => `<li><b>${n}</b> ${k}</li>`).join('') || '<li>No sections detected</li>'}</ul></div>
          ${(r.warnings || []).length ? `<div class="rh-warn" role="status"><b>Please check:</b><ul>${r.warnings.map(w => `<li>${E(w)}</li>`).join('')}</ul></div>` : ''}
          ${q.tips.length ? `<div class="rh-tips"><b>To score higher</b><ul>${q.tips.map(t => `<li>${E(t)}</li>`).join('')}</ul></div>` : '<p class="rh-ok">Nicely done. Your resume is ATS-friendly.</p>'}
          <p class="rh-note">We read the text of your file and filled the builder. Pick a template, check every section and download when you are happy.</p>
          <div class="rh-actions"><button class="button primary" data-rh-edit="${E(r.id)}">Review and edit</button><button class="button secondary" data-rh-close>Later</button></div></div>`);
        $('[data-rh-edit]', $('#rhDialog')).onclick = () => open(r.id);
        document.dispatchEvent(new CustomEvent('cvtara-resume-saved'));
      } catch (e) {
        if (e.data && e.data.needsReplace) return this.replace(e.data, text, fileName);
        this.failed(e);
      }
    },
    // The file was read, but the plan has no room for another resume: let the customer put it into one they already have.
    replace(d, text, fileName) {
      const list = d.resumes || [];
      const pick = list.length > 1 ? `<label class="rh-field">Resume to replace<select id="rhReplace">${list.map(x => `<option value="${E(x.id)}">${E(x.title)} · ${E(when(x.updatedAt))}</option>`).join('')}</select></label>` : list.length ? `<p class="rh-note">This replaces <b>${E(list[0].title)}</b>. Its old text cannot be brought back.</p>` : '';
      this.dialog(`<div class="rh-dlg"><h3>We read your file. Where should it go?</h3><p>${E(d.error)}</p>${pick}
        <div class="rh-actions"><button class="button primary" data-rh-replace>Use this file instead</button>${d.paid ? '' : '<a class="button secondary" href="/workspace.html?view=billing">See plans</a>'}<button class="button secondary" data-rh-close>Cancel</button></div></div>`);
      $('[data-rh-replace]', $('#rhDialog')).onclick = () => { const id = ($('#rhReplace') || {}).value || (list[0] && list[0].id); this.dialog('<div class="rh-dlg"><h3>Updating your resume…</h3><div class="rh-spin" aria-hidden="true"></div></div>'); this.send(text, fileName, id); };
    },
    failed(e) {
      this.dialog(`<div class="rh-dlg"><h3>We could not import that file</h3><p role="alert">${E(e.message)}</p><p class="rh-note">You can also start from a template and type your details.</p><div class="rh-actions"><button class="button primary" data-rh-again>Try another file</button><button class="button secondary" data-rh-close>Close</button></div></div>`);
      $('[data-rh-again]', $('#rhDialog')).onclick = () => { $('#rhDialog').close(); this.pick(); };
    },
  };
  window.CVTaraResumeImport = importer;

  // The builder's template step offers the same upload.
  function builderUpload() {
    const h = $('#studioTemplateStage .studio-chooser-heading');
    if (!h || $('#rhBuilderUpload')) return;
    const b = Object.assign(document.createElement('button'), { type: 'button', id: 'rhBuilderUpload', className: 'button secondary rh-upload-link', textContent: '📤 Already have a resume? Upload it' });
    b.onclick = () => importer.pick(); h.append(b);
  }
  addEventListener('load', builderUpload); setTimeout(builderUpload, 600);

  // ---- Live ATS-friendly score in the builder -------------------------------------------------------------------------
  function live(ats) {
    const bar = $('.studio-actions'); if (!bar || !ats || ats.score == null) return;
    let el = $('#rhLive'); if (!el) { el = document.createElement('span'); el.id = 'rhLive'; el.setAttribute('role', 'status'); bar.prepend(el); }
    const [c, w] = level(ats.score);
    el.className = 'rh-live ' + c; el.textContent = `ATS-friendly ${ats.score}%`;
    el.title = ats.score >= 70 ? `${w}. ` + (ats.tips && ats.tips.length ? 'To go higher: ' + ats.tips[0] : 'Nicely done.') : 'To reach 70%+: ' + (ats.tips || []).slice(0, 2).join(' ');
  }
  document.addEventListener('cvtara-resume-saved', e => live(e.detail && e.detail.ats));
  addEventListener('load', async () => { const d = new URLSearchParams(location.search).get('draft'); if (!d) return; try { const q = await api(`/api/resumes/${encodeURIComponent(d)}/quality`); live({ score: q.quality.score, tips: q.quality.tips }); } catch { /* optional */ } });

  // ---- My resumes ---------------------------------------------------------------------------------------------------
  const labelOf = r => r.is_primary ? 'Primary resume' : `Resume ${r.number}`;
  const tagOf = r => r.source === 'import' ? '<span class="rh-tag">Uploaded</span>' : r.source === 'tailored' ? '<span class="rh-tag">Tailored</span>' : '';

  function card(r) {
    const n = r.ats_score ?? 0, [cls, word] = level(n), m = r.match;
    return `<article class="rh-card ${r.is_primary ? 'is-primary' : ''}" data-id="${E(r.id)}">
      ${ring(n)}
      <div class="rh-body">
        <div class="rh-top"><span class="rh-label">${r.is_primary ? '👑 ' : ''}${E(labelOf(r))}</span>${tagOf(r)}${r.downloaded_at ? '<span class="rh-tag lock" title="Downloaded resumes stay in your account">🔒 Downloaded</span>' : ''}</div>
        <h3>${E(r.title)}</h3>
        <small>${E(r.template)} · updated ${when(r.updated_at)}${r.company ? ' · ' + E(r.company) : ''}${r.role ? ' · ' + E(r.role) : ''}</small>
        <div class="rh-chips"><span class="rh-chip ${cls}">${word} for ATS · ${n}%</span>${m ? `<span class="rh-chip ${level(m.score)[0]}" title="Latest ATS Match">Job match ${m.score}%${m.company || m.role ? ' · ' + E(m.company || m.role) : ''}</span>${m.count > 1 ? `<span class="rh-chip">Best ${m.best}% over ${m.count} checks</span>` : ''}` : '<span class="rh-chip">Not matched to a job yet</span>'}</div>
        <details class="rh-more"><summary>What affects this score?</summary><div class="rh-detail" data-detail>Loading…</div></details>
        <div class="rh-actions">
          <button class="button primary small" data-open="${E(r.id)}">Edit</button>
          <button class="button secondary small" data-export="${E(r.id)}">Download</button>
          <button class="button secondary small" data-ats="${E(r.id)}">ATS check</button>
          ${r.is_primary ? '' : `<button class="button secondary small" data-primary="${E(r.id)}">Make primary</button>`}
        </div>
      </div></article>`;
  }

  async function detail(box) {
    if (box.dataset.loaded) return; box.dataset.loaded = '1';
    try {
      const id = box.closest('[data-id]').dataset.id, { quality: q } = await api(`/api/resumes/${encodeURIComponent(id)}/quality`);
      box.innerHTML = `<p class="rh-explain">This score checks how easily a tracking system reads your resume and whether it has what recruiters look for. It does not depend on one job. For a job-specific score use <b>ATS check</b>.</p>
        <ul class="rh-checks">${q.checks.map(c => `<li><span>${E(c.label)}</span><i style="--w:${Math.round(100 * c.got / c.max)}%"></i><em>${c.got}/${c.max}</em>${c.tip ? `<small>${E(c.tip)}</small>` : ''}</li>`).join('')}</ul>`;
    } catch (e) { box.textContent = e.message; delete box.dataset.loaded; }
  }

  async function render() {
    const host = $('#view-resumes');
    if (!host) return;
    host.innerHTML = '<div class="pr-panel"><p>Loading your resumes…</p></div>';
    let list;
    try { list = await api('/api/resumes'); } catch (e) { host.innerHTML = `<div class="pr-panel" role="alert">${E(e.message)}</div>`; return; }
    const rows = (list.resumes || []).slice().sort((a, b) => (b.is_primary ? 1 : 0) - (a.is_primary ? 1 : 0) || a.number - b.number);
    const primary = rows.find(r => r.is_primary), cr = list.credits || { resumes: { used: 0, total: 0, remaining: 0 } };
    const full = rows.length >= list.limit;
    host.innerHTML = `<div class="admin-heading"><div><h2>My resumes</h2><p>Every resume you make here shows how ATS-friendly it is. Edit it, download it, or check it against a job.</p></div></div>
      <div class="rh-start" role="group" aria-label="Create a resume">
        <button class="rh-opt" data-upload><i>📤</i><b>Upload your resume</b><small>Word, PDF or text. We fill the builder for you.</small></button>
        <button class="rh-opt" data-new><i>🎨</i><b>Choose a template</b><small>Pick a design, add your details, download.</small></button>
        <button class="rh-opt" data-tailor ${primary && !full ? '' : 'disabled'}><i>🎯</i><b>Tailor for a job</b><small>Copy your primary resume for one company.</small></button>
      </div>
      <form class="pr-form" data-tailor-form><label>Company<input name="company" maxlength="160" required placeholder="e.g. Infosys"></label><label>Job title<input name="role" maxlength="160" required placeholder="e.g. Business Analyst"></label><label>Job description (optional, for your reference)<textarea name="jobDescription" rows="5" maxlength="40000" placeholder="Paste the job description here"></textarea></label><div class="pr-actions"><button class="button primary" type="submit">Create tailored version</button><button class="button secondary" type="button" data-cancel>Cancel</button></div><p role="status" data-status></p></form>
      <p class="rh-count">${rows.length}${list.limit < 500 ? ' of ' + list.limit : ''} saved · A clean download of a tailored version uses 1 resume download credit (${cr.resumes.remaining} of ${cr.resumes.total} left). Your first purchase includes one primary resume download free, once for life.${full ? ' You have reached the saved-resume limit for your plan.' : ''}</p>
      <div class="rh-list">${rows.length ? rows.map(card).join('') : '<div class="pr-panel"><h2>No resume yet</h2><p>Upload the resume you already have, or choose a template and build one. It becomes your primary resume.</p></div>'}</div>
      <p class="rh-keep">🔒 Saved resumes cannot be deleted, so your download credits always stay accurate. You can edit any resume at any time.</p>`;
    $$('[data-upload]', host).forEach(b => b.onclick = () => importer.pick());
    $$('[data-new]', host).forEach(b => b.onclick = () => { location.href = '/workspace.html?view=builder'; });
    $$('[data-open]', host).forEach(b => b.onclick = () => open(b.dataset.open));
    $$('[data-export]', host).forEach(b => b.onclick = () => window.CVTaraExport ? window.CVTaraExport('resume', b.dataset.export) : open(b.dataset.export));
    $$('[data-ats]', host).forEach(b => b.onclick = () => { sessionStorage.setItem('cvtara-ats-resume', b.dataset.ats); const nav = document.querySelector('[data-workspace-view="match"]'); if (nav) { nav.click(); setTimeout(() => window.CVTaraAts?.select(b.dataset.ats), 150); } else location.href = '/workspace.html?view=match'; });
    $$('[data-primary]', host).forEach(b => b.onclick = async () => { try { await api(`/api/resumes/${b.dataset.primary}/primary`, { method: 'POST', body: {} }); render(); } catch (e) { alert(e.message); } });
    $$('.rh-more', host).forEach(d => d.addEventListener('toggle', () => { if (d.open) detail($('[data-detail]', d)); }));
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
  document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'resumes') render(); });
  addEventListener('load', () => { if (new URLSearchParams(location.search).get('view') === 'resumes') render(); });
})();
