/* Owner console: Operations menu, view banners, Settings hub, Referral Ops and Error Log. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const inr = p => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN');
  const META = {
    dashboard: ['📊', 'Overview', 'How the business is doing right now.'],
    orders: ['🛒', 'All Orders', 'Complete transaction history'],
    desk: ['⭐', 'User Reviews', 'Add, moderate and publish user reviews shown on the landing page'],
    overview: ['📈', 'Detailed analytics', 'Profiles, funnels, pipeline and activity in depth.'],
    users: ['👥', 'Users', 'Search accounts, review plans and block abusive users.'],
    commerce: ['🛒', 'Orders', 'Verified purchases, refunds and coupons.'],
    templates: ['📄', 'Resume Library', 'Every resume template customers can pick, with its category.'],
    letters: ['✉️', 'CL Library', 'Cover-letter designs and sample copy.'],
    design: ['🎨', 'Templates & site design', 'Homepage copy, colours and the look of the public website.'],
    business: ['💹', 'Business Ops', 'Coupons, growth rules and plan controls in one place.'],
    referral: ['🎁', 'Referral Ops', 'Manage the referral programme, affiliate balances, and payout requests'],
    emails: ['📧', 'User Mailer', 'Email templates, sending rules and delivery queue.'],
    errors: ['🔴', 'Error Log', 'Health checks, failed emails and warnings that need attention.'],
    blog: ['📝', 'Blog & SEO', 'Pages, guides and articles with their search settings.'],
    reviews: ['⭐', 'Reviews', 'Customer feedback, consent and what is published.'],
    pricing: ['💰', 'Pricing Plans', 'Edit plan prices, limits, add-on packs and perks. Changes go live instantly.'],
    settings: ['🔧', 'Settings', 'Integrations, policies, SEO, analytics and everything else you can configure.'],
    features: ['⌁', 'Product controls', 'Choose which products customers can use.'],
    popup: ['🔔', 'Offers & popup', 'The social-proof popup shown on the website.'],
    seo: ['◎', 'SEO & sitemap', 'Search titles, descriptions and sitemap.'],
    keywords: ['⌕', 'Keyword intelligence', 'Target keywords and Search Console results.'],
    analytics: ['↗', 'Analytics', 'Traffic and recorded product activity.'],
    integrations: ['⚙', 'Integrations', 'Payments, email, AI, analytics and push services.'],
    push: ['◉', 'Push notifications', 'OneSignal push setup.'],
    legal: ['§', 'Footer & policies', 'Footer links, legal pages and contact details.'],
    setup: ['✔', 'Setup & health', 'Launch checklist, cron, webhooks and test tools.']
  };
  const HUB = ['policies', 'health', 'features', 'popup', 'keywords', 'analytics', 'integrations', 'push', 'seo', 'overview'];
  let current = 'overview';

  function banner(k) {
    const v = $('#admin-' + k); if (!v) return;
    if (['dashboard', 'orders', 'desk', 'referral', 'people', 'resumelib', 'letterlib', 'sitedesign', 'mailer', 'errors', 'bloghub', 'policies', 'health', 'reports'].includes(k)) return;
    let b = v.querySelector(':scope > .ops-banner'); const m = META[k]; if (!m) return;
    if (!b) { b = document.createElement('div'); b.className = 'ops-banner'; v.prepend(b); }
    b.innerHTML = `<span class="ops-ic">${m[0]}</span><div><h2>${E(m[1])}</h2><p>${E(m[2])}</p></div>${k !== 'overview' && HUB.includes(k) ? '<button class="button secondary small" data-admin-view="settings">← Settings</button>' : ''}`;
  }
  function title(k) { const t = $('#adminTitle'); if (t && META[k]) t.textContent = META[k][1]; }

  /* ---- Settings hub ---- */
  function settings() {
    const h = $('#admin-settings'); if (!h) return;
    h.innerHTML = `<div class="ops-grid">${HUB.map(k => `<article class="ops-card"><span class="ops-ic">${META[k][0]}</span><h3>${E(META[k][1])}</h3><p>${E(META[k][2])}</p><button class="button secondary small" data-admin-view="${k}">Open</button></article>`).join('')}
      <article class="ops-card"><span class="ops-ic">📖</span><h3>Platform guide</h3><p>How every part of the owner console works.</p><a class="button secondary small" href="admin-guide.html">Read guide</a></article></div>`;
    banner('settings');
  }

  /* ---- Error Log ---- */
  async function errors() {
    const h = $('#admin-errors'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>'; banner('errors');
    const lines = []; const stamp = () => new Date().toISOString().slice(0, 19).replace('T', ' ');
    try {
      const [st, jobs, hl] = await Promise.all([api('/api/admin/setup/status').catch(() => null), api('/api/admin/email/jobs').catch(() => null), api('/api/health').catch(() => null)]);
      const labels = { backupFresh: 'No database backup in the last 36 hours', previewOff: 'Demo/preview mode is ON — remove CVTARA_PREVIEW from .env', https: 'HTTPS is not forced on this domain', emailConfigured: 'Email provider not configured (SMTP / Resend)', scheduler: 'Cron scheduler has not reported in', payoutPolicy: 'Referral payout policy not confirmed', privateFolder: 'Private folder is not protected' };
      if (st && st.checks) Object.entries(st.checks).forEach(([k, v]) => { if (!v) lines.push(['warn', `setup.${k}: ${labels[k] || k}`]); else if (v) lines.push(['info', `setup.${k}: ok`]); });
      ((jobs && jobs.jobs) || []).filter(j => /fail|error/i.test(j.status || '')).forEach(j => lines.push(['err', `mail.${E(j.template || 'job')} to ${E(j.to_email || '?')}: ${E(j.last_error || j.status)}`]));
      if (hl) lines.push(['info', `storage: ${hl.storage || 'unknown'}`]);
    } catch (e) { lines.push(['err', E(e.message)]); }
    const errs = lines.filter(l => l[0] === 'err').length, warns = lines.filter(l => l[0] === 'warn').length;
    h.querySelector('.ops-skel')?.remove();
    h.insertAdjacentHTML('beforeend', `<div class="ops-kpis"><div class="kpi"><span>Errors</span><b style="color:#ef4444">${errs}</b></div><div class="kpi"><span>Warnings</span><b style="color:#f59e0b">${warns}</b></div><div class="kpi"><span>Checks passed</span><b style="color:#22c55e">${lines.length - errs - warns}</b></div></div>
      <div class="ops-term"><div class="ops-term-bar"><span class="live-dot"></span> live · refreshed ${stamp()} <button class="button small" id="opsErrRefresh">Refresh</button></div><pre>${lines.sort((a, b) => ['err', 'warn', 'info'].indexOf(a[0]) - ['err', 'warn', 'info'].indexOf(b[0])).map(l => `<span class="${l[0]}">[${l[0].toUpperCase().padEnd(4)}]</span> ${stamp()}  ${l[1]}`).join('\n') || 'No entries.'}</pre></div>`);
    $('#opsErrRefresh').onclick = errors;
  }


  /* ================= dashboard data ================= */
  const parse = s => { s = String(s || ''); return new Date(s.replace(' ', 'T') + (/[zZ]$|[+-]\d\d:?\d\d$/.test(s) ? '' : 'Z')); };
  const short = p => { const r = (p || 0) / 100; return r >= 1e5 ? '₹' + (r / 1e5).toFixed(1).replace(/\.0$/, '') + 'L' : r >= 1e3 ? '₹' + (r / 1e3).toFixed(r >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K' : '₹' + Math.round(r).toLocaleString('en-IN'); };
  const dmy = d => d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    const planName = p => { try { return JSON.parse(p.limits_json).name; } catch (e) { return p.plan; } };
  const pill = st => `<span class="pill ${/paid|success|published|active/i.test(st) ? 'st-paid' : /refund|hidden|fail/i.test(st) ? 'st-failed' : /pending/i.test(st) ? 'st-started' : 'st-free'}">${E(st === 'paid' ? 'success' : st)}</span>`;
  function barChart(vals, labels) {
    const W = 560, H = 190, max = Math.max(...vals, 1), bw = 38, gap = (W - bw * vals.length) / (vals.length + 1);
    return `<svg viewBox="0 0 ${W} ${H}" class="ops-svg" role="img" aria-label="Signups last 7 days">${vals.map((v, i) => { const h = Math.max(4, (v / max) * 120), x = gap + i * (bw + gap); return `<text x="${x + bw / 2}" y="${H - 36 - h}" text-anchor="middle" class="ops-t">${v}</text><rect x="${x}" y="${H - 28 - h}" width="${bw}" height="${h}" rx="3" fill="#1557d2"/><text x="${x + bw / 2}" y="${H - 8}" text-anchor="middle" class="ops-t2">${E(labels[i])}</text>`; }).join('')}</svg>`;
  }
  function lineChart(vals) {
    const W = 560, H = 190, max = Math.max(...vals, 1), step = (W - 40) / (vals.length - 1 || 1);
    const pts = vals.map((v, i) => [20 + i * step, H - 26 - (v / max) * 130]);
    const line = pts.map(p => p.join(',')).join(' ');
    return `<svg viewBox="0 0 ${W} ${H}" class="ops-svg" role="img" aria-label="Revenue last 30 days"><polygon points="20,${H - 26} ${line} ${W - 20},${H - 26}" fill="rgba(99,102,241,.12)"/><polyline points="${line}" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linejoin="round"/></svg>`;
  }

  async function dashboard() {
    const h = $('#admin-dashboard'); if (!h) return; if (!h.dataset.ok) h.innerHTML = '<div class="ops-skel"></div><div class="ops-skel"></div>';
    try {
      // Every number comes from the server (full database, real money only), never from a truncated page of rows.
      const [k, dash, ro] = await Promise.all([api('/api/admin/report/kpis?days=30'), api('/api/admin/dashboard?days=30').catch(() => ({})), api('/api/admin/report?dataset=orders&per=10&columns=customer,plan,amount_inr,status,date&format=json')]);
      const d = { total: k.users, paidUsers: k.paidUsers, dash }, last7 = k.series.slice(-7);
      const newThisWeek = k.signups7, revenue = k.netRevenuePaise, revWeek = last7.reduce((s, x) => s + x.revenuePaise, 0), ordersWeek = last7.reduce((s, x) => s + x.orders, 0);
      const ev = Object.fromEntries(((dash.eventCounts) || []).map(e => [e.event, e.count]));
      const scans = (ev.resume_compared || 0) + (ev.ats_scan || 0);
      const conv = k.conversionPct;
      const days = last7.map(x => new Date(x.date + 'T00:00:00'));
      const signups = last7.map(x => x.signups);
      const rev30 = k.series.map(x => x.revenuePaise / 100);
      const recent = ro.rows;
      const act = (dash.activity || []).slice(0, 5);
      h.dataset.ok = '1';
      h.innerHTML = `
        <div class="pg-head"><h2>Overview</h2><p>How the business is doing right now</p></div>
        <div class="ops-kpis six">
          <div class="kpi"><span>Total members</span><b>${d.total.toLocaleString('en-IN')}</b><small>+${newThisWeek} this week</small></div>
          <div class="kpi"><span>Paid users</span><b>${d.paidUsers}</b><small>+${ordersWeek} orders this week</small></div>
          <div class="kpi"><span>Revenue</span><b>${short(revenue)}</b><small>+${short(revWeek)} this week</small></div>
          <div class="kpi"><span>ATS scans</span><b>${scans.toLocaleString('en-IN')}</b><small>all time</small></div>
          <div class="kpi"><span>Resumes built</span><b>${(d.dash.totalResumes ?? 0).toLocaleString('en-IN')}</b><small>${d.dash.totalApplications ?? 0} applications tracked</small></div>
          <div class="kpi"><span>Conversion</span><b>${conv.toFixed(1)}%</b><small>paid ÷ members</small></div>
        </div>
        <div class="ops-two">
          <section class="panel panel-pad"><h3>Signups — Last 7 Days</h3>${barChart(signups, days.map(x => x.toLocaleDateString('en-US', { weekday: 'short' })))}</section>
          <section class="panel panel-pad"><h3>Revenue — Last 30 Days</h3>${lineChart(rev30)}</section>
        </div>
        <div class="ops-two">
          <div><h3 class="ops-h3">Recent Orders</h3><section class="panel"><table class="ad-table"><thead><tr><th>User</th><th>Plan</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>${recent.map(p => `<tr><td>${E(p.customer || 'Customer')}</td><td>${E(p.plan)}</td><td><b>${short(p.amount_inr * 100)}</b></td><td>${pill(p.status)}</td><td>${dmy(parse(p.date))}</td></tr>`).join('') || '<tr><td colspan="5">No orders yet.</td></tr>'}</tbody></table></section></div>
          <div><h3 class="ops-h3">Recent Activity</h3><section class="panel"><table class="ad-table"><thead><tr><th>User</th><th>Event</th><th>Time</th></tr></thead><tbody>${act.map(a => `<tr><td>${E(a.name || 'Visitor')}</td><td>${E(String(a.event).replace(/_/g, ' '))}</td><td>${dmy(parse(a.created_at))}</td></tr>`).join('') || '<tr><td colspan="3">No activity recorded yet.</td></tr>'}</tbody></table></section></div>
        </div>`;
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(e.message)}</div>`; }
  }

  async function desk() {
    const h = $('#admin-desk'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div>';
    let tab = 'pending', list = [], counts = {}, page = 1, per = 20, q = '', timer = null;
    // One page of the chosen tab at a time; the tab counts come from the server.
    const load = async () => { const d = await api(`/api/admin/reviews?status=${tab}&page=${page}&per=${per}${q ? '&q=' + encodeURIComponent(q) : ''}`); list = d.reviews || []; counts = d.counts || {}; page = d.page || 1; paint(d); };
    const initials = n => String(n || '?').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
    const paint = d => {
      $('#opsRvTabs').innerHTML = [['pending', 'Pending'], ['published', 'Published'], ['hidden', 'Hidden']].map(([k, l]) => `<button class="${tab === k ? 'on' : ''}" data-rt="${k}">${l} (${counts[k] || 0})</button>`).join('');
      pager($('#opsRvPg'), d, (p, n) => { page = p; per = n; load(); });
      $('#opsRvList').innerHTML = list.map(r => `<article class="panel panel-pad ops-rv"><span class="av">${E(initials(r.display_name))}</span><div><b>${E(r.display_name)}</b> <small>${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)} · ${E(r.source === 'owner_verified' ? 'verified, with consent' : r.source === 'dummy' ? 'sample' : r.source)}</small><p>“${E(r.content)}”</p><div class="ops-rv-act">${r.status !== 'published' ? `<button class="button small ops-ok" data-rv="${r.id}" data-st="published">✓ Approve &amp; Publish</button>` : `<button class="button small secondary" data-rv="${r.id}" data-st="hidden">Unpublish</button>`}${r.status !== 'hidden' ? `<button class="button small ops-no" data-rv="${r.id}" data-st="hidden">✕ Reject</button>` : `<button class="button small secondary" data-rv="${r.id}" data-st="pending">Restore</button>`}</div></div></article>`).join('') || '<div class="panel panel-pad"><p>Nothing here.</p></div>';
    };
    h.innerHTML = `<div class="pg-head"><h2>User Reviews</h2><p>Add, moderate and publish user reviews shown on the landing page</p></div>
      <section class="panel panel-pad"><h3>＋ Add New Review</h3><form id="opsRvForm" class="ops-form"><label>Full name<input name="name" required maxlength="100" placeholder="Priya Sharma"></label><label>Company &amp; role<input name="role" maxlength="100" placeholder="TCS · Software Engineer"></label><label>City<input name="city" maxlength="60" placeholder="Mumbai"></label><label>Star rating<select name="rating"><option value="5">★★★★★ 5 Stars</option><option value="4">★★★★ 4 Stars</option><option value="3">★★★ 3 Stars</option></select></label><label class="wide">Review text<textarea name="content" required minlength="10" maxlength="2000" rows="3" placeholder="Write the review feedback…"></textarea></label><label>Source<select name="source"><option value="dummy">Sample (cannot be published)</option><option value="verified">Verified customer (consent on file)</option></select></label><label>Customer account email<input name="customerEmail" type="email" placeholder="Required for verified reviews — a paying customer"></label><label>Consent reference<input name="consentReference" placeholder="Required for verified reviews — where consent is recorded"></label><div class="wide"><button class="button primary">Add Review</button> <span id="opsRvSt" role="status"></span></div></form></section>
      <div class="ops-tabs" id="opsRvTabs"></div><div class="ops-filters"><input type="search" id="opsRvQ" placeholder="Search name, review text or email" aria-label="Search reviews"></div><div id="opsRvList"></div><div class="ops-pager" id="opsRvPg"></div>`;
    $('#opsRvQ').oninput = e => { clearTimeout(timer); timer = setTimeout(() => { q = e.target.value.trim(); page = 1; load(); }, 350); };
    $('#opsRvForm').onsubmit = async e => {
      e.preventDefault(); const f = e.target, v = Object.fromEntries(new FormData(f)), st = $('#opsRvSt'); st.textContent = 'Saving…';
      try { await api('/api/admin/feedback/add', { method: 'POST', body: { name: v.name + (v.role ? ' · ' + v.role : '') + (v.city ? ', ' + v.city : ''), content: v.content, rating: Number(v.rating), source: v.source, consent: v.source === 'verified', consentReference: v.consentReference || '', customerEmail: v.customerEmail || '' } }); st.textContent = 'Added.'; f.reset(); await load(); }
      catch (err) { st.textContent = err.message; }
    };
    h.onclick = async e => {
      const t = e.target.closest('[data-rt]'); if (t) { tab = t.dataset.rt; page = 1; return load(); }
      const b = e.target.closest('[data-rv]'); if (!b) return; b.disabled = true;
      try { await api('/api/admin/reviews', { method: 'POST', body: { id: b.dataset.rv, status: b.dataset.st } }); await load(); } catch (err) { b.disabled = false; (window.alert || console.error)(err.message); }
    };
    try { await load(); } catch (e) { $('#opsRvList').innerHTML = `<div class="panel panel-pad" role="alert">${E(e.message)}</div>`; }
  }

  /* ---- Live stats card ---- */
  async function live() {
    if (document.hidden && $('#opsLive')) return;
    if (!$('#opsLive')) { const c = document.createElement('aside'); c.id = 'opsLive'; c.className = 'ops-live'; c.innerHTML = '<header><span class="live-dot"></span> LIVE STATS <button type="button" aria-label="Collapse">⤢</button></header><div></div><footer></footer>'; document.body.appendChild(c); c.querySelector('button').onclick = () => { c.dataset.manual = '1'; c.classList.toggle('min'); }; }
    try {
      // One small cached request (five counts) instead of the user, order and dashboard lists every 30 seconds.
      const d = await api('/api/admin/live'), now = new Date();
      const rows = [['Active users', d.activeUsers], ['Resumes today', d.resumesToday], ['Revenue today', short(d.revenueTodayPaise)], ['ATS scans', d.scansToday], ['Signups today', d.signupsToday]];
      $('#opsLive > div').innerHTML = rows.map(r => `<p><span>${r[0]}</span><b>${r[1]}</b></p>`).join('');
      $('#opsLive footer').textContent = 'Updated ' + now.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
    } catch (e) { /* keep last values */ }
  }

  const render = { settings, errors, dashboard, desk };

  /* ================= shared kit for the owner-console modules ================= */
  function modal(title, html, o = {}) {
    const wrap = document.createElement('div'); wrap.className = 'ad-modal';
    wrap.innerHTML = `<div class="ad-modal-box ${o.wide ? 'wide' : ''}" role="dialog" aria-modal="true" aria-label="${E(title)}"><header><h3>${E(title)}</h3><button type="button" class="ad-x" aria-label="Close">×</button></header><div class="ad-modal-body">${html}</div></div>`;
    document.body.append(wrap); document.body.classList.add('ad-modal-open');
    const esc = e => { if (e.key === 'Escape') close(); };
    const close = () => { wrap.remove(); if (!document.querySelector('.ad-modal')) document.body.classList.remove('ad-modal-open'); document.removeEventListener('keydown', esc); if (o.onClose) o.onClose(); };
    document.addEventListener('keydown', esc); wrap.addEventListener('mousedown', e => { if (e.target === wrap) close(); }); wrap.querySelector('.ad-x').onclick = close;
    return { el: wrap, body: wrap.querySelector('.ad-modal-body'), close };
  }
  let toastTimer;
  function toast(msg, bad) {
    let t = $('#adToast'); if (!t) { t = document.createElement('div'); t.id = 'adToast'; t.setAttribute('role', 'status'); document.body.append(t); }
    t.textContent = msg; t.className = 'show' + (bad ? ' bad' : ''); clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.className = ''; }, 4200);
  }
  const ensureRecent = async () => (window.CVTaraEnsureRecent ? window.CVTaraEnsureRecent() : true);
  const download = (name, text, type) => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text], { type: type || 'text/plain' })); a.download = name; document.body.append(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500); };
  const csv = (rows, cols) => cols.join(',') + '\n' + rows.map(r => cols.map(c => '"' + String(r[c] ?? '').replace(/"/g, '""').replace(/^[=+\-@\t\r]/, "'$&") + '"').join(',')).join('\n');
  /* ================= console-wide list rule =================
     Every list in the owner console shows one page at a time (20 rows unless the owner picks another size).
     pager() draws the page links; grid() asks the server for one page with its search, filters, date range and sort,
     so nothing ever downloads or draws a whole table. */
  const SIZES = [10, 20, 50, 100];
  function pager(el, d, go) {
    if (!el) return;
    const total = Number(d.total) || 0, per = Number(d.per) || 20, pages = Math.max(1, Math.ceil(total / per)), page = Math.min(Math.max(1, Number(d.page) || 1), pages);
    if (!total) { el.innerHTML = ''; return; }
    const nums = [...new Set([1, page - 2, page - 1, page, page + 1, page + 2, pages])].filter(n => n >= 1 && n <= pages).sort((a, b) => a - b);
    const btn = (n, label, on, aria) => `<button type="button" class="button small ${on ? 'primary' : 'secondary'}" data-gp="${n}" ${n < 1 || n > pages || (on && label === String(n)) ? 'disabled' : ''} ${on ? 'aria-current="page"' : ''} aria-label="${aria || 'Page ' + n}">${label}</button>`;
    let links = '', prev = 0;
    nums.forEach(n => { if (n - prev > 1) links += '<span class="gp-gap">…</span>'; links += btn(n, String(n), n === page); prev = n; });
    const from = (page - 1) * per + 1, to = Math.min(total, page * per);
    el.classList.add('cv-pager');
    el.innerHTML = `<span class="gp-info">${from.toLocaleString('en-IN')}–${to.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')}</span>
      <span class="gp-links">${btn(page - 1, '‹ Prev', false, 'Previous page')}${links}${btn(page + 1, 'Next ›', false, 'Next page')}</span>
      <label class="gp-size">Rows <select aria-label="Rows per page">${SIZES.map(s => `<option ${s === per ? 'selected' : ''}>${s}</option>`).join('')}</select></label>`;
    el.onclick = e => { const b = e.target.closest('[data-gp]'); if (b && !b.disabled) go(Number(b.dataset.gp), per); };
    el.onchange = e => { if (e.target.matches('.gp-size select')) go(1, Number(e.target.value)); };
  }
  const memo = new Map();
  // host must be an element the grid owns: its click/input/change handlers are replaced on every call.
  function grid(host, spec) {
    if (!host) return null;
    const id = spec.id || spec.url, cols = spec.columns, F = spec.filters || [];
    const st = memo.get(id) || { q: '', page: 1, per: spec.per || 20, sort: spec.sort || '', dir: spec.dir || 'desc', f: {} };
    memo.set(id, st);
    if (spec.q !== undefined) { st.q = spec.q; st.page = 1; }
    const field = f => f.type === 'date' ? `<label class="ops-f">${E(f.label)}<input type="date" data-gf="${f.key}" value="${E(st.f[f.key] || '')}"></label>`
      : `<label class="ops-f">${E(f.label)}<select data-gf="${f.key}"><option value="all">All</option>${(typeof f.options === 'function' ? f.options() : f.options).map(o => `<option value="${E(o[0])}" ${st.f[f.key] === String(o[0]) ? 'selected' : ''}>${E(o[1])}</option>`).join('')}</select></label>`;
    host.innerHTML = `<div class="cv-grid">${spec.search === false && !F.length && !spec.tools ? '' : `<div class="cv-grid-bar ops-filterbox">${spec.search === false ? '' : `<label class="ops-f grow">Search<input type="search" data-gq value="${E(st.q)}" placeholder="${E(spec.search || 'Search')}"></label>`}${F.map(field).join('')}${F.length || st.q ? '<button type="button" class="button secondary small" data-greset>Clear</button>' : ''}${spec.tools || ''}</div>`}
      <div class="cv-grid-wrap"><table class="ad-table ${spec.cls || ''}" data-kit="grid"><thead><tr>${cols.map(c => `<th class="${c.cls || ''} ${c.sort ? 'kit-sort' : ''}" ${c.sort ? `data-gs="${c.sort}" tabindex="0" aria-sort="${st.sort === c.sort ? (st.dir === 'asc' ? 'ascending' : 'descending') : 'none'}"` : ''}>${E(c.label)}</th>`).join('')}</tr></thead><tbody><tr><td colspan="${cols.length}"><div class="ops-skel" style="height:48px;margin:0"></div></td></tr></tbody></table></div>
      <div class="ops-pager"></div></div>`;
    const body = $('tbody', host), pg = $('.ops-pager', host);
    let seq = 0, timer = null;
    const api_ = { state: st, data: null, reload, host };
    async function reload() {
      const n = ++seq;
      const qs = new URLSearchParams({ page: st.page, per: st.per, ...(spec.params ? spec.params() : {}) });
      if (st.q) qs.set('q', st.q); if (st.sort) { qs.set('sort', st.sort); qs.set('dir', st.dir); }
      Object.entries(st.f).forEach(([k, v]) => { if (v && v !== 'all') qs.set(k, v); });
      body.setAttribute('aria-busy', 'true');
      try {
        const d = await api(spec.url + (spec.url.includes('?') ? '&' : '?') + qs);
        if (n !== seq) return;
        const rows = d[spec.rowsKey || 'rows'] || [];
        api_.data = d; st.page = d.page || st.page;
        body.innerHTML = rows.map(r => `<tr ${spec.row ? spec.row(r) : ''}>${cols.map(c => `<td class="${c.cls || ''}">${c.html ? c.html(r) : E(r[c.key])}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="${cols.length}">${E(st.q || Object.values(st.f).some(v => v && v !== 'all') ? 'Nothing matches these filters.' : (spec.empty || 'Nothing here yet.'))}</td></tr>`;
        pager(pg, { total: d.total, per: d.per || st.per, page: d.page }, (p, per) => { st.page = p; st.per = per; reload(); host.scrollIntoView({ block: 'nearest' }); });
        if (spec.onData) spec.onData(d, rows);
      } catch (e) { if (n === seq) body.innerHTML = `<tr><td colspan="${cols.length}" role="alert">${E((e && e.message) || 'Could not load this list.')}</td></tr>`; }
      finally { if (n === seq) body.removeAttribute('aria-busy'); }
    }
    host.oninput = e => { if (e.target.matches('[data-gq]')) { clearTimeout(timer); timer = setTimeout(() => { st.q = e.target.value.trim(); st.page = 1; reload(); }, 350); } };
    host.onchange = e => { const k = e.target.dataset.gf; if (k) { st.f[k] = e.target.value; st.page = 1; reload(); } };
    const sortBy = th => { const s = th.dataset.gs; st.dir = st.sort === s && st.dir === 'desc' ? 'asc' : 'desc'; st.sort = s; st.page = 1; $$('th[data-gs]', host).forEach(t => t.setAttribute('aria-sort', t === th ? (st.dir === 'asc' ? 'ascending' : 'descending') : 'none')); reload(); };
    host.onclick = e => {
      const th = e.target.closest('th[data-gs]'); if (th) return sortBy(th);
      if (e.target.closest('[data-greset]')) { st.q = ''; st.f = {}; st.page = 1; $$('[data-gf]', host).forEach(x => { x.value = x.type === 'date' ? '' : 'all'; }); const q = $('[data-gq]', host); if (q) q.value = ''; reload(); }
      if (spec.onClick) spec.onClick(e, api_);
    };
    host.onkeydown = e => { const th = e.target.closest && e.target.closest('th[data-gs]'); if (th && e.key === 'Enter') sortBy(th); if (spec.onKey) spec.onKey(e, api_); };
    reload();
    return api_;
  }
  window.CVTaraAdminKit = { $, $$, E, api, inr, short, parse, dmy, pill, planName, modal, toast, ensureRecent, download, csv, pager, grid, SIZES, META, HUB, register: (k, fn, meta) => { render[k] = fn; if (meta) META[k] = meta; }, open: k => open(k) };

  function open(k) {
    // The old 'users' view was a second, unpaged copy of the user list; send it to the paged Users view.
    if (k === 'users') { const b = $('.side-nav [data-admin-view="people"]'); if (b) { b.click(); return; } }
    current = k; title(k); banner(k);
    const lv = $('#opsLive'); if (lv && !lv.dataset.manual) lv.classList.toggle('min', k !== 'dashboard');
    $$('.side-nav [data-admin-view]').forEach(b => b.classList.toggle('active', b.dataset.adminView === (HUB.includes(k) ? 'settings' : k)));
    if (render[k]) render[k]();
    const c = $('.admin-content'); if (c) c.scrollTop = 0;
  }
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-ops-toggle]');
    if (t) { const sub = $('#opsNav'); const open = sub.classList.toggle('closed') === false; t.setAttribute('aria-expanded', String(open)); t.querySelector('em').textContent = open ? '▲' : '▼'; return; }
    const b = e.target.closest('[data-admin-view]'); if (!b) return;
    setTimeout(() => open(b.dataset.adminView), 20);
  });
  addEventListener('load', () => setTimeout(() => { Object.keys(META).forEach(banner); settings(); const first = $('.side-nav [data-admin-view="dashboard"]'); if (first) first.click(); const D = window.CVTARA_DATA; const cnt = (v, n) => { const el = $('.side-nav [data-admin-view="' + v + '"] .nav-count'); if (el && n) el.textContent = n; }; if (D) { cnt('resumelib', D.templates.length); cnt('letterlib', D.coverLetters.length); cnt('bloghub', (D.blog || []).length); }
    Promise.resolve(dashboard()).catch(() => 0).finally(() => { document.body.classList.add('admin-ready'); live(); setInterval(live, 30000); }); }, 400));
  // banners must survive panels re-rendering themselves
  new MutationObserver(() => { const v = $('.admin-view.active'); if (v && !v.querySelector(':scope > .ops-banner')) banner(v.id.replace('admin-', '')); }).observe(document.body, { childList: true, subtree: true });
})();
