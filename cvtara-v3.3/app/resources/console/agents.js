/* Owner console: Growth page tabs for the SEO agent, the revenue (growth) agent and the agent log (3.34.0).
   Every number comes from the server; anything the site cannot measure shows as "Not tracked". */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const n = x => x === null || x === undefined ? 'Not tracked' : Number(x).toLocaleString('en-IN');
  const inr = p => p === null || p === undefined ? 'Not tracked' : '₹' + Math.round(Number(p) / 100).toLocaleString('en-IN');
  const when = t => t ? new Date(String(t).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(t)) ? '' : 'Z')).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—';
  const yes = v => v === null || v === undefined ? '<span class="muted">not checked</span>' : v ? '<span class="pill ok">Yes</span>' : '<span class="pill bad">No</span>';
  const SEV = { high: 'bad', medium: 'mid', low: '', info: 'ok' };
  const kpi = (label, value, sub = '') => `<div class="kpi"><small>${E(label)}</small><b${/^Not (tracked|checked yet)$/.test(String(value)) ? ' style="font-size:1.1rem"' : ''}>${value}</b><small>${sub}</small></div>`;
  const table = (head, rows, empty) => rows.length ? `<div style="overflow:auto"><table class="ad-table"><thead><tr>${head.map(h => `<th>${E(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>` : `<p class="muted">${E(empty)}</p>`;
  const P = { page: 1, per: 20, q: '', issues: 'all', index: 'all', important: 'all', sort: 'impressions' };
  const KW = { page: 1, per: 20, q: '', kind: 'all', band: 'all', sort: 'impressions' };
  const LG = { page: 1, per: 20, agent: 'all', status: 'all' };

  // ---------------------------------------------------------------- agent status strip
  function strip(s, name) {
    const st = { running: ['Running', 'ok'], paused: ['Paused', 'mid'], off: ['Not connected', 'bad'] }[s.status] || [s.status, ''];
    return `<div class="ops-kpis">
      ${kpi(name, `<span class="pill ${st[1]}">${E(st[0])}</span>`, s.failing && s.failing.length ? '<span class="pill bad">Last ' + E(s.failing.map(f => f.task).join(', ')) + ' failed</span>' : 'last run ' + E(when(s.lastRun)))}
      ${kpi('Next run', E(when(s.nextRun)), '')}
      ${kpi('Tasks done (7 days)', n(s.tasksDone7d), n(s.tasksFailed7d) + ' failed')}
      ${kpi('Waiting for you', n(s.approvalsWaiting.length), 'approvals')}
      ${kpi('AI spending this month', inr(s.spendMonthPaise), '')}
    </div>`;
  }

  async function toggle(agent, auto, done) {
    try { await api('/api/admin/growth/agents', { method: 'POST', body: { agent, auto } }); toast(auto ? 'Resumed' : 'Paused'); done(); } catch (e) { toast(err(e)); }
  }

  // ---------------------------------------------------------------- SEO agent
  async function seo(h) {
    h.innerHTML = '<div class="ops-skel"></div>';
    let A, O;
    try { [A, O] = await Promise.all([api('/api/admin/growth/agents'), api('/api/admin/growth/seo/overview')]); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const k = O.kpis, b = O.brand, s = A.seo;
    const sm = (O.sitemaps.list || []);
    h.innerHTML = `${strip(s, 'SEO agent')}
      <p><button class="button secondary small" id="agSeoToggle">${s.status === 'paused' ? 'Resume the SEO agent' : 'Pause the SEO agent'}</button> <button class="button secondary small" id="agScan">Scan pages now</button> <button class="button secondary small" id="agInspect">Check Google index now</button></p>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Results</h3>
        <div class="ops-kpis">
          ${kpi('Google clicks', n(k.clicks), E(k.searchPeriod || 'Search Console not connected'))}
          ${kpi('Google impressions', n(k.impressions), k.ctr === null ? '' : k.ctr + '% clicked')}
          ${kpi('Searches for CVTara by name', n(k.brandImpressions), n(k.brandClicks) + ' clicks')}
          ${kpi('Other searches', n(k.nonBrandImpressions), n(k.nonBrandClicks) + ' clicks')}
          ${kpi('Important pages indexed', k.importantIndexed === null ? 'Not checked yet' : n(k.importantIndexed) + ' / ' + n(k.importantInspected), n(k.importantPages) + ' important pages')}
          ${kpi('Technical issues open', n(k.issuesOpen), n(k.issuesResolved30d) + ' resolved in 30 days')}
          ${kpi('Sign-ups from Google/Bing (30 days)', n(k.organicSignups30d), n(k.signupsWithSource30d) + ' of ' + n(k.signups30d) + ' sign-ups have a known source')}
          ${kpi('Their paid customers / revenue', n(k.organicBuyers30d), inr(k.organicNetPaise30d) + ' (attributed)')}
          ${kpi('Changes you approved', k.approvalRate === null ? '—' : k.approvalRate + '%', n(k.approved90d) + ' approved, ' + n(k.rejected90d) + ' rejected (90 days)')}
        </div><p class="muted" style="margin-bottom:0">${E(k.notTracked)}</p></section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Brand result for "CVTara"</h3><p class="muted" style="margin-top:0">${E(b.note)}</p>
        ${table(['Check', 'OK', 'Detail'], b.checks.map(c => `<tr><td>${E(c.check)}</td><td>${yes(c.ok)}</td><td>${E(c.detail || '')}</td></tr>`), 'The home page has not been scanned yet.')}
        ${table(['Main page', 'Page', 'Live', 'Linked from home', 'Indexed', 'Note'], b.sitelinks.map(l => `<tr><td>${E(l.label)}</td><td>${l.path ? `<code>${E(l.path)}</code>` : '—'}</td><td>${yes(l.path ? l.exists : false)}</td><td>${yes(l.path ? l.linkedFromHome : null)}</td><td>${yes(l.indexed)}</td><td class="muted">${E(l.note || '')}</td></tr>`), '')}
        ${b.brandSearches.length ? table(['Brand search', 'Impressions', 'Clicks', 'Position'], b.brandSearches.map(r => `<tr><td>${E(r.query)}</td><td>${n(r.impressions)}</td><td>${n(r.clicks)}</td><td>${r.position}</td></tr>`), '') : ''}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Pages and Google index</h3>
        <p class="muted" style="margin-top:0">Every sitemap page, scanned like a visitor sees it, with Google's own index status (URL Inspection). ${O.site.scanned} of ${O.site.pages} pages scanned. Google URL checks today: ${n((O.inspectQuota && O.inspectQuota.used) || 0)} of ${n(O.ops.inspectPerDay)}. ${O.site.note ? E(O.site.note) : ''}</p>
        <div class="ops-filters" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
          <input type="search" id="pgQ" placeholder="Search page or title" value="${E(P.q)}">
          <select id="pgIssues"><option value="all">Issues: any</option><option value="yes">With issues</option><option value="no">No issues</option></select>
          <select id="pgIndex"><option value="all">Google: any</option><option value="yes">Indexed</option><option value="no">Not indexed</option><option value="unchecked">Not checked yet</option></select>
          <select id="pgImp"><option value="all">All pages</option><option value="yes">Important only</option></select>
          <select id="pgSort"><option value="impressions">Most impressions</option><option value="clicks">Most clicks</option><option value="issues">Most issues</option><option value="linksIn">Fewest links in</option><option value="path">Address</option></select>
          <a class="button secondary small" id="pgCsv" href="#">Download CSV</a></div>
        <div id="pgT"><div class="ops-skel"></div></div><div class="ops-pager" id="pgP"></div></section>
      <div class="ops-two">
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Site-wide</h3>
          <p>Duplicate titles: <b>${O.site.duplicateTitles.length}</b> · Duplicate descriptions: <b>${O.site.duplicateDescriptions.length}</b> · Orphan pages (no links in): <b>${O.site.orphans === null ? '—' : O.site.orphans.length}</b> · Pages with only one link in: <b>${O.site.weaklyLinked === null ? '—' : O.site.weaklyLinked}</b></p>
          ${O.site.linkedButNotInSitemap.length ? `<p>Linked from the site but missing from the sitemap:</p><ul>${O.site.linkedButNotInSitemap.slice(0, 15).map(p => `<li><code>${E(p)}</code></li>`).join('')}</ul>` : '<p class="muted">Every linked content page is in the sitemap.</p>'}
          ${O.site.orphans && O.site.orphans.length ? `<p>Orphan pages:</p><ul>${O.site.orphans.slice(0, 15).map(p => `<li><code>${E(p)}</code></li>`).join('')}</ul>` : ''}</section>
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Sitemaps in Search Console</h3>
          ${sm.length ? table(['Sitemap', 'URLs', 'Errors', 'Warnings', 'Google read it'], sm.map(m => `<tr><td>${E(m.path)}</td><td>${n(m.submitted)}</td><td>${n(m.errors)}</td><td>${n(m.warnings)}</td><td>${E(when(m.lastDownloaded))}${m.pending ? ' (pending)' : ''}</td></tr>`), '') : '<p class="muted">Not read yet: the agent reads it on its daily run once Search Console is connected.</p>'}</section>
      </div>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Topic clusters</h3><p class="muted" style="margin-top:0">Each product page and the guides that support it. Guides should link to their product page and the product page to its guides. New topics need your approval before anything is written.</p>
        ${O.clusters.map(c => `<details><summary><b>${E(c.cluster)}</b> · ${c.main ? `<code>${E(c.main)}</code>` : '<span class="pill mid">no product page yet</span>'} · ${c.linkGaps ? `<span class="pill mid">${c.linkGaps} link gap(s)</span>` : '<span class="pill ok">linked</span>'}</summary>
          ${table(['Supporting page', 'Live', 'Links to product page', 'Product page links here', 'Indexed'], c.support.map(s => `<tr><td><code>${E(s.path)}</code></td><td>${yes(s.exists)}</td><td>${yes(s.linksToMain)}</td><td>${yes(s.mainLinksHere)}</td><td>${yes(s.indexed)}</td></tr>`), '')}
          <p class="muted">Suggested topics not covered yet: ${c.suggestedTopics.map(E).join(' · ')}</p></details>`).join('')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Keyword opportunities</h3>
        ${Object.entries({ lowCtr: 'Seen but rarely clicked', pageTwo: 'On page 2', deep: 'Beyond page 2', wrongPage: 'Google shows a different page than planned' }).map(([key, label]) => { const o = O.opportunities[key]; return `<details><summary><b>${E(label)}</b> (${o.rows.length})</summary><p class="muted">${E(o.why)}</p>${table(['Search', 'Impressions', 'Clicks', 'Position', 'Page shown', 'Planned page'], o.rows.map(r => `<tr><td>${E(r.keyword)}</td><td>${n(r.impressions)}</td><td>${n(r.clicks)}</td><td>${r.position}</td><td><code>${E(r.page)}</code></td><td><code>${E(r.target)}</code></td></tr>`), 'None right now.')}</details>`; }).join('')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Keyword database</h3>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px"><input type="search" id="kwQ" placeholder="Search keyword or page" value="${E(KW.q)}">
          <select id="kwKind"><option value="all">All types</option><option value="brand">CVTara (brand)</option><option value="product">Product</option><option value="commercial">Commercial</option><option value="informational">Informational</option><option value="question">Question</option><option value="long_tail">Long-tail</option></select>
          <select id="kwBand"><option value="all">Any position</option><option value="top">Page 1</option><option value="two">Page 2</option><option value="far">Beyond page 2</option><option value="none">Not seen yet</option></select>
          <button class="button secondary small" id="kwAdd">Add a target keyword</button> <a class="button secondary small" id="kwCsv" href="#">Download CSV</a></div>
        <div id="kwT"><div class="ops-skel"></div></div><div class="ops-pager" id="kwP"></div>
        <p class="muted">Type, intent and business value are set by rules from the words of the search. Search volume and difficulty are not tracked (they need a paid keyword tool).</p></section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Did approved changes work?</h3>
        ${table(['Change', 'Page', 'Approved', 'Before (clicks / impr. / pos.)', '4+ weeks after', 'Result'], O.results.map(r => `<tr><td>${r.kind === 'meta' ? 'Title and description' : 'Internal links'}${r.status === 'reverted' ? ' (undone)' : ''}</td><td><code>${E(r.path)}</code></td><td>${E(when(r.decidedAt))}</td><td>${r.before ? `${n(r.before.clicks)} / ${n(r.before.impressions)} / ${r.before.position}` : '—'}</td><td>${r.after ? `${n(r.after.clicks)} / ${n(r.after.impressions)} / ${r.after.position}` : '—'}</td><td>${E(r.verdict)}</td></tr>`), 'No approved changes yet. Proposals wait for you in the SEO agent box on the Overview tab.')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Sign-ups and sales by first page (Google and Bing visitors, 30 days)</h3>
        ${table(['First page', 'Visitors', 'Sign-ups', 'Sign-up rate', 'Buyers', 'Revenue (attributed)'], O.landing.map(r => `<tr><td><code>${E(r.page)}</code></td><td>${n(r.visitors)}</td><td>${n(r.signups)}</td><td>${r.signupRate === null ? '—' : r.signupRate + '%'}</td><td>${n(r.buyers)}</td><td>${inr(r.netPaise)}</td></tr>`), 'No sign-ups with a known first page yet. They are recorded from 3.34.0 on, for visitors who allow analytics.')}</section>`;
    $('#agSeoToggle', h).onclick = () => toggle('seo', s.status === 'paused', () => seo(h));
    $('#agScan', h).onclick = async e => { e.target.disabled = true; try { const r = await api('/api/admin/growth/seo/scan', { method: 'POST', body: {} }); toast(r.summary || 'Done'); seo(h); } catch (x) { toast(err(x)); e.target.disabled = false; } };
    $('#agInspect', h).onclick = async e => { e.target.disabled = true; try { const r = await api('/api/admin/growth/seo/inspect', { method: 'POST', body: {} }); toast(r.summary || 'Done'); seo(h); } catch (x) { toast(err(x)); e.target.disabled = false; } };
    ['Issues', 'Index', 'Imp', 'Sort'].forEach(id => { const el = $('#pg' + id, h); const key = { Issues: 'issues', Index: 'index', Imp: 'important', Sort: 'sort' }[id]; el.value = P[key]; el.onchange = () => { P[key] = el.value; P.page = 1; pages(h); }; });
    $('#pgQ', h).onchange = e => { P.q = e.target.value.trim(); P.page = 1; pages(h); };
    ['Kind', 'Band'].forEach(id => { const el = $('#kw' + id, h); const key = id.toLowerCase(); el.value = KW[key]; el.onchange = () => { KW[key] = el.value; KW.page = 1; keywords(h); }; });
    $('#kwQ', h).onchange = e => { KW.q = e.target.value.trim(); KW.page = 1; keywords(h); };
    $('#kwAdd', h).onclick = () => addKeyword(h);
    pages(h); keywords(h);
  }

  function qs(o, keys) { const q = new URLSearchParams({ page: String(o.page), per: String(o.per) }); keys.forEach(k => { if (o[k] && o[k] !== 'all') q.set(k, o[k]); }); return q; }

  async function pages(h) {
    const box = $('#pgT', h); if (!box) return;
    const q = qs(P, ['q', 'issues', 'index', 'important', 'sort']); if (P.sort === 'linksIn' || P.sort === 'path') q.set('dir', 'asc');
    $('#pgCsv', h).href = '/api/admin/growth/seo/pages?' + q + '&export=csv';
    try {
      const d = await api('/api/admin/growth/seo/pages?' + q);
      box.innerHTML = table(['Page', 'Google', 'Impr.', 'Clicks', 'Pos.', 'Links in', 'Issues', ''], d.rows.map(r => `<tr><td><code>${E(r.path)}</code>${r.important ? ' <span class="pill">important</span>' : ''}<br><small class="muted">${E(r.title)}</small></td>
        <td>${r.verdict === 'PASS' ? '<span class="pill ok">Indexed</span>' : r.verdict ? `<span class="pill bad">${E(r.coverage || r.verdict)}</span>` : r.inspectError ? `<span class="pill mid" title="${E(r.inspectError)}">check failed</span>` : '<span class="muted">not checked</span>'}</td>
        <td>${n(r.impressions)}${r.impressionsChange !== null ? `<br><small class="muted">${r.impressionsChange >= 0 ? '+' : ''}${n(r.impressionsChange)}</small>` : ''}</td><td>${n(r.clicks)}</td><td>${r.position === null ? '—' : r.position}</td><td>${n(r.linksIn)}</td>
        <td>${r.issueCount ? `<ul style="margin:0;padding-left:16px">${Object.values(r.issues).slice(0, 4).map(i => `<li><small>${E(i)}</small></li>`).join('')}</ul>` : '<span class="pill ok">none</span>'}</td>
        <td><button class="button secondary small" data-path="${E(r.path)}">History</button></td></tr>`), 'No pages scanned yet. Press "Scan pages now", or wait for the agent (it scans 40 pages every 10 minutes).');
      box.querySelectorAll('button[data-path]').forEach(b => { b.onclick = () => history(b.dataset.path); });
      K.pager($('#pgP', h), d, (p, per) => { P.page = p; P.per = per; pages(h); });
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  async function history(path) {
    try {
      const d = await api('/api/admin/growth/seo/page?path=' + encodeURIComponent(path));
      const p = d.page || {};
      modal('Page history: ' + path, `<p>Google: <b>${E(p.coverage || p.verdict || 'not checked yet')}</b>${p.last_crawl_at ? ' · last crawled ' + E(when(p.last_crawl_at)) : ''}${p.google_canonical ? ' · Google\'s canonical: <code>' + E(p.google_canonical) + '</code>' : ''}${p.index_requested_at ? ' · recrawl asked ' + E(when(p.index_requested_at)) : ''}</p>
        <p><a href="${E(d.inspectLink)}" target="_blank" rel="noopener">Open in Search Console (Request indexing is there)</a></p>
        <h4>Week by week (each week = the 4 weeks before it)</h4>${table(['Week', 'Impressions', 'Clicks', 'Clicked %', 'Position'], d.weeks.map(w => `<tr><td>${E(w.week)}</td><td>${n(w.impressions)}</td><td>${n(w.clicks)}</td><td>${Math.round(w.ctr * 1000) / 10}%</td><td>${w.position}</td></tr>`), 'No Search Console numbers for this page yet.')}
        <h4>Agent actions</h4>${table(['Proposed', 'Change', 'Status', 'Why'], d.actions.map(a => `<tr><td>${E(when(a.created_at))}</td><td>${a.kind === 'meta' ? 'Title and description' : 'Internal links'}</td><td>${E(a.status)}</td><td><small>${E(a.reason)}</small></td></tr>`), 'None yet.')}`);
    } catch (e) { toast(err(e)); }
  }

  async function keywords(h) {
    const box = $('#kwT', h); if (!box) return;
    const q = qs(KW, ['q', 'kind', 'band', 'sort']); if (KW.band === 'none') q.set('band', 'none');
    $('#kwCsv', h).href = '/api/admin/growth/seo/keywords?' + q + '&export=csv';
    try {
      const d = await api('/api/admin/growth/seo/keywords?' + q);
      box.innerHTML = table(['Keyword', 'Type', 'Intent', 'Value', 'Impr.', 'Clicks', 'Position', 'Planned page', 'Google shows'], d.rows.map(r => `<tr><td>${E(r.keyword)}${r.source === 'seed' ? ' <span class="pill">target</span>' : ''}</td><td>${E(String(r.kind).replace('_', '-'))}</td><td>${E(r.intent)}</td><td>${E(r.value)}</td><td>${n(r.impressions)}</td><td>${n(r.clicks)}</td>
        <td>${r.position === null ? '—' : Number(r.position)}${r.prev_position !== null && r.position !== null ? `<br><small class="muted">${(Number(r.prev_position) - Number(r.position)) >= 0 ? '▲' : '▼'} ${Math.abs(Math.round((Number(r.prev_position) - Number(r.position)) * 10) / 10)}</small>` : ''}</td><td><code>${E(r.target || '—')}</code></td><td><code>${E(r.ranking_page || '—')}</code></td></tr>`), 'No keywords yet: the list fills on the next daily run.');
      K.pager($('#kwP', h), d, (p, per) => { KW.page = p; KW.per = per; keywords(h); });
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  function addKeyword(h) {
    const m = modal('Add a target keyword', `<form class="ops-form" id="kwF"><label class="wide">Keyword<input name="keyword" required maxlength="190"></label><label class="wide">Page it should rank with (optional)<input name="target" placeholder="/resume-builder/"></label><label class="wide">Notes<input name="notes" maxlength="300"></label><div class="wide"><button class="button primary">Save</button> <span role="status" id="kwSt"></span></div></form>`);
    const f = $('#kwF', m.el);
    f.onsubmit = async e => { e.preventDefault(); try { await api('/api/admin/growth/seo/keyword', { method: 'POST', body: { keyword: f.keyword.value, target: f.target.value.trim() || null, notes: f.notes.value } }); toast('Saved'); m.close(); keywords(h); } catch (x) { $('#kwSt', m.el).textContent = err(x); } };
  }

  // ---------------------------------------------------------------- growth (revenue) agent
  let RD = 30;
  async function revenue(h, fresh) {
    h.innerHTML = '<div class="ops-skel"></div>';
    let A, R, C, X;
    try { [A, R, C, X] = await Promise.all([api('/api/admin/growth/agents'), api('/api/admin/growth/revenue?days=' + RD + (fresh ? '&fresh=1' : '')), api('/api/admin/growth/campaign-plans'), api('/api/admin/growth/experiments')]); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const k = R.kpis, s = A.growth, f = R.funnel.steps, dg = R.diagnosis || {};
    h.innerHTML = `${strip(s, 'Growth agent')}
      <p><button class="button secondary small" id="agGrToggle">${s.status === 'paused' ? 'Resume the growth agent' : 'Pause the growth agent'}</button> <button class="button secondary small" id="agDiag">Diagnose now</button>
        <label class="muted">Period <select id="agDays"><option value="7">7 days</option><option value="30">30 days</option><option value="90">90 days</option></select></label></p>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Results (last ${R.days} days)</h3><div class="ops-kpis">
        ${kpi('Confirmed revenue', inr(k.confirmedNetPaise), 'after refunds · gross ' + inr(k.grossPaise))}
        ${kpi('Paid orders', n(k.transactions), 'average ' + inr(k.aovPaise))}
        ${kpi('Sign-ups', n(k.registrations), n(k.activated) + ' activated')}
        ${kpi('Free to paid', k.freeToPaid === null ? '—' : k.freeToPaid + '%', 'of new sign-ups')}
        ${kpi('Repeat purchases', n(k.repeatPurchases), 'add-on / repeat revenue ' + inr(k.upsellPaise))}
        ${kpi('Attributed to campaigns', inr(k.attributedNetPaise), 'not confirmed cause · cost ₹' + n(k.campaignCostInr))}
        ${kpi('Cost per new customer', k.costPerNewCustomerInr === null ? 'Not tracked' : '₹' + n(k.costPerNewCustomerInr), 'from costs you enter')}
        ${kpi('Outreach', n(k.outreachSent) + ' sent', (k.outreachClickRate === null ? '—' : k.outreachClickRate + '% clicked') + ' · ' + n(k.outreachSignups) + ' signed up')}
        ${kpi('Refunds', k.refundRate === null ? '—' : k.refundRate + '%', 'complaints ' + (k.complaintsPer100Orders === null ? '—' : k.complaintsPer100Orders + ' per 100 orders'))}
        ${kpi('Ad spend', 'Not tracked', 'no ad account connected')}
      </div><p class="muted" style="margin-bottom:0">${E(k.notTracked)}</p></section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Customer journey</h3><p class="muted" style="margin-top:0">${E(R.funnel.note)}</p>
        ${table(['Step', 'People', 'Of the step before', 'Lost here'], f.map(x => `<tr><td>${E(x.label)}</td><td>${n(x.value)}</td><td>${x.fromPrevious === null ? '—' : x.fromPrevious + '%'}</td><td>${x.lost === null ? '—' : n(x.lost)}</td></tr>`), '')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Why revenue is where it is</h3><p class="muted" style="margin-top:0">Worked out ${E(when(dg.at || dg.workedOutAt))}. Each finding gives the likely cause, the evidence, the fix, the expected effect and how to measure it. Changes to pages, prices or messages are yours to approve.</p>
        ${(dg.findings || []).map(x => `<details ${x.sev === 'high' ? 'open' : ''}><summary><span class="pill ${SEV[x.sev] || ''}">${E(x.area)}</span> <b>${E(x.finding)}</b>${x.atStakePaise ? ' · about ' + inr(x.atStakePaise) + ' at stake' : ''}</summary>
          <p><b>Likely cause:</b> ${E(x.cause)}</p><ul>${(x.evidence || []).map(v => `<li>${E(v)}</li>`).join('')}</ul><p><b>Fix:</b> ${E(x.action)}</p>${x.impact ? `<p><b>Expected effect:</b> ${E(x.impact)}</p>` : ''}<p><b>Measure:</b> ${E(x.measure)}</p></details>`).join('') || '<p class="muted">No diagnosis yet.</p>'}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Sign-ups and sales by first page (all sources)</h3>
        ${table(['First page', 'Visitors', 'Sign-ups', 'Sign-up rate', 'Buyers', 'Buy rate', 'Revenue (attributed)'], R.landing.map(r => `<tr><td><code>${E(r.page)}</code></td><td>${n(r.visitors)}</td><td>${n(r.signups)}</td><td>${r.signupRate === null ? '—' : r.signupRate + '%'}</td><td>${n(r.buyers)}</td><td>${r.buyRate === null ? '—' : r.buyRate + '%'}</td><td>${inr(r.netPaise)}</td></tr>`), 'Recorded from 3.34.0 on, for visitors who allow analytics.')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Campaign register</h3><p class="muted" style="margin-top:0">Every marketing activity on any channel: who it is for, the problem, the offer, the landing page, cost and result. Email, outreach and LinkedIn campaigns must say how permission to contact was obtained.</p>
        <p><button class="button primary small" id="cpAdd">Add a campaign</button></p>
        ${table(['Campaign', 'Channel', 'Status', 'Reached / sent', 'Sign-ups', 'Buyers', 'Attributed revenue', 'Cost', ''], C.rows.map(c => `<tr><td><b>${E(c.name)}</b><br><small class="muted">${E(c.audience)}</small></td><td>${E(c.channelLabel)}</td><td>${E(c.status)}</td><td>${n(c.result.sent)}${c.result.clicks !== null ? `<br><small>${n(c.result.clicks)} clicked</small>` : ''}</td><td>${n(c.result.signups)}</td><td>${n(c.result.buyers)}</td><td>${inr(c.result.attributedNetPaise)}</td><td>₹${n(c.cost_inr)}${c.result.costPerBuyerInr !== null ? `<br><small>₹${n(c.result.costPerBuyerInr)} per buyer</small>` : ''}</td><td><button class="button secondary small" data-cp="${E(c.id)}">Edit</button></td></tr>`), 'No campaigns registered yet.')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Experiments</h3><p class="muted" style="margin-top:0">One change at a time, measured against the same number of days just before it started.</p>
        <p><button class="button primary small" id="exAdd">Add an experiment</button></p>
        ${table(['Experiment', 'Measures', 'Dates', 'Baseline', 'Result', 'Change', 'Status', 'Decision', ''], X.rows.map(e => { const fmt = v => v === null || v === undefined ? '—' : /Paise$/.test(e.metric) ? inr(v) : /Rate|activation/.test(e.metric) ? v + '%' : n(v); return `<tr><td><b>${E(e.title)}</b><br><small class="muted">${E(e.hypothesis)}</small></td><td>${E(e.metricLabel)}</td><td>${E(e.start_date)} → ${E(e.end_date)}</td><td>${fmt(e.baseline && e.baseline.value)}</td><td>${fmt(e.result && e.result.value)}</td><td>${e.result && e.result.change !== null && e.result.change !== undefined ? (e.result.change > 0 ? '+' : '') + e.result.change + '%' : '—'}</td><td>${E(e.status)}</td><td>${E(e.decision || (e.status === 'finished' ? 'waiting for you' : '—'))}</td><td><button class="button secondary small" data-ex="${E(e.id)}">Edit</button></td></tr>`; }), 'No experiments yet.')}</section>`;
    const days = $('#agDays', h); days.value = String(RD); days.onchange = () => { RD = Number(days.value); revenue(h); };
    $('#agGrToggle', h).onclick = () => toggle('growth', s.status === 'paused', () => revenue(h));
    $('#agDiag', h).onclick = async e => { e.target.disabled = true; try { await api('/api/admin/growth/diagnose', { method: 'POST', body: {} }); revenue(h, true); } catch (x) { toast(err(x)); e.target.disabled = false; } };
    $('#cpAdd', h).onclick = () => campaignForm(h, R, null);
    h.querySelectorAll('[data-cp]').forEach(b => { b.onclick = () => campaignForm(h, R, C.rows.find(c => c.id === b.dataset.cp)); });
    $('#exAdd', h).onclick = () => experimentForm(h, R, null);
    h.querySelectorAll('[data-ex]').forEach(b => { b.onclick = () => experimentForm(h, R, X.rows.find(c => c.id === b.dataset.ex)); });
  }

  const opt = (o, v) => Object.entries(o).map(([k, l]) => `<option value="${E(k)}" ${k === v ? 'selected' : ''}>${E(l)}</option>`).join('');
  function campaignForm(h, R, c) {
    c = c || { status: 'planned', channel: 'seo' };
    const m = modal(c.id ? 'Edit campaign' : 'Add a campaign', `<form class="ops-form" id="cpF">
      <label class="wide">Name<input name="name" required maxlength="120" value="${E(c.name || '')}"></label>
      <label>Channel<select name="channel">${opt(R.channels, c.channel)}</select></label>
      <label>Status<select name="status">${opt({ planned: 'Planned', live: 'Live', done: 'Done', stopped: 'Stopped' }, c.status)}</select></label>
      <label class="wide">Target audience<input name="audience" maxlength="300" value="${E(c.audience || '')}"></label>
      <label class="wide">Customer problem<input name="problem" maxlength="500" value="${E(c.problem || '')}"></label>
      <label>Offer<input name="offer" maxlength="300" value="${E(c.offer || '')}"></label><label>Call to action<input name="cta" maxlength="120" value="${E(c.cta || '')}"></label>
      <label class="wide">Message and creative<textarea name="message" rows="3" maxlength="5000">${E(c.message || '')}</textarea></label>
      <label>Landing page<input name="landing" placeholder="/ats-checker/" value="${E(c.landing || '')}"></label><label>utm_campaign tag<input name="utm" placeholder="tpo-nov" value="${E(c.utm || '')}"></label>
      <label>Linked email campaign<select name="email_campaign_id"><option value="">None</option>${R.emailCampaigns.map(e => `<option value="${E(e.id)}" ${e.id === c.email_campaign_id ? 'selected' : ''}>${E(e.name)} (${E(e.status)})</option>`).join('')}</select></label>
      <label>Linked outreach list<select name="outreach_list"><option value="">None</option>${opt(R.lists, c.outreach_list)}</select></label>
      <label class="wide">How permission to contact was obtained<input name="consent" maxlength="300" value="${E(c.consent || '')}" placeholder="e.g. signed up and ticked tips; placement office asked for it"></label>
      <label>Budget (₹)<input name="budget_inr" type="number" min="0" value="${E(c.budget_inr || 0)}"></label><label>Actual cost (₹)<input name="cost_inr" type="number" min="0" value="${E(c.cost_inr || 0)}"></label>
      <label>Start<input name="start_date" type="date" value="${E(c.start_date || '')}"></label><label>End<input name="end_date" type="date" value="${E(c.end_date || '')}"></label>
      <label class="wide">Expected outcome<input name="expected" maxlength="300" value="${E(c.expected || '')}"></label>
      <label class="wide">Result note<input name="result_note" maxlength="500" value="${E(c.result_note || '')}"></label>
      <div class="wide"><button class="button primary">Save</button> <span role="status" id="cpSt"></span></div></form>`);
    const f = $('#cpF', m.el);
    f.onsubmit = async e => {
      e.preventDefault(); const b = Object.fromEntries(new FormData(f)); b.id = c.id || null; ['budget_inr', 'cost_inr'].forEach(k => { b[k] = Number(b[k] || 0); }); ['email_campaign_id', 'outreach_list', 'start_date', 'end_date', 'landing', 'utm'].forEach(k => { if (!b[k]) b[k] = null; });
      try { await api('/api/admin/growth/campaign-plan', { method: 'POST', body: b }); toast('Saved'); m.close(); revenue(h); } catch (x) { $('#cpSt', m.el).textContent = err(x); }
    };
  }

  function experimentForm(h, R, x) {
    x = x || { metric: 'signupRate' };
    const m = modal(x.id ? 'Edit experiment' : 'Add an experiment', `<form class="ops-form" id="exF">
      <label class="wide">Title<input name="title" required maxlength="160" value="${E(x.title || '')}"></label>
      <label class="wide">Hypothesis<input name="hypothesis" required maxlength="600" value="${E(x.hypothesis || '')}" placeholder="If we … then … because …"></label>
      <label class="wide">Change made<input name="change" maxlength="600" value="${E(x.change || '')}"></label>
      <label>Target segment<input name="segment" maxlength="200" value="${E(x.segment || '')}"></label><label>Success metric<select name="metric">${opt(R.metrics, x.metric)}</select></label>
      <label>Start<input name="start_date" type="date" required value="${E(x.start_date || '')}"></label><label>End<input name="end_date" type="date" required value="${E(x.end_date || '')}"></label>
      <label>Budget (₹)<input name="budget_inr" type="number" min="0" value="${E(x.budget_inr || 0)}"></label>
      <label>Decision<select name="decision"><option value="">Not decided</option>${opt({ continue: 'Continue', improve: 'Improve', stop: 'Stop' }, x.decision)}</select></label>
      <label class="wide">Notes<input name="notes" maxlength="600" value="${E(x.notes || '')}"></label>
      <div class="wide"><button class="button primary">Save</button> <span role="status" id="exSt"></span></div></form>`);
    const f = $('#exF', m.el);
    f.onsubmit = async e => {
      e.preventDefault(); const b = Object.fromEntries(new FormData(f)); b.id = x.id || null; b.budget_inr = Number(b.budget_inr || 0); if (!b.decision) b.decision = null;
      try { await api('/api/admin/growth/experiment', { method: 'POST', body: b }); toast('Saved'); m.close(); revenue(h); } catch (y) { $('#exSt', m.el).textContent = err(y); }
    };
  }

  // ---------------------------------------------------------------- agent log
  async function log(h) {
    if (!$('#lgT', h)) {
      h.innerHTML = `<section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Agent log</h3><p class="muted" style="margin-top:0">Every task the SEO and growth agents ran, with its result or error. Tasks are work done, not results.</p>
        <div style="display:flex;gap:8px;margin-bottom:8px"><select id="lgA"><option value="all">Both agents</option><option value="seo">SEO agent</option><option value="growth">Growth agent</option></select><select id="lgS"><option value="all">Any result</option><option value="ok">Done</option><option value="failed">Failed</option><option value="skipped">Skipped</option></select><a class="button secondary small" id="lgCsv" href="#">Download CSV</a></div>
        <div id="lgT"></div><div class="ops-pager" id="lgP"></div></section>`;
      $('#lgA', h).onchange = e => { LG.agent = e.target.value; LG.page = 1; log(h); };
      $('#lgS', h).onchange = e => { LG.status = e.target.value; LG.page = 1; log(h); };
    }
    const q = qs(LG, ['agent', 'status']); $('#lgCsv', h).href = '/api/admin/growth/agents/log?' + q + '&export=csv';
    try {
      const d = await api('/api/admin/growth/agents/log?' + q);
      $('#lgT', h).innerHTML = table(['When', 'Agent', 'Task', 'Result', 'Took'], d.rows.map(r => `<tr><td>${E(when(r.started_at))}</td><td>${r.agent === 'seo' ? 'SEO' : 'Growth'}</td><td>${E(r.task)}</td><td>${r.status === 'failed' ? `<span class="pill bad">Failed</span> ${E(r.error)}` : r.status === 'skipped' ? `<span class="muted">Skipped: ${E(r.summary)}</span>` : E(r.summary)}</td><td>${(r.ms / 1000).toFixed(1)} s</td></tr>`), 'Nothing yet.');
      K.pager($('#lgP', h), d, (p, per) => { LG.page = p; LG.per = per; log(h); });
    } catch (e) { $('#lgT', h).innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  window.CVTaraAgents = { seo, revenue, log };
})();
