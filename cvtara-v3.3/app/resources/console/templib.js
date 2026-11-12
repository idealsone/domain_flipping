/* Owner console: Template quality (3.59.0). Licence, source, author, version and the text-reading test for every
   resume and cover-letter design; ZIP package import; approve, reject, retire, roll back; coverage per role group. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const when = t => t ? new Date(t).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—';
  const table = (head, rows, empty) => rows.length ? `<div style="overflow:auto"><table class="ad-table"><thead><tr>${head.map(h => `<th>${E(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>` : `<p class="muted">${E(empty)}</p>`;
  const S = { page: 1, per: 20, q: '', kind: 'all', status: 'all', origin: 'all', test: 'all', label: 'all' };
  const CV = { page: 1, per: 20 };
  const qs = (o, keys) => { const q = new URLSearchParams({ page: String(o.page), per: String(o.per) }); keys.forEach(k => { if (o[k] && o[k] !== 'all') q.set(k, o[k]); }); return q; };
  const STATUS = { published: ['Live', 'ok'], draft: ['Draft', 'mid'], rejected: ['Rejected', 'bad'], retired: ['Retired', ''] };
  const test = r => r.testPassed === null ? '<span class="muted">Not tested yet</span>' : r.testPassed ? '<span class="pill ok">Passed</span>' : '<span class="pill bad">Failed</span>';

  async function load() {
    const h = $('#admin-templib'); if (!h) return;
    if (!$('#tlT', h)) {
      h.innerHTML = `<div class="pg-head"><h2>Template quality</h2><p>Every design with its licence, source, author and version. A design shows the ATS label only when it is a single-column, text-first layout and its PDF and Word files were read back with every word, in order, with special characters intact.</p></div>
        <div class="ops-kpis" id="tlK"></div>
        <section class="panel panel-pad"><h3 style="margin-top:0">Import a template package</h3>
          <p class="muted" style="margin-top:0">Upload one ZIP with <code>manifest.json</code> and <code>templates/resume/…</code> or <code>templates/cover-letter/…</code> files. Each design must name a CVTara design to build on (<code>layout_family</code>), and carry licence, source and author. Scripts, macros, remote files and unsafe CSS are refused. Imported designs arrive as drafts and go live only when you approve them.</p>
          <form id="tlUp" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><input type="file" name="package" accept=".zip" required><button class="button primary small">Check and import</button><span class="ops-st" role="status"></span></form><div id="tlRep"></div></section>
        <section class="panel panel-pad"><h3 style="margin-top:0">Designs</h3>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px"><input id="tlQ" type="search" placeholder="Search name, group or author">
            <select id="tlKind"><option value="all">Resumes and letters</option><option value="resume">Resumes</option><option value="letter">Cover letters</option></select>
            <select id="tlSt"><option value="all">Any status</option><option value="published">Live</option><option value="draft">Draft</option><option value="rejected">Rejected</option><option value="retired">Retired</option></select>
            <select id="tlOr"><option value="all">Built in and imported</option><option value="builtin">Built in</option><option value="package">Imported</option></select>
            <select id="tlTe"><option value="all">Any test result</option><option value="passed">Passed</option><option value="failed">Failed</option><option value="untested">Not tested yet</option></select>
            <select id="tlLa"><option value="all">With or without ATS label</option><option value="yes">Shows ATS label</option><option value="no">No ATS label</option></select>
            <a class="button secondary small" id="tlCsv" href="#">Download CSV</a><a class="button secondary small" id="tlXl" href="#">Download Excel</a>
            <button type="button" class="button secondary small" id="tlRun">Test untested designs now</button></div>
          <div id="tlT"></div><div class="ops-pager" id="tlP"></div></section>
        <section class="panel panel-pad"><h3 style="margin-top:0">Coverage by role group</h3><p class="muted" style="margin-top:0">Target: five resume designs and five cover letters for each group. Colour-only changes do not count as new designs.</p>
          <div style="margin-bottom:8px"><a class="button secondary small" id="cvCsv" href="#">Download CSV</a></div><div id="cvT"></div><div class="ops-pager" id="cvP"></div></section>
        <section class="panel panel-pad"><h3 style="margin-top:0">Uploaded packages</h3><div id="pkT"></div></section>`;
      const on = (id, k) => { $(id, h).onchange = e => { S[k] = e.target.value; S.page = 1; list(h); }; };
      on('#tlKind', 'kind'); on('#tlSt', 'status'); on('#tlOr', 'origin'); on('#tlTe', 'test'); on('#tlLa', 'label');
      let t; $('#tlQ', h).oninput = e => { clearTimeout(t); t = setTimeout(() => { S.q = e.target.value.trim(); S.page = 1; list(h); }, 300); };
      $('#tlRun', h).onclick = async e => { e.target.disabled = true; try { const r = await api('/api/admin/template-library/test-due', { method: 'POST', body: {} }); toast(r.tested ? `Tested ${r.tested} design${r.tested === 1 ? '' : 's'}` : 'Every design is already tested'); list(h); } catch (x) { toast(err(x)); } e.target.disabled = false; };
      $('#tlUp', h).onsubmit = async e => {
        e.preventDefault(); const st = $('.ops-st', e.target); st.textContent = 'Checking…';
        try {
          const res = await fetch('/api/admin/template-library/import', { method: 'POST', credentials: 'same-origin', body: new FormData(e.target), headers: { Accept: 'application/json', 'X-CVTara-Request': '1', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } });
          const j = await res.json().catch(() => ({})); if (!res.ok) throw new Error(j.message || j.error || 'Upload failed.');
          const r = j.report; st.textContent = r.ok ? 'Imported as drafts' : 'Not imported';
          $('#tlRep', h).innerHTML = r.ok ? table(['Design', 'Result', 'Version', 'Reading test'], r.templates.map(x => `<tr><td>${E(x.name)}</td><td>${E({ new: 'New draft', new_version: 'New version (draft)', duplicate: 'Duplicate, skipped' }[x.result] || x.result)}</td><td>${E(x.version || '')}</td><td>${x.testPassed === undefined ? '—' : x.testPassed ? '<span class="pill ok">Passed</span>' : '<span class="pill bad">Failed</span> ' + E((x.problems || []).join('; '))}</td></tr>`), 'Nothing in the package.')
            : `<div role="alert"><p><b>The package was not imported.</b> Fix these and upload it again:</p><ul>${r.errors.map(x => `<li>${E(x)}</li>`).join('')}</ul></div>`;
          list(h); packages(h);
        } catch (x) { st.textContent = err(x); }
      };
      h.addEventListener('click', e => { const b = e.target.closest('[data-tl]'); if (b) detail(b.dataset.tl, h); });
    }
    list(h); coverage(h); packages(h);
  }

  async function list(h) {
    const q = qs(S, ['q', 'kind', 'status', 'origin', 'test', 'label']);
    $('#tlCsv', h).href = '/api/admin/template-library?' + q + '&export=csv'; $('#tlXl', h).href = '/api/admin/template-library?' + q + '&export=xlsx';
    try {
      const d = await api('/api/admin/template-library?' + q); const s = d.summary || {};
      $('#tlK', h).innerHTML = `<div class="kpi"><span>Resume designs live</span><b>${s.resumes ?? 0}</b></div><div class="kpi"><span>Cover letters live</span><b>${s.letters ?? 0}</b></div><div class="kpi"><span>Tested</span><b>${s.tested ?? 0}</b></div><div class="kpi"><span>Failed the test</span><b>${s.failed ?? 0}</b></div><div class="kpi"><span>Show the ATS label</span><b>${s.atsReady ?? 0}</b></div>`;
      $('#tlT', h).innerHTML = table(['Design', 'Type', 'Role group', 'Version', 'Licence', 'Author', 'Status', 'Reading test', 'ATS label', ''], d.rows.map(r => `<tr><td>${E(r.name)}<br><small class="muted">${E(r.origin === 'package' ? 'Imported' : 'Built in')}</small></td><td>${r.kind === 'resume' ? 'Resume' : 'Cover letter'}</td><td>${E(r.category)}</td><td>${E(r.version)}</td><td>${E(r.licence)}</td><td>${E(r.author)}</td>
        <td><span class="pill ${(STATUS[r.status] || ['', ''])[1]}">${E((STATUS[r.status] || [r.status])[0])}</span></td><td>${test(r)}${r.testedAt ? '<br><small class="muted">' + E(when(r.testedAt)) + '</small>' : ''}</td><td>${r.kind === 'letter' ? '—' : r.atsLabel ? 'Yes' : r.singleColumn ? (r.atsLayout ? 'No' : 'No (visual design)') : 'No (two columns)'}</td><td><button type="button" class="button secondary small" data-tl="${E(r.slug)}">Open</button></td></tr>`), 'No designs match.');
      K.pager($('#tlP', h), d, (p, per) => { S.page = p; S.per = per; list(h); });
    } catch (e) { $('#tlT', h).innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  async function coverage(h) {
    const q = qs(CV, []); $('#cvCsv', h).href = '/api/admin/template-library/coverage?' + q + '&export=csv';
    try {
      const d = await api('/api/admin/template-library/coverage?' + q);
      $('#cvT', h).innerHTML = table(['Role group', 'Resume designs', 'With ATS label', 'Cover letters', 'Resumes needed', 'Letters needed'], d.rows.map(r => `<tr><td>${E(r.cluster)}</td><td>${E(r.resumes)}</td><td>${E(r.atsReady)}</td><td>${E(r.letters)}</td><td>${r.resumeGap ? `<span class="pill mid">${E(r.resumeGap)}</span>` : '<span class="pill ok">0</span>'}</td><td>${r.letterGap ? `<span class="pill mid">${E(r.letterGap)}</span>` : '<span class="pill ok">0</span>'}</td></tr>`), 'No designs yet.');
      K.pager($('#cvP', h), d, (p, per) => { CV.page = p; CV.per = per; coverage(h); });
    } catch (e) { $('#cvT', h).innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  async function packages(h) {
    try {
      const d = await api('/api/admin/template-library/packages?per=20');
      $('#pkT', h).innerHTML = table(['Uploaded', 'Package', 'Vendor', 'Result', 'Problems'], d.rows.map(p => `<tr><td>${E(when(String(p.created_at).replace(' ', 'T') + 'Z'))}</td><td>${E(p.name)}</td><td>${E(p.vendor)}</td><td>${p.status === 'imported' ? '<span class="pill ok">Imported</span>' : '<span class="pill bad">Not imported</span>'}</td><td>${E(((p.report || {}).errors || []).slice(0, 3).join('; '))}</td></tr>`), 'No packages uploaded yet.');
    } catch (e) { $('#pkT', h).innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  async function detail(slug, h) {
    let t; try { t = (await api('/api/admin/template-library/' + encodeURIComponent(slug))).template; } catch (e) { toast(err(e)); return; }
    const ts = t.test || {}; const c = ts.checks || {};
    const chk = f => c[f] ? `${c[f].ok ? '<span class="pill ok">OK</span>' : '<span class="pill bad">Problem</span>'} ${E(c[f].characters)} characters read${c[f].missing && c[f].missing.length ? ', missing: ' + E(c[f].missing.join(', ')) : ''}${c[f].sectionOrder === false ? ', sections out of order' : ''}` : 'Not tested yet';
    const m = modal(t.name, `<div class="ops-form">
      <p><b>${t.kind === 'resume' ? 'Resume' : 'Cover letter'}</b>, version ${E(t.version)}, ${E(t.origin === 'package' ? 'imported' : 'built in')}${t.base && t.base !== t.slug ? ', built on ' + E(t.base) : ''}. Status: ${E((STATUS[t.status] || [t.status])[0])}.</p>
      <p>Licence: ${E(t.licence)}<br>Source: ${E(t.source)}<br>Author: ${E(t.author)}<br>Exports: ${E((t.formats || []).join(', '))}</p>
      <p><b>Reading test</b> (demo data)${ts.checkedAt ? ', ' + E(when(ts.checkedAt)) : ''}<br>PDF: ${chk('pdf')}<br>Word: ${chk('docx')}<br>Layout: ${t.singleColumn ? 'single column' : 'two columns (no ATS label)'}</p>
      ${(ts.problems || []).length ? `<p role="alert">${E(ts.problems.join('; '))}</p>` : ''}
      ${ts.sample ? `<details><summary>Text read back from the PDF</summary><pre style="white-space:pre-wrap;font-size:.8em">${E(ts.sample)}</pre></details>` : ''}
      <p><a class="button secondary small" href="/api/admin/template-library/${encodeURIComponent(t.slug)}/preview" target="_blank" rel="noopener">Preview with demo data</a>
        <button type="button" class="button secondary small" data-a="test">Run the test again</button>
        ${t.origin === 'package' && t.status !== 'published' && t.status !== 'retired' ? '<button type="button" class="button primary small" data-a="approve">Approve and publish</button>' : ''}
        ${t.status === 'draft' ? '<button type="button" class="button secondary small" data-a="reject">Reject</button>' : ''}
        ${t.origin === 'package' && t.status === 'published' ? '<button type="button" class="button secondary small" data-a="retire">Retire</button>' : ''}</p>
      ${(t.versions || []).length ? `<h4>Versions</h4>${table(['Version', 'Content', 'Added', ''], t.versions.map(v => `<tr><td>${E(v.version)}</td><td><code>${E(v.hash)}</code></td><td>${E(when(v.at))}</td><td>${v.version !== t.version ? `<button type="button" class="button secondary small" data-rb="${E(v.version)}">Roll back to this</button>` : 'Current'}</td></tr>`), '')}` : ''}
      <span class="ops-st" role="status"></span></div>`);
    const st = $('.ops-st', m.el || m.body);
    const act = async (body, url) => { st.textContent = 'Working…'; try { await api(url, { method: 'POST', body }); toast('Done'); m.close(); list(h); coverage(h); } catch (x) { st.textContent = err(x); } };
    (m.el || m.body).querySelectorAll('[data-a]').forEach(b => b.onclick = () => b.dataset.a === 'test' ? act({}, `/api/admin/template-library/${encodeURIComponent(t.slug)}/test`) : act({ action: b.dataset.a }, `/api/admin/template-library/${encodeURIComponent(t.slug)}/decide`));
    (m.el || m.body).querySelectorAll('[data-rb]').forEach(b => b.onclick = () => { if (confirm('Make version ' + b.dataset.rb + ' current again? It becomes a draft until you approve it.')) act({ action: 'rollback', version: Number(b.dataset.rb) }, `/api/admin/template-library/${encodeURIComponent(t.slug)}/decide`); });
  }

  K.register('templib', load, ['🧪', 'Template quality', 'Licence, version and the text-reading test for every design; package import; coverage per role group.']);
})();
