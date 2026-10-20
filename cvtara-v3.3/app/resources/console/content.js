/* Owner console: Resume Library, CL Library, Templates & site design, User Mailer. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api, parse, dmy, pill, modal, toast, download, csv } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const saveContent = async (key, data, revision) => api('/api/admin/content/' + key, { method: 'POST', body: { data, revision } });
  const dtm = d => { const x = parse(d); return isNaN(x) ? '' : x.toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };

  /* ======================= LIBRARIES (resume + cover letter) ======================= */
  const LIB = { templates: { q: '', cat: 'all', st: 'all', aud: 'all' }, letters: { q: '', cat: 'all', st: 'all', aud: 'all' } };
  async function library(kind) {
    const isT = kind === 'templates', host = $(isT ? '#admin-resumelib' : '#admin-letterlib'); if (!host) return;
    const D = window.CVTARA_DATA; if (!D) { host.innerHTML = '<div class="panel panel-pad">Library data is not loaded.</div>'; return; }
    const items = isT ? D.templates : D.coverLetters, S = LIB[kind];
    let saved;
    try { saved = await api('/api/admin/content/library'); } catch (e) { host.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const overrides = () => (saved.data[kind] || {});
    const isPub = it => (overrides()[it.slug] || {}).published !== false && it.publicPublished !== false;
    const cats = [...new Set(items.map(i => (isT ? i.category : i.role) || 'Other'))].sort();
    host.innerHTML = `<div class="pg-head"><h2>${isT ? 'Resume Library' : 'CL Library'}</h2><p>${isT ? 'Every resume design customers can pick. Click a card to preview the full document; use Edit to change its details.' : 'Cover-letter designs and sample letters. Click a card to read the full letter.'}</p></div>
      <div class="ops-kpis" id="lk"></div>
      <section class="panel panel-pad ops-filterbox"><label class="ops-f grow">Search<input id="lq" type="search" placeholder="Search by name or category" value="${E(S.q)}"></label><label class="ops-f">${isT ? 'Category' : 'Role'}<select id="lc"><option value="all">All</option>${cats.map(c => `<option ${S.cat === c ? 'selected' : ''}>${E(c)}</option>`).join('')}</select></label><label class="ops-f">Candidate type<select id="la"><option value="all">All</option>${(D.audiences || []).map(a => `<option value="${E(a.id)}" ${S.aud === a.id ? 'selected' : ''}>${E(a.name)}</option>`).join('')}<option value="none" ${S.aud === 'none' ? 'selected' : ''}>Not set</option></select></label><label class="ops-f">Status<select id="ls"><option value="all">All</option><option value="live" ${S.st === 'live' ? 'selected' : ''}>Published</option><option value="hidden" ${S.st === 'hidden' ? 'selected' : ''}>Hidden</option></select></label></section>
      <div class="lib-grid" id="lg"></div>`;
    const preview = (it) => {
      try {
        if (isT) { const R = window.CVTaraReferenceTemplates; return R ? R.render(it, R.sample(it), { sample: true }) : ''; }
        const P = window.CVTaraPremium; return P ? P.renderLetter(P.letterSample(it), { sample: true }) : '';
      } catch (e) { return ''; }
    };
    const io = 'IntersectionObserver' in window ? new IntersectionObserver(es => es.forEach(en => { if (en.isIntersecting) { const el = en.target; io.unobserve(el); el.innerHTML = preview(items.find(i => i.slug === el.dataset.slug)); } }), { rootMargin: '300px' }) : null;
    const draw = () => {
      const rows = items.filter(it => { const c = (isT ? it.category : it.role) || 'Other'; return (S.cat === 'all' || c === S.cat) && (S.aud === 'all' || (S.aud === 'none' ? !it.audience : it.audience === S.aud)) && (S.st === 'all' || (S.st === 'live') === isPub(it)) && (!S.q || (it.name + ' ' + c).toLowerCase().includes(S.q.toLowerCase())); });
      $('#lk', host).innerHTML = `<div class="kpi"><span>Total</span><b>${items.length}</b></div><div class="kpi"><span>Published</span><b>${items.filter(isPub).length}</b></div>${isT ? `<div class="kpi"><span>ATS-conscious</span><b>${items.filter(i => i.ats).length}</b></div>` : ''}<div class="kpi"><span>${isT ? 'Categories' : 'Roles'}</span><b>${cats.length}</b></div><div class="kpi"><span>Showing</span><b>${rows.length}</b></div>`;
      $('#lg', host).innerHTML = rows.map(it => `<article class="lib-card ${isPub(it) ? '' : 'off'}"><button type="button" class="${isT ? 'template-preview-button' : 'lib-letter-btn'}" data-open="${E(it.slug)}" aria-label="Preview ${E(it.name)}"><div class="lib-thumb ${isT ? 'template-preview' : 'cover-letter-preview'}" data-slug="${E(it.slug)}"></div>${isT ? '<span>Preview resume</span>' : ''}</button><div class="lib-meta"><b>${E(it.name)}</b><small>${E((isT ? it.category : it.role + ' · ' + it.tone) || '')}</small><div class="lib-tags">${isT && it.ats ? '<span class="tag tag-blue">ATS</span>' : ''}${it.audience ? `<span class="tag">${E((D.audiences || []).find(a => a.id === it.audience)?.name || it.audience)}</span>` : ''}${isPub(it) ? '<span class="pill st-paid">Published</span>' : '<span class="pill st-free">Hidden</span>'}</div><div class="lib-act"><button class="button small primary" data-open="${E(it.slug)}">Preview</button><button class="button small secondary" data-edit="${E(it.slug)}">Edit</button></div></div></article>`).join('') || '<div class="panel panel-pad">Nothing matches.</div>';
      $$('.lib-thumb', host).forEach(el => { if (io) io.observe(el); else el.innerHTML = preview(items.find(i => i.slug === el.dataset.slug)); });
    };
    $('#lq', host).oninput = e => { S.q = e.target.value; draw(); }; $('#lc', host).onchange = e => { S.cat = e.target.value; draw(); }; $('#la', host).onchange = e => { S.aud = e.target.value; draw(); }; $('#ls', host).onchange = e => { S.st = e.target.value; draw(); };
    host.onclick = e => {
      const o = e.target.closest('[data-open]'); if (o) { const f = window.CVTaraPreview && window.CVTaraPreview[isT ? 'template' : 'letter']; return f ? f(o.dataset.open) : toast('Preview is not available on this page', true); }
      const ed = e.target.closest('[data-edit]'); if (ed) edit(items.find(i => i.slug === ed.dataset.edit));
    };
    function edit(it) {
      const ov = overrides()[it.slug] || {}, pub = isPub(it);
      const fld = (n, l, v, o = {}) => `<label class="${o.wide ? 'wide' : ''}">${l}${o.area ? `<textarea name="${n}" rows="${o.rows || 4}" maxlength="${o.max || 4000}">${E(v)}</textarea>` : `<input name="${n}" ${o.type ? `type="${o.type}"` : ''} maxlength="${o.max || 180}" value="${E(v)}">`}</label>`;
      const body = Array.isArray(ov.body) ? ov.body.join('\n\n') : (ov.body ?? (Array.isArray(it.body) ? it.body.join('\n\n') : it.body || ''));
      const m = modal((isT ? 'Edit resume design — ' : 'Edit cover letter — ') + it.name, `<form class="ops-form">${fld('name', 'Display name', ov.name ?? it.name)}${isT ? fld('category', 'Category', ov.category ?? it.category) + fld('accent', 'Accent colour', ov.accent ?? it.accent ?? '#1557d2', { type: 'color' }) + `<label class="ops-check"><input type="checkbox" name="ats" ${(ov.ats ?? it.ats) ? 'checked' : ''}> Text-first / ATS-conscious layout</label>` + fld('summary', 'Fictional sample summary', ov.summary ?? '', { wide: true, area: true }) : fld('role', 'Role', ov.role ?? it.role) + fld('market', 'Market', ov.market ?? it.market) + fld('tone', 'Tone', ov.tone ?? it.tone) + fld('subject', 'Subject', ov.subject ?? it.subject ?? '') + fld('salutation', 'Salutation', ov.salutation ?? it.salutation ?? '') + fld('body', 'Full sample letter', body, { wide: true, area: true, rows: 12, max: 20000 }) + fld('close', 'Closing', ov.close ?? it.close ?? '', { wide: true, area: true, rows: 2, max: 500 })}
        <label class="wide ops-check"><input type="checkbox" name="published" ${pub ? 'checked' : ''}> Published — customers can choose it</label><div class="wide"><button class="button primary">Save</button> <button type="button" class="button secondary" data-prev>Preview</button> <span class="ops-st" role="status"></span></div><p class="ops-note wide">Sample names and achievements stay fictional. Customers’ saved documents are never changed by edits here.</p></form>`);
      m.body.querySelector('[data-prev]').onclick = () => { const f = window.CVTaraPreview && window.CVTaraPreview[isT ? 'template' : 'letter']; if (f) f(it.slug); };
      m.body.querySelector('form').onsubmit = async ev => {
        ev.preventDefault(); const st = m.body.querySelector('.ops-st'); st.textContent = 'Saving…';
        const f = ev.target, v = Object.fromEntries(new FormData(f)); v.published = f.published.checked; if (isT) v.ats = f.ats.checked;
        try {
          const next = { ...saved.data, [kind]: { ...(saved.data[kind] || {}), [it.slug]: v } };
          const r = await saveContent('library', next, saved.revision); saved.data = next; saved.revision = r.revision ?? saved.revision + 1;
          Object.assign(it, { name: v.name, ...(isT ? { category: v.category, accent: v.accent, ats: v.ats } : { role: v.role, market: v.market, tone: v.tone }) }); it.publicPublished = v.published;
          document.dispatchEvent(new CustomEvent('cvtara-library-refresh')); toast('Saved'); m.close(); draw();
        } catch (x) { st.textContent = err(x); }
      };
    }
    draw();
  }

  /* ======================= TEMPLATES & SITE DESIGN ======================= */
  async function sitedesign() {
    const h = $('#admin-sitedesign'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div><div class="ops-skel"></div>';
    try {
      const [site, seo] = await Promise.all([api('/api/admin/content/site'), api('/api/admin/content/seo')]);
      const s = { announce: '', announceLink: '', heroPill: '', heroTitle: '', heroRoles: [], heroLead: '', ctaPrimary: '', ctaSecondary: '', ctaFinalTitle: '', ctaFinalText: '', ctaFinalButton: '', pages: {}, ...site.data };
      const pages = { ...(seo.data.pages || {}) };
      const lab = { '/': 'Home', '/templates.html': 'Resume templates', '/cover-letters.html': 'Cover letters', '/blog.html': 'Blog', '/guides.html': 'Guides & calculators', '/about.html': 'About us', '/contact.html': 'Contact & support', '/privacy.html': 'Privacy policy', '/terms.html': 'Terms & conditions', '/refund.html': 'No-refund policy' };
      const keys = Object.keys(lab).filter(k => pages[k] || true);
      const inp = (n, l, v, o = {}) => `<label class="${o.wide ? 'wide' : ''}">${l}${o.area ? `<textarea name="${n}" rows="${o.rows || 3}" maxlength="${o.max || 600}">${E(v)}</textarea>` : `<input name="${n}" maxlength="${o.max || 200}" value="${E(v)}">`}${o.hint ? `<small>${o.hint}</small>` : ''}</label>`;
      h.innerHTML = `<div class="pg-head"><h2>Templates & site design</h2><p>What the public website says, prefilled from the live site. Change anything and it updates the website — no files to edit.</p></div>
        <div class="sd-grid"><section class="panel panel-pad"><h3>Homepage content</h3><form id="sdForm" class="ops-form">
          ${inp('heroPill', 'Small label above the headline', s.heroPill)}${inp('announce', 'Announcement bar', s.announce, { hint: 'Use <code>{{from}}</code> for the lowest plan price.' })}
          ${inp('heroTitle', 'Headline (first part)', s.heroTitle, { wide: true, hint: 'The rotating job titles are added after this text.' })}${inp('heroRoles', 'Rotating job titles (one per line)', (s.heroRoles || []).join('\n'), { wide: true, area: true, rows: 5 })}
          ${inp('heroLead', 'Sub-headline', s.heroLead, { wide: true, area: true, hint: 'Wrap words in <code>**double stars**</code> to make them bold.' })}
          ${inp('ctaPrimary', 'Main button', s.ctaPrimary)}${inp('ctaSecondary', 'Second button', s.ctaSecondary)}${inp('announceLink', 'Announcement link text', s.announceLink)}
          ${inp('ctaFinalTitle', 'Closing section title', s.ctaFinalTitle, { wide: true })}${inp('ctaFinalText', 'Closing section text', s.ctaFinalText, { wide: true, area: true, rows: 2 })}${inp('ctaFinalButton', 'Closing button', s.ctaFinalButton)}
          <div class="wide"><button class="button primary">Save homepage content</button> <button type="button" class="button secondary" id="sdOpen">Open website ↗</button> <span class="ops-st" role="status"></span></div></form></section>
          <section class="panel panel-pad sd-prev"><h3>Live preview</h3><div class="sd-hero"><div class="sd-ann" id="pvAnn"></div><span class="lp-pill" id="pvPill"></span><h4 id="pvH"></h4><p id="pvLead"></p><div><span class="button primary small" id="pvB1"></span> <span class="button secondary small" id="pvB2"></span></div></div></section></div>
        <section class="panel panel-pad" style="margin-top:20px"><h3>Search titles, descriptions & keywords</h3><p class="ops-note" style="margin-top:0">These are the search-result text and focus keywords for each main page — already filled in, ready to edit. Blog and guide keywords are edited in Blog & SEO.</p>
          <form id="sdSeo"><div class="sd-table">${keys.map(k => { const p = pages[k] || {}; return `<div class="sd-row" data-k="${E(k)}"><div class="sd-pg"><b>${E(lab[k])}</b><a href="${E(k)}" target="_blank" rel="noopener">${E(k)} ↗</a></div><label>Title<input data-f="title" maxlength="70" value="${E(p.title || '')}"><small class="cnt"></small></label><label>Description<textarea data-f="description" rows="2" maxlength="170">${E(p.description || '')}</textarea><small class="cnt"></small></label><label>Focus & advanced keywords<input data-f="keywords" maxlength="300" value="${E(p.keywords || '')}" placeholder="comma separated"></label></div>`; }).join('')}</div><div style="margin-top:14px"><button class="button primary">Save search settings</button> <span class="ops-st" role="status"></span></div></form></section>`;
      const f = $('#sdForm'), pv = () => { const v = Object.fromEntries(new FormData(f)); $('#pvAnn').textContent = (v.announce || '').replace('{{from}}', '₹299') + ' · ' + v.announceLink; $('#pvPill').textContent = v.heroPill; $('#pvH').innerHTML = E(v.heroTitle) + ' <em>' + E((v.heroRoles || '').split('\n')[0]) + '</em>'; $('#pvLead').innerHTML = E(v.heroLead).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>'); $('#pvB1').textContent = v.ctaPrimary; $('#pvB2').textContent = v.ctaSecondary; };
      f.oninput = pv; pv(); $('#sdOpen').onclick = () => window.open('/', '_blank');
      f.onsubmit = async e => {
        e.preventDefault(); const st = $('.ops-st', f); st.textContent = 'Saving…'; const v = Object.fromEntries(new FormData(f));
        try { const data = { ...s, ...v, heroRoles: v.heroRoles.split('\n').map(x => x.trim()).filter(Boolean) }; const r = await saveContent('site', data, site.revision); site.revision = r.revision ?? site.revision + 1; st.textContent = 'Saved — live on the website.'; toast('Homepage content saved'); } catch (x) { st.textContent = err(x); }
      };
      const cnt = () => $$('.sd-row', h).forEach(r => { $$('input[data-f=title],textarea[data-f=description]', r).forEach(i => { const lim = i.dataset.f === 'title' ? [30, 60] : [120, 160], n = i.value.length; const c = i.parentElement.querySelector('.cnt'); c.textContent = n + ' characters' + (n < lim[0] ? ' · a little short' : n > lim[1] ? ' · may be cut in search results' : ' · good'); c.className = 'cnt ' + (n >= lim[0] && n <= lim[1] ? 'ok' : 'warn'); }); });
      $('#sdSeo').oninput = cnt; cnt();
      $('#sdSeo').onsubmit = async e => {
        e.preventDefault(); const st = $('.ops-st', e.target); st.textContent = 'Saving…';
        try { const np = { ...pages }; $$('.sd-row', h).forEach(r => { const k = r.dataset.k; np[k] = { ...(np[k] || {}), includeInSitemap: np[k] ? np[k].includeInSitemap !== false : true }; $$('[data-f]', r).forEach(i => { np[k][i.dataset.f] = i.value.trim(); }); }); const data = { ...seo.data, pages: np }; const r = await saveContent('seo', data, seo.revision); seo.revision = r.revision ?? seo.revision + 1; seo.data = data; st.textContent = 'Saved.'; toast('Search settings saved'); } catch (x) { st.textContent = err(x); }
      };
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }

  /* ======================= USER MAILER ======================= */
  const VARS = ['name', 'email', 'plan_name', 'access_until', 'amount', 'payment_reference', 'site_url', 'reset_url'];
  const TRIG = { profile_created: 'New account', password_reset: 'Password reset', resume_saved: 'Resume saved', purchase_verified: 'Subscription confirmed', refund_requested: 'Refund requested', refund_processed: 'Refund sent', refund_rejected: 'Refund declined', expert_review_delivered: 'Expert review delivered', abandoned_purchase: 'Checkout abandoned', blog_published: 'New blog post', access_expiring: 'Plan expiring', followup_due: 'Application follow-up', application_status: 'Application status reminder', download_locked: 'Download needs a plan', manual: 'Manual' };
  async function mailer() {
    const h = $('#admin-mailer'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div><div class="ops-skel"></div>';
    try {
      const [em, integ, jobs, dash] = await Promise.all([api('/api/admin/content/emails'), api('/api/admin/content/integrations'), api('/api/admin/email/jobs').catch(() => ({ jobs: [] })), K.data()]);
      const list = em.data, active = !!integ.data.email, counts = (jobs.jobs || []).reduce((a, j) => { a[j.status] = (a[j.status] || 0) + 1; return a; }, {});
      const hb = jobs.heartbeat && jobs.heartbeat.at ? dtm(jobs.heartbeat.at) : 'not yet';
      h.innerHTML = `<div class="pg-head"><h2>User Mailer</h2><p>Automatic emails to every registered customer, always sent from <b>support@cvtara.com</b>.</p></div>
        <section class="panel panel-pad mail-status"><div class="mail-state ${active ? 'on' : 'off'}"><span class="live-dot" ${active ? '' : 'style="background:#ef4444"'}></span><div><b>${active ? 'Mailer is active' : 'Mailer is paused'}</b><small>${active ? 'Service emails go out automatically to all customers.' : 'No emails are being sent. Turn it on to resume.'}</small></div><button class="button ${active ? 'secondary' : 'primary'}" id="mTog">${active ? 'Pause mailer' : 'Activate mailer'}</button></div>
          <dl class="mail-dl"><div><dt>Sender</dt><dd>CVTara &lt;support@cvtara.com&gt;</dd></div><div><dt>Reply-to</dt><dd>support@cvtara.com</dd></div><div><dt>Domain</dt><dd>cvtara.com</dd></div><div><dt>Recipients</dt><dd>All ${dash.total} registered users</dd></div><div><dt>Scheduler last ran</dt><dd>${hb}</dd></div></dl>
          <div class="ops-bar" style="margin:12px 0 0"><button class="button secondary small" id="mTest">Send me a test email</button><button class="button secondary small" id="mRun">Send queued emails now</button><span class="ops-st" role="status"></span></div>
          <p class="ops-note">To keep emails out of spam, add the SPF and DKIM records from your Hostinger email settings to the cvtara.com DNS.</p></section>
        <div class="ops-kpis"><div class="kpi"><span>Sent</span><b>${counts.sent || 0}</b></div><div class="kpi"><span>Queued</span><b>${counts.queued || 0}</b></div><div class="kpi"><span>Failed</span><b style="color:${counts.failed ? '#ef4444' : 'inherit'}">${counts.failed || 0}</b></div><div class="kpi"><span>Templates on</span><b>${list.filter(t => t.enabled).length}/${list.length}</b></div></div>
        <h3 class="ops-h3">Email templates</h3><form id="mForm">${list.map((t, i) => `<details class="panel mail-tpl" ${i < 1 ? 'open' : ''}><summary><span class="mt-name">${E(t.name)}</span><span class="tag">${E(TRIG[t.trigger] || t.trigger)}</span><label class="ops-sw" onclick="event.stopPropagation()"><input type="checkbox" data-i="${i}" data-f="enabled" ${t.enabled ? 'checked' : ''} aria-label="Enabled"></label></summary><div class="ops-form"><label>Send after (hours)<input type="number" min="0" max="720" data-i="${i}" data-f="delayHours" value="${t.delayHours || 0}"></label><label class="wide">Subject<input data-i="${i}" data-f="subject" maxlength="180" value="${E(t.subject)}"></label><label class="wide">Message<textarea rows="9" data-i="${i}" data-f="body" maxlength="20000">${E(t.body)}</textarea></label><div class="wide mail-vars">Insert: ${VARS.map(v => `<button type="button" class="tag" data-ins="${v}" data-i="${i}">{{${v}}}</button>`).join(' ')} <button type="button" class="button secondary small" data-prev="${i}" style="margin-left:auto">Preview</button></div></div></details>`).join('')}<div class="ops-bar"><button class="button primary">Save templates</button><span class="ops-st" id="mSt" role="status"></span></div></form>
        <h3 class="ops-h3">Recent emails</h3><section class="panel"><table class="ad-table"><thead><tr><th>To</th><th>Subject</th><th>Status</th><th>When</th></tr></thead><tbody>${(jobs.jobs || []).slice(0, 25).map(j => `<tr><td>${E(j.recipient)}</td><td>${E(j.subject)}${j.error ? `<br><small style="color:#ef4444">${E(j.error)}</small>` : ''}</td><td>${pill(j.status === 'sent' ? 'success' : j.status)}</td><td>${dtm(j.sent_at || j.due_at)}</td></tr>`).join('') || '<tr><td colspan="4">No emails yet.</td></tr>'}</tbody></table></section>`;
      const st = $('.ops-st', h);
      $('#mTog').onclick = async () => { try { const data = { ...integ.data, email: !active }; await saveContent('integrations', data, integ.revision); toast(!active ? 'Mailer activated' : 'Mailer paused'); mailer(); } catch (x) { st.textContent = err(x); } };
      $('#mTest').onclick = async () => { st.textContent = 'Sending…'; try { const r = await api('/api/admin/setup/email-test', { method: 'POST', body: {} }); st.textContent = `Test processed — ${r.delivery ? r.delivery.sent : 0} sent. Check your inbox.`; } catch (x) { st.textContent = err(x); } };
      $('#mRun').onclick = async () => { st.textContent = 'Sending…'; try { const r = await api('/api/admin/email/process', { method: 'POST', body: {} }); st.textContent = `Done — ${r.delivery ? r.delivery.sent : r.sent || 0} sent.`; setTimeout(mailer, 800); } catch (x) { st.textContent = err(x); } };
      h.addEventListener('click', e => {
        const ins = e.target.closest('[data-ins]'); if (ins) { const ta = $(`textarea[data-i="${ins.dataset.i}"]`, h), a = ta.selectionStart; ta.value = ta.value.slice(0, a) + `{{${ins.dataset.ins}}}` + ta.value.slice(ta.selectionEnd); ta.focus(); return; }
        const pv = e.target.closest('[data-prev]'); if (pv) { const t = list[pv.dataset.prev], sample = { name: 'Priya', email: 'priya@example.com', plan_name: 'Fresher Standard', access_until: '12 December 2026', amount: '₹399.00', payment_reference: 'pay_demo123', site_url: 'https://cvtara.com', reset_url: 'https://cvtara.com/reset-password/…', resumes: '20', letters: '20' }; const rep = s => String(s).replace(/\{\{([a-z_]+)\}\}/g, (m, k) => sample[k] ?? ''); modal('Preview — ' + t.name, `<div class="mail-preview"><div><small>From</small> CVTara &lt;support@cvtara.com&gt;</div><div><small>To</small> priya@example.com</div><div><small>Subject</small> <b>${E(rep($(`input[data-i="${pv.dataset.prev}"][data-f=subject]`, h).value))}</b></div><pre>${E(rep($(`textarea[data-i="${pv.dataset.prev}"]`, h).value))}</pre></div>`); }
      });
      $('#mForm').onsubmit = async e => {
        e.preventDefault(); const s2 = $('#mSt'); s2.textContent = 'Saving…';
        $$('[data-i][data-f]', h).forEach(i => { const t = list[i.dataset.i]; t[i.dataset.f] = i.type === 'checkbox' ? i.checked : i.type === 'number' ? Number(i.value) : i.value; });
        try { const r = await saveContent('emails', list, em.revision); em.revision = r.revision ?? em.revision + 1; s2.textContent = 'Saved.'; toast('Email templates saved'); } catch (x) { s2.textContent = err(x); }
      };
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }

  K.register('resumelib', () => library('templates'), ['📄', 'Resume Library', 'Every resume design customers can pick.']);
  K.register('letterlib', () => library('letters'), ['✉️', 'CL Library', 'Cover-letter designs and sample letters.']);
  K.register('sitedesign', sitedesign, ['🎨', 'Templates & site design', 'Homepage content and page keywords, prefilled and editable.']);
  K.register('mailer', mailer, ['📧', 'User Mailer', 'Automatic emails to every customer, from support@cvtara.com.']);
})();
