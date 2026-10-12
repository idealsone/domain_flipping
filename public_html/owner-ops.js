(() => {
'use strict';
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const api = (p, o) => window.CVTaraAPI.request(p, o);

function csvDownload(name, headers, rows) {
  const cell = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
  const body = [headers.map(cell).join(','), ...rows.map(r => r.map(cell).join(','))].join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([body], {type: 'text/csv;charset=utf-8'}));
  a.download = name + '-' + new Date().toISOString().slice(0, 10) + '.csv';
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

function bindTable(table, filename) {
  if (!table || table.dataset.opsBound) return;
  table.dataset.opsBound = '1';
  const wrap = table.closest('.owner-table-scroll') || table.parentElement;
  if (!wrap || wrap.querySelector('[data-ops-toolbar]')) return;
  const bar = document.createElement('div');
  bar.className = 'ops-toolbar';
  bar.dataset.opsToolbar = '1';
  bar.innerHTML = `<input type="search" placeholder="Filter this table" aria-label="Filter table"><button type="button" class="button secondary small">Export CSV</button><small></small>`;
  wrap.insertBefore(bar, wrap.firstChild === table ? table : table);
  if (bar.nextSibling !== table) wrap.insertBefore(table, bar.nextSibling);
  const search = bar.querySelector('input');
  const note = bar.querySelector('small');
  const rows = () => [...table.tBodies[0]?.rows || []];
  const paint = () => {
    const q = search.value.trim().toLowerCase();
    let n = 0;
    rows().forEach(r => { const hide = q && !r.textContent.toLowerCase().includes(q); r.hidden = hide; if (!hide) n++; });
    note.textContent = n + ' row' + (n === 1 ? '' : 's');
  };
  search.addEventListener('input', paint);
  bar.querySelector('button').onclick = () => {
    const heads = [...table.tHead?.rows[0]?.cells || []].map(c => c.textContent.trim());
    const data = rows().filter(r => !r.hidden).map(r => [...r.cells].map(c => c.innerText.replace(/\s+/g, ' ').trim()));
    csvDownload(filename, heads, data);
  };
  paint();
}

function bindAllTables() {
  const names = { 'admin-users': 'users', 'admin-commerce': 'purchases', 'admin-templates': 'resume-templates', 'admin-letters': 'cover-letters', 'admin-blog': 'pages', 'admin-reviews': 'feedback', 'admin-emails': 'mail-jobs', 'admin-analytics': 'activity', 'admin-keywords': 'keywords', 'admin-seo': 'seo-pages', 'admin-overview': 'overview', 'admin-funnel': 'funnel' };
  $$('.admin-table').forEach(t => bindTable(t, names[t.closest('.admin-view')?.id] || 'cvtara-export'));
}

function liveLibraries() {
  const D = window.CVTARA_DATA || {};
  const lib = window.CVTaraOwnerContext?.states?.library?.data || {templates: {}, letters: {}};
  const templates = (D.templates || []).map(t => {
    const o = lib.templates?.[t.slug] || {};
    return [t.name || o.name, t.slug, t.category || o.category || '—', t.ats || o.ats ? 'ATS' : 'Styled', o.published === false ? 'Hidden' : 'Published', t.accent || o.accent || '—'];
  });
  const letters = (D.coverLetters || []).map(l => {
    const o = lib.letters?.[l.slug] || {};
    return [l.name || o.name, l.slug, l.role || o.role || '—', l.market || o.market || '—', l.tone || o.tone || '—', o.published === false ? 'Hidden' : 'Published'];
  });
  const box = (id, title, heads, rows) => {
    const host = $('#admin-' + id);
    if (!host || host.querySelector('[data-ops-library]')) return;
    const sec = document.createElement('section');
    sec.className = 'panel panel-pad';
    sec.dataset.opsLibrary = '1';
    sec.innerHTML = `<div class="admin-heading"><div><span class="eyebrow">LIVE LIBRARY</span><h3>${title}</h3><p>This is the same catalogue customers see on the website and in the workspace.</p></div></div><div class="owner-table-scroll"><table class="admin-table"><thead><tr>${heads.map(h => '<th>' + E(h) + '</th>').join('')}</tr></thead><tbody>${rows.map(r => '<tr>' + r.map(v => '<td>' + E(v) + '</td>').join('') + '</tr>').join('')}</tbody></table></div>`;
    host.prepend(sec);
  };
  box('templates', 'Resume templates on the customer site', ['Name', 'Key', 'Category', 'ATS', 'Status', 'Accent'], templates);
  box('letters', 'Cover letters on the customer site', ['Name', 'Key', 'Role', 'Market', 'Tone', 'Status'], letters);
}

function polishOverview() {
  const head = $('#admin-overview .admin-heading h2');
  if (head) head.textContent = 'Owner dashboard';
  const p = $('#admin-overview .admin-heading p');
  if (p) p.textContent = 'Live counts from customer accounts, purchases, drafts and activity. Filter any table, then export the visible rows.';
  const title = $('#adminTitle');
  if (title && /Business overview|One connected/i.test(title.textContent)) title.textContent = 'Owner dashboard';
  document.title = 'Owner console | CVTara';
}

function mailGrok() {
  if ($('#draftGrok') || $('[data-ops-grok]')) return;
  const host = $('#admin-emails');
  if (!host) return;
  const box = document.createElement('section');
  box.className = 'panel panel-pad';
  box.dataset.opsGrok = '1';
  box.innerHTML = `<div class="admin-heading"><div><span class="eyebrow">GROK</span><h3>Draft a mail template</h3><p>Add your xAI key under Integrations → AI. Grok writes a starting draft. You review and save. Nothing is sent until an enabled, consented rule fires. Grok does not send cold lists, warming mail or blasts.</p></div></div><form class="owner-form" id="opsGrokForm"><label>What should this email do?<input name="purpose" required maxlength="400" placeholder="e.g. Confirm a verified purchase and point the customer to Account & purchases"></label><div class="owner-actions"><button class="button primary" type="submit">Draft with Grok</button><p role="status"></p></div></form>`;
  host.append(box);
  box.querySelector('form').onsubmit = async e => {
    e.preventDefault();
    const f = e.target, status = f.querySelector('[role=status]'), button = f.querySelector('[type=submit]');
    button.disabled = true; status.textContent = 'Drafting with Grok…';
    try {
      const editor = $('#emailEditor form');
      const d = await api('/api/admin/ai/mail-draft', {method: 'POST', body: {
        purpose: f.elements.purpose.value,
        subject: editor?.elements.subject?.value || '',
        body: editor?.elements.body?.value || '',
        trigger: editor?.elements.trigger?.value || ''
      }});
      if (editor) {
        if (editor.elements.subject) editor.elements.subject.value = d.subject || '';
        if (editor.elements.body) editor.elements.body.value = d.body || '';
      }
      status.textContent = (d.note || 'Draft ready.') + (d.source === 'grok' ? ' Grok filled the open template. Review it, then click Save template.' : '');
    } catch (err) { status.textContent = err.message; }
    finally { button.disabled = false; }
  };
}

function handbookNav() {
  const nav = $('.admin-side .side-nav');
  if (!nav || nav.querySelector('[data-ops-handbook]')) return;
  const a = document.createElement('a');
  a.className = 'button secondary';
  a.dataset.opsHandbook = '1';
  a.href = '/owner-handbook';
  a.textContent = 'Owner handbook';
  nav.after(a);
}

function enhance() {
  $$('.connected-preview-label').forEach(n => n.remove());
  polishOverview();
  liveLibraries();
  mailGrok();
  handbookNav();
  bindAllTables();
}
function start() {
  if (document.body.dataset.page !== 'admin') return;
  document.body.dataset.ownerOpsReady = '1';
  enhance();
}

const wait = () => {
  if (document.body.dataset.ownerAdvancedReady || window.CVTaraOwnerContext) start();
  else setTimeout(wait, 120);
};
document.addEventListener('cvtara-owner-context-ready', () => setTimeout(start, 400));
document.addEventListener('cvtara-admin-ready', () => setTimeout(wait, 200));
if (window.CVTaraAdminReady) setTimeout(wait, 200);
setTimeout(start, 800);
setTimeout(enhance, 1800);
setTimeout(enhance, 3500);
})();
