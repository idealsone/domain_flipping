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
  const P = { page: 1, per: 20, q: '', issues: 'all', index: 'all', important: 'all', audit: 'all', sort: 'impressions' };
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
          <select id="pgAudit"><option value="all">Audit: any</option><option value="poor">Poor</option><option value="needs_work">Needs work</option><option value="good">Good</option><option value="none">Not audited yet</option></select>
          <select id="pgSort"><option value="impressions">Most impressions</option><option value="clicks">Most clicks</option><option value="issues">Most issues</option><option value="linksIn">Fewest links in</option><option value="score">Lowest audit score</option><option value="path">Address</option></select>
          <a class="button secondary small" id="pgCsv" href="#">Download CSV</a></div>
        <div id="pgT"><div class="ops-skel"></div></div><div class="ops-pager" id="pgP"></div></section>
      <div class="ops-two">
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Site-wide</h3>
          <p>Duplicate titles: <b>${O.site.duplicateTitles.length}</b> · Duplicate descriptions: <b>${O.site.duplicateDescriptions.length}</b> · Orphan pages (no links in): <b>${O.site.orphans === null ? '—' : O.site.orphans.length}</b> · Pages with only one link in: <b>${O.site.weaklyLinked === null ? '—' : O.site.weaklyLinked}</b></p>
          ${O.site.linkedButNotInSitemap.length ? `<p>Linked from the site but missing from the sitemap:</p><ul>${O.site.linkedButNotInSitemap.slice(0, 15).map(p => `<li><code>${E(p)}</code></li>`).join('')}</ul>` : '<p class="muted">Every linked content page is in the sitemap.</p>'}
          ${O.site.orphans && O.site.orphans.length ? `<p>Orphan pages:</p><ul>${O.site.orphans.slice(0, 15).map(p => `<li><code>${E(p)}</code></li>`).join('')}</ul>` : ''}
          <div id="agLists"><div class="ops-skel"></div></div>
          <p><button class="button secondary small" id="agAudit">Run the full audit now</button> <button class="button secondary small" id="agCwv">Measure Core Web Vitals</button></p></section>
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
    ['Issues', 'Index', 'Imp', 'Audit', 'Sort'].forEach(id => { const el = $('#pg' + id, h); const key = { Issues: 'issues', Index: 'index', Imp: 'important', Audit: 'audit', Sort: 'sort' }[id]; el.value = P[key]; el.onchange = () => { P[key] = el.value; P.page = 1; pages(h); }; });
    $('#pgQ', h).onchange = e => { P.q = e.target.value.trim(); P.page = 1; pages(h); };
    ['Kind', 'Band'].forEach(id => { const el = $('#kw' + id, h); const key = id.toLowerCase(); el.value = KW[key]; el.onchange = () => { KW[key] = el.value; KW.page = 1; keywords(h); }; });
    $('#kwQ', h).onchange = e => { KW.q = e.target.value.trim(); KW.page = 1; keywords(h); };
    $('#kwAdd', h).onclick = () => addKeyword(h);
    const run = (btn, url) => { btn.onclick = async () => { btn.disabled = true; try { const r = await api(url, { method: 'POST', body: {} }); toast(r.summary || 'Done'); seo(h); } catch (x) { toast(err(x)); btn.disabled = false; } }; };
    run($('#agAudit', h), '/api/admin/growth/seo/audit'); run($('#agCwv', h), '/api/admin/growth/seo/web-vitals');
    pages(h); keywords(h); lists(h);
  }

  // 3.44.0: the audit lists (orphans, sitemap gaps, noindex in the sitemap, broken links).
  async function lists(h) {
    const box = $('#agLists', h); if (!box) return;
    try {
      const L = await api('/api/admin/growth/seo/audit-lists');
      const ul = (a, f) => `<ul>${a.slice(0, 15).map(f).join('')}</ul>${a.length > 15 ? `<p class="muted">and ${a.length - 15} more</p>` : ''}`;
      box.innerHTML = `<p>In the sitemap but marked noindex: <b>${L.noindexInSitemap.length}</b> · Broken pages linked from the site: <b>${L.broken.length}</b></p>
        ${L.noindexInSitemap.length ? '<p>Noindex pages in the sitemap (remove them from the sitemap or the noindex):</p>' + ul(L.noindexInSitemap, p => `<li><code>${E(p)}</code></li>`) : ''}
        ${L.broken.length ? '<p>Broken pages and where they are linked from:</p>' + ul(L.broken, b => `<li><code>${E(b.path)}</code> (HTTP ${b.status || 'no answer'})${b.linkedFrom.length ? ' from ' + b.linkedFrom.slice(0, 3).map(f => `<code>${E(f)}</code>`).join(', ') : ''}</li>`) : ''}`;
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  function qs(o, keys) { const q = new URLSearchParams({ page: String(o.page), per: String(o.per) }); keys.forEach(k => { if (o[k] && o[k] !== 'all') q.set(k, o[k]); }); return q; }

  async function pages(h) {
    const box = $('#pgT', h); if (!box) return;
    const q = qs(P, ['q', 'issues', 'index', 'important', 'audit', 'sort']); if (P.sort === 'linksIn' || P.sort === 'path' || P.sort === 'score') q.set('dir', 'asc');
    $('#pgCsv', h).href = '/api/admin/growth/seo/pages?' + q + '&export=csv';
    try {
      const d = await api('/api/admin/growth/seo/pages?' + q);
      box.innerHTML = table(['Page', 'Audit', 'Google', 'Impr.', 'Clicks', 'Pos.', 'Links in', 'Issues', ''], d.rows.map(r => `<tr><td><code>${E(r.path)}</code>${r.important ? ' <span class="pill">important</span>' : ''}<br><small class="muted">${E(r.title)}</small></td>
        <td>${score(r.auditScore, r.auditStatus)}</td>
        <td>${r.verdict === 'PASS' ? '<span class="pill ok">Indexed</span>' : r.verdict ? `<span class="pill bad">${E(r.coverage || r.verdict)}</span>` : r.inspectError ? `<span class="pill mid" title="${E(r.inspectError)}">check failed</span>` : '<span class="muted">not checked</span>'}</td>
        <td>${n(r.impressions)}${r.impressionsChange !== null ? `<br><small class="muted">${r.impressionsChange >= 0 ? '+' : ''}${n(r.impressionsChange)}</small>` : ''}</td><td>${n(r.clicks)}</td><td>${r.position === null ? '—' : r.position}</td><td>${n(r.linksIn)}</td>
        <td>${r.issueCount ? `<ul style="margin:0;padding-left:16px">${Object.values(r.issues).slice(0, 4).map(i => `<li><small>${E(i)}</small></li>`).join('')}</ul>` : '<span class="pill ok">none</span>'}</td>
        <td><button class="button secondary small" data-path="${E(r.path)}">Record</button></td></tr>`), 'No pages scanned yet. Press "Scan pages now", or wait for the agent (it scans 40 pages every 10 minutes).');
      box.querySelectorAll('button[data-path]').forEach(b => { b.onclick = () => history(b.dataset.path); });
      K.pager($('#pgP', h), d, (p, per) => { P.page = p; P.per = per; pages(h); });
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  const ST = { good: ['ok', 'Good'], needs_work: ['mid', 'Needs work'], poor: ['bad', 'Poor'] };
  const score = (v, st) => v === null || v === undefined ? '<span class="muted">not audited</span>' : `<span class="pill ${(ST[st] || ['', ''])[0]}">${v} · ${E((ST[st] || ['', st])[1])}</span>`;
  const RES = { pass: ['ok', 'Pass'], warn: ['mid', 'Check'], fail: ['bad', 'Fail'], na: ['', 'n/a'] };

  // 3.44.0: one page's record (what it is for), its checklist, its audit history, Google numbers and agent actions.
  async function history(path) {
    let d; try { d = await api('/api/admin/growth/seo/page?path=' + encodeURIComponent(path)); } catch (e) { toast(err(e)); return; }
    const m = modal('Page record: ' + path, '', { wide: true });
    const draw = () => {
      const p = d.page || {}, R = d.record, O = d.options || {};
      const opt = (map, v) => Object.entries(map).map(([k, l]) => `<option value="${E(k)}"${k === v ? ' selected' : ''}>${E(l)}</option>`).join('');
      m.body.innerHTML = `${R ? `<p>Audit: ${score(R.auditScore, R.auditStatus)}${R.auditedAt ? ' · ' + E(when(R.auditedAt)) : ''} <button type="button" class="button secondary small" id="prCheck">Scan and audit this page now</button></p>
        <form class="ops-form" id="prF"><label>Page type<select name="pageType">${opt(O.pageTypes, R.pageType)}</select></label>
          <label>Search intent<select name="intent"><option value="">Not set</option>${opt(Object.fromEntries(O.intents.map(i => [i, i[0].toUpperCase() + i.slice(1)])), R.intent)}</select></label>
          <label>Primary keyword<input name="primaryKeyword" maxlength="190" value="${E(R.primaryKeyword)}"></label>
          <label>Secondary keywords (comma separated)<input name="secondaryKeywords" maxlength="900" value="${E(R.secondaryKeywords.join(', '))}"></label>
          <label>Content status<select name="contentStatus">${opt(O.contentStatuses, R.contentStatus)}</select></label>
          <label>Looked after by<select name="assignedAgent">${opt(O.agents, R.assignedAgent)}</select></label>
          <label class="wide">Note<input name="note" maxlength="500" value="${E(R.note)}"></label>
          <div class="wide"><button class="button primary small">Save record</button> <span role="status" id="prSt" class="muted">${R.editedBy ? 'Last changed by ' + E(R.editedBy) + ' · ' + E(when(R.editedAt)) : 'Type and keyword are filled in by the agent until someone saves.'}</span></div></form>
        <p>Editorial review: <b>${R.reviewedAt ? 'Reviewed by ' + E(R.reviewedBy || 'the team') + ' · ' + E(when(R.reviewedAt)) : 'Not reviewed yet'}</b> <button type="button" class="button secondary small" id="prRev">I reviewed this page today</button>${R.editable ? ' <button type="button" class="button secondary small" id="prDraft">Propose a change</button>' : ' <small class="muted">(changes can be proposed only for the ready-made SEO pages)</small>'}</p>
        <div id="prVer"></div>
        <p>Main call to action: <b>${E(R.cta || 'none found')}</b> · Links in: <b>${n(R.linksIn)}</b> · Images: <b>${n(R.images)}</b>${R.altCoverage !== null ? ` (ALT text on ${R.altCoverage}%)` : ''} · Core Web Vitals: <b>${R.cwv && R.cwv.status === 'ok' ? `LCP ${Math.round(R.cwv.lcp / 100) / 10} s, INP ${R.cwv.inp} ms, CLS ${R.cwv.cls}` : 'not tracked'}</b></p>
        ${R.outline.length ? `<details><summary>Headings on the page (${R.outline.length})</summary><ul style="margin:6px 0">${R.outline.map(o => `<li style="margin-left:${o[0] === 'h3' ? 18 : 0}px"><small class="muted">${E(o[0].toUpperCase())}</small> ${E(o[1])}</li>`).join('')}</ul></details>` : ''}
        <h4>Checklist (latest audit)</h4>${table(['Check', 'Result', 'Detail'], d.checks.map(c => `<tr><td>${E(c.label)}</td><td><span class="pill ${RES[c.result][0]}">${RES[c.result][1]}</span></td><td><small>${E(c.detail)}</small></td></tr>`), 'Not audited yet. Press "Scan and audit this page now".')}
        <h4>Audit history</h4>${table(['When', 'Score', 'Failed', 'To check', 'Run by'], d.audits.map(a => `<tr><td>${E(when(a.at))}</td><td>${score(a.score, a.status)}</td><td>${a.failed}</td><td>${a.warnings}</td><td>${a.source === 'manual' ? 'A person' : 'The agent'}</td></tr>`), 'No audits yet.')}` : '<p class="muted">This page has not been scanned yet.</p>'}
        <p>Google: <b>${E(p.coverage || p.verdict || 'not checked yet')}</b>${p.last_crawl_at ? ' · last crawled ' + E(when(p.last_crawl_at)) : ''}${p.google_canonical ? ' · Google\'s canonical: <code>' + E(p.google_canonical) + '</code>' : ''}${p.index_requested_at ? ' · recrawl asked ' + E(when(p.index_requested_at)) : ''} · <a href="${E(d.inspectLink)}" target="_blank" rel="noopener">Open in Search Console</a></p>
        <h4>Week by week (each week = the 4 weeks before it)</h4>${table(['Week', 'Impressions', 'Clicks', 'Clicked %', 'Position'], d.weeks.map(w => `<tr><td>${E(w.week)}</td><td>${n(w.impressions)}</td><td>${n(w.clicks)}</td><td>${Math.round(w.ctr * 1000) / 10}%</td><td>${w.position}</td></tr>`), 'No Search Console numbers for this page yet.')}
        <h4>Agent actions</h4>${table(['Proposed', 'Change', 'Status', 'Why'], d.actions.map(a => `<tr><td>${E(when(a.created_at))}</td><td>${a.kind === 'meta' ? 'Title and description' : 'Internal links'}</td><td>${E(a.status)}</td><td><small>${E(a.reason)}</small></td></tr>`), 'None yet.')}`;
      const f = $('#prF', m.el);
      if (f) f.onsubmit = async e => {
        e.preventDefault(); const st = $('#prSt', m.el); st.textContent = 'Saving…';
        try { const r = await api('/api/admin/growth/seo/page/record', { method: 'POST', body: { path, pageType: f.pageType.value, intent: f.intent.value || null, primaryKeyword: f.primaryKeyword.value.trim(), secondaryKeywords: f.secondaryKeywords.value.split(',').map(x => x.trim()).filter(Boolean).slice(0, 10), contentStatus: f.contentStatus.value, assignedAgent: f.assignedAgent.value, note: f.note.value.trim() } });
          Object.assign(d, r); toast('Saved'); draw(); } catch (x) { st.textContent = err(x); }
      };
      const rv = $('#prRev', m.el);
      if (rv) rv.onclick = async () => { rv.disabled = true; try { Object.assign(d, await api('/api/admin/growth/seo/page/reviewed', { method: 'POST', body: { path } })); toast('Marked reviewed. The page now shows today\'s review date.'); draw(); } catch (x) { toast(err(x)); rv.disabled = false; } };
      const dr = $('#prDraft', m.el); if (dr) dr.onclick = () => draft(path, d);
      versions(path, $('#prVer', m.el));
      const c = $('#prCheck', m.el);
      if (c) c.onclick = async () => { c.disabled = true; c.textContent = 'Checking…'; try { const r = await api('/api/admin/growth/seo/page/audit', { method: 'POST', body: { path } }); Object.assign(d, r); toast('Audited'); draw(); } catch (x) { toast(err(x)); c.disabled = false; c.textContent = 'Scan and audit this page now'; } };
    };
    draw();
  }

  // 3.45.0: content versions of a page (each approved change, its live check and one-click undo).
  const VS = { pending: ['mid', 'Checking soon'], live: ['ok', 'Seen live'], not_live: ['bad', 'Not live'], failed: ['bad', 'Check failed'] };
  async function versions(path, box) {
    if (!box) return;
    let j; try { j = await api('/api/admin/growth/seo/versions?path=' + encodeURIComponent(path)); } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; return; }
    if (!j.rows.length) { box.innerHTML = ''; return; }
    const show = v => !v ? '—' : v.title ? E(v.title) : v.h1 ? E(v.h1) : v.items ? v.items.map(i => E(i[0])).join('<br>') : v.type ? E(v.type) : v.from ? 'Link "' + E(v.label) + '" on ' + v.from.map(E).join(', ') : v.types ? E(v.types.join(', ')) : v.count !== undefined ? v.count + ' question(s)' : '—';
    box.innerHTML = `<h4>Content versions</h4>${table(['When', 'Change', 'Before', 'After', 'Live check', ''], j.rows.map(v => `<tr><td>${E(when(v.appliedAt))}<br><small class="muted">${E(v.appliedBy)}</small></td><td>${E(v.kindLabel)}${v.revertedAt ? `<br><small class="muted">undone ${E(when(v.revertedAt))}${v.revertedBy ? ' by ' + E(v.revertedBy) : ''}</small>` : ''}</td><td><small>${show(v.before)}</small></td><td><small>${show(v.after)}</small></td>
      <td><span class="pill ${(VS[v.verifyStatus] || ['', ''])[0]}">${E((VS[v.verifyStatus] || ['', v.verifyStatus])[1])}</span>${v.verifyNote ? `<br><small>${E(v.verifyNote)}</small>` : ''}</td>
      <td style="white-space:nowrap"><button type="button" class="button secondary small" data-vchk="${v.id}">Check now</button>${v.canUndo ? ` <button type="button" class="button secondary small" data-vundo="${E(v.proposalId)}">Undo</button>` : ''}</td></tr>`), '')}`;
    box.querySelectorAll('[data-vchk]').forEach(b => b.onclick = async () => { b.disabled = true; try { const r = await api(`/api/admin/growth/seo/versions/${b.dataset.vchk}/verify`, { method: 'POST', body: {} }); toast(r.note); versions(path, box); } catch (x) { toast(err(x)); b.disabled = false; } });
    box.querySelectorAll('[data-vundo]').forEach(b => b.onclick = async () => { if (!confirm('Undo this change on the live page?')) return; b.disabled = true; try { await api('/api/admin/growth/seo/decide', { method: 'POST', body: { id: b.dataset.vundo, action: 'undo' } }); toast('Undone. The live page is checked again in a minute.'); versions(path, box); } catch (x) { toast(err(x)); b.disabled = false; } });
  }

  // 3.45.0: draft a change for approval. Someone else with approve rights decides it in Approvals & tasks.
  async function draft(path, d) {
    const R = d.record || {};
    let K2; try { K2 = await api('/api/admin/growth/seo/versions?path=' + encodeURIComponent(path)); } catch (e) { toast(err(e)); return; }
    const m = modal('Propose a change: ' + path, `<form class="ops-form" id="dfF"><label class="wide">What to change<select name="kind">${Object.entries(K2.kinds).map(([k, l]) => `<option value="${k}">${E(l)}</option>`).join('')}</select></label>
      <div class="wide" id="dfFields"></div><label class="wide">Why this helps (shown to the approver)<textarea name="reason" rows="2" minlength="10" maxlength="2000" required></textarea></label>
      <div class="wide"><button class="button primary small">Send for approval</button> <span role="status" id="dfSt" class="muted"></span></div></form>`);
    const f = $('#dfF', m.el), box = $('#dfFields', m.el);
    const p = d.page || {};
    const fields = {
      meta: `<label>New title<input name="title" maxlength="70" value="${E(p.title || '')}"></label><label>New description<textarea name="description" rows="2" maxlength="170">${E(p.description || '')}</textarea></label>`,
      h1: `<label>New main heading<input name="h1" maxlength="90" value="${E(p.h1 || '')}"></label>`,
      faq: [1, 2, 3].map(i => `<label>Question ${i}${i > 1 ? ' (optional)' : ''}<input name="q${i}" maxlength="160"></label><label>Answer ${i}<textarea name="a${i}" rows="2" maxlength="600"></textarea></label>`).join(''),
      schema: `<label>Structured data<select name="type">${Object.entries(K2.schemaTypes).map(([k, l]) => `<option value="${k}">${E(l)}</option>`).join('')}</select></label>`,
      links: `<label>Link text<input name="label" maxlength="70"></label><label>Add the link on these pages (one address per line)<textarea name="from" rows="3" placeholder="/resume-examples/it-software/"></textarea></label>`,
      brief: `<label>Topic<input name="topic" maxlength="120"></label><label>Target search<input name="keyword" maxlength="120" value="${E(R.primaryKeyword || '')}"></label><label>Outline (one heading per line)<textarea name="outline" rows="4"></textarea></label><label>Notes for the writer<textarea name="notes" rows="2" maxlength="1000"></textarea></label>`,
    };
    const pick = () => { box.innerHTML = `<div style="display:grid;gap:8px">${fields[f.kind.value]}</div>`; };
    f.kind.onchange = pick; pick();
    f.onsubmit = async e => {
      e.preventDefault(); const k = f.kind.value, g = n => (f.elements[n] ? f.elements[n].value.trim() : '');
      const data = k === 'meta' ? { title: g('title'), description: g('description') } : k === 'h1' ? { h1: g('h1') } : k === 'faq' ? { items: [1, 2, 3].map(i => [g('q' + i), g('a' + i)]).filter(x => x[0] || x[1]) }
        : k === 'schema' ? { type: g('type') } : k === 'links' ? { label: g('label'), from: g('from').split(/\s+/).filter(Boolean) } : { topic: g('topic'), keyword: g('keyword'), outline: g('outline').split('\n').map(x => x.trim()).filter(Boolean), notes: g('notes') };
      const st = $('#dfSt', m.el); st.textContent = 'Sending…';
      try { await api('/api/admin/growth/seo/draft', { method: 'POST', body: { kind: k, path, data, reason: f.reason.value.trim() } }); toast('Sent for approval'); m.close(); } catch (x) { st.textContent = err(x); }
    };
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

  // ---------------------------------------------------------------- agent reports, corrective actions, knowledge sync (3.58.0)
  const CAD = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' };
  const AG = { seo: 'SEO Head', growth: 'Growth & Revenue' };
  const CR = { page: 1, per: 20, agent: 'all', status: 'all' };
  function facts(d) {
    if (!d) return '<p class="muted">Not written yet.</p>';
    const skip = new Set(['sources', 'period', 'from', 'to', 'knowledgeVersion', 'corrections', 'month', 'bottleneck', 'margin', 'plans', 'sections', 'nearWins', 'lowClickRate', 'brokenPages', 'importantNotIndexed', 'signupsBySource']);
    const label = k => k.replace(/Paise$/, '').replace(/([A-Z])/g, ' $1').replace(/^./, c => c.toUpperCase());
    const val = (k, v) => /Paise$/.test(k) ? inr(v) : (v === null || v === undefined ? 'Not tracked' : typeof v === 'object' ? E(JSON.stringify(v)) : E(String(v)));
    const rows = Object.entries(d).filter(([k]) => !skip.has(k)).map(([k, v]) => `<tr><td>${E(label(k))}</td><td>${val(k, v)}</td></tr>`);
    const list = (t, a, f) => a && a.length ? `<p style="margin:8px 0 2px"><b>${E(t)}</b></p><ul style="margin:0">${a.slice(0, 8).map(f).join('')}</ul>` : '';
    return `<table class="ad-table">${rows.join('')}</table>
      ${d.month ? `<p class="muted">${E(d.month.label)}: ${n(d.month.purchases)} of ${n(d.month.target)} orders, ${d.month.attainment === null ? 'no target set' : E(d.month.attainment + '% of pace')} <span class="pill ${{ green: 'ok', amber: 'mid', red: 'bad' }[d.month.band] || ''}">${E(d.month.band)}</span></p>` : ''}
      ${list('Pages that do not open', Object.entries(d.brokenPages || {}), ([p, s]) => `<li>${E(p)} (${E(s)})</li>`)}
      ${list('Searches close to page 1', d.nearWins, r => `<li>${E(r.query)}: position ${E(r.position)}, ${n(r.impressions)} views</li>`)}
      ${list('Shown but rarely clicked', d.lowClickRate, r => `<li>${E(r.query)}: ${E(r.ctrPct)}% at position ${E(r.position)}</li>`)}
      ${list('Sections', d.sections, r => `<li>${E(r.section)}: ${n(r.indexed)} of ${n(r.pages)} indexed</li>`)}
      <p class="muted" style="font-size:.85em">Read from: ${E((d.sources || []).join(', '))}. Knowledge version ${n(d.knowledgeVersion)}.</p>`;
  }
  async function cadence(h) {
    let d; try { d = await api('/api/admin/growth/agents/cadence'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const cards = d.reports.map(r => `<details class="panel panel-pad" style="margin-bottom:8px"><summary><b>${E(AG[r.agent])}: ${E(CAD[r.cadence])}</b> <span class="muted">${r.period ? E(r.period) + ', written ' + E(when(r.at)) : 'not written yet'}</span>
      ${r.data && r.data.corrections && r.data.corrections.length ? `<span class="pill bad">${r.data.corrections.length} to fix</span>` : ''}</summary>${facts(r.data)}
      <button type="button" class="button secondary small" data-crun="${r.agent}:${r.cadence}">Write this report now</button></details>`).join('');
    const ks = d.knowledge.agents.map(a => `<tr><td>${E(a.label)}</td><td>${a.version === null ? '—' : n(a.version)}</td><td>${E(when(a.syncedAt))}</td><td>${n(a.changesSince)}</td><td>${a.stale ? '<span class="pill mid">Behind</span>' : '<span class="pill ok">Up to date</span>'}</td></tr>`);
    h.innerHTML = `<section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Agent reports</h3>
      <p class="muted" style="margin-top:0">The SEO Head and the Growth & Revenue agent each write a daily, weekly and monthly report after 7 AM. Numbers come from the site's own records; nothing here is changed on the site without your approval.</p>${cards}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Corrective actions</h3><p class="muted" style="margin-top:0">Written when a target is missed. Each one also waits as a task in Approvals.</p>
        <div style="display:flex;gap:8px;margin-bottom:8px"><select id="crA"><option value="all">Both agents</option><option value="seo">SEO Head</option><option value="growth">Growth & Revenue</option></select><select id="crS"><option value="all">Any status</option><option value="open">Open</option><option value="met">Met</option><option value="missed">Missed</option></select><a class="button secondary small" id="crCsv" href="#">Download CSV</a><a class="button secondary small" id="crXl" href="#">Download Excel</a></div>
        <div id="crT"></div><div class="ops-pager" id="crP"></div></section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">What the agents know</h3><p class="muted" style="margin-top:0">Every saved change to plans, prices, offers and programme rules is listed here. An agent that has not worked since a change is shown as behind.</p>
        ${table(['Agent', 'Knowledge version', 'Last worked', 'Changes since', 'State'], ks, 'No agents yet.')}
        ${table(['When', 'What changed', 'By'], d.changes.map(c => `<tr><td>${E(when(c.at))}</td><td>${E(c.summary)}</td><td>${E(c.by || 'System')}</td></tr>`), 'No changes recorded yet.')}</section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Recent runs</h3>
        ${table(['When', 'Agent', 'Task', 'Rules', 'Model', 'Read from', 'Run id'], d.runs.map(r => `<tr><td>${E(when(r.at))}</td><td>${E(AG[r.agent] || r.agent)}</td><td>${E(r.task)}</td><td>${E(r.rules || '—')}</td><td>${E(r.model || 'None')}</td><td>${E(r.sources.join(', '))}</td><td><code>${E(String(r.run).slice(0, 8))}</code></td></tr>`), 'No traced runs yet.')}</section>`;
    h.querySelectorAll('[data-crun]').forEach(b => b.onclick = async () => { const [agent, c] = b.dataset.crun.split(':'); b.disabled = true; try { const r = await api('/api/admin/growth/agents/cadence/run', { method: 'POST', body: { agent, cadence: c } }); toast(r.summary); cadence(h); } catch (x) { toast(err(x)); b.disabled = false; } });
    $('#crA', h).onchange = e => { CR.agent = e.target.value; CR.page = 1; corrections(h); };
    $('#crS', h).onchange = e => { CR.status = e.target.value; CR.page = 1; corrections(h); };
    corrections(h);
  }
  async function corrections(h) {
    const q = qs(CR, ['agent', 'status']);
    $('#crCsv', h).href = '/api/admin/growth/agents/corrections?' + q + '&export=csv';
    $('#crXl', h).href = '/api/admin/growth/agents/corrections?' + q + '&export=xlsx';
    try {
      const d = await api('/api/admin/growth/agents/corrections?' + q);
      $('#crT', h).innerHTML = table(['Period', 'Agent', 'Metric', 'Target', 'Actual', 'Variance', 'Root cause', 'Corrective action', 'Owner', 'Deadline', 'Expected result', 'Review'],
        d.rows.map(r => `<tr><td>${E(r.period)}</td><td>${E(AG[r.agent] || r.agent)}</td><td>${E(r.metric)}</td><td>${E(r.target)}</td><td>${E(r.actual)}</td><td>${E(r.variance)}</td><td title="${E(r.evidence)}">${E(r.root_cause)}</td><td>${E(r.action)}</td><td>${E(r.owner)}</td><td>${E(r.deadline)}</td><td>${E(r.expected)}</td><td>${E(r.review_date)}</td></tr>`), 'No missed targets.');
      K.pager($('#crP', h), d, (p, per) => { CR.page = p; CR.per = per; corrections(h); });
    } catch (e) { $('#crT', h).innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  window.CVTaraAgents = { seo, revenue, log, cadence };
})();
