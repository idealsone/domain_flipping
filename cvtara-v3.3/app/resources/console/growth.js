/* Owner console: Growth — the first-year target ladder, this month against plan, the funnel, the owner reports (weekly by default, or 2 PM / 7 PM) and AI spending. Every number comes from the server; a number the site cannot measure shows as "Data unavailable". */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const n = x => x === null || x === undefined ? 'Data unavailable' : Number(x).toLocaleString('en-IN');
  const inr = p => p === null || p === undefined ? 'Data unavailable' : '₹' + Math.round(Number(p) / 100).toLocaleString('en-IN');
  const pct = (a, b) => a === null || b === null || !b || a > b ? '—' : (Math.round(a / b * 1000) / 10) + '%';
  const BAND = { green: ['On pace', 'ok'], amber: ['Behind pace (70–89%)', 'mid'], red: ['Well behind pace (below 70%)', 'bad'], none: ['No target this month', ''] };
  const monthName = k => new Date(k + '-01T00:00:00').toLocaleDateString('en-IN', { month: 'short', year: 'numeric' });
  const R = { page: 1, per: 20, slot: 'all', band: 'all' };
  let D = null;

  async function growth() {
    const h = $('#admin-growth'); if (!h) return;
    if (!D) h.innerHTML = '<div class="ops-skel"></div>';
    try { D = await api('/api/admin/growth'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const M = D.month, m = M.metrics, b = BAND[M.band] || BAND.none, B = D.budget, st = D.stages;
    const funnel = (row, label) => `<tr><th scope="row">${label}</th>${Object.keys(st).map((k, i, ks) => `<td>${n(row[k])}${i ? `<br><small class="muted">${pct(row[k], row[ks[i - 1]])}</small>` : ''}</td>`).join('')}</tr>`;
    const plans = Object.entries(m.plans || {});
    h.innerHTML = `<div class="pg-head"><p>Where CVTara stands against the first-year plan of ${n(D.year.totalTarget)} purchases (${inr(D.year.totalPaise)} gross). Numbers are counted from payments, sign-ups and resumes; nothing is estimated. ${D.settings.reportEvery === 'daily' ? 'Reports go to you at 2 PM and 7 PM, Monday to Saturday.' : 'A weekly report goes to you every Monday morning.'}</p></div>
      <div class="ops-kpis">
        <div class="kpi"><small>${E(M.label)} purchases</small><b>${n(m.purchases)}</b><small>${M.target ? 'of ' + n(M.target) + ' committed · ' + n(M.stretch) + ' stretch' : 'no target set'}</small></div>
        <div class="kpi"><small>Gross sales</small><b>${inr(m.grossPaise)}</b><small>${M.targetPaise ? 'of ' + inr(M.targetPaise) + ' · net ' + inr(m.netPaise) : 'net ' + inr(m.netPaise)}</small></div>
        <div class="kpi"><small>Against pace</small><b>${M.attainment === null ? '—' : M.attainment + '%'}</b><small><span class="pill ${b[1]}">${E(b[0])}</span></small></div>
        <div class="kpi"><small>Needed per day</small><b>${M.perDayNeeded === null ? '—' : n(M.perDayNeeded)}</b><small>purchases for the rest of the month</small></div>
        <div class="kpi"><small>New paying customers</small><b>${n(m.newCustomers)}</b><small>${n(m.repeatPurchases)} repeat purchases</small></div>
      </div>
      <section class="panel panel-pad"><h3 style="margin-top:0">Next actions</h3><ul style="margin:0;padding-left:18px">${D.actions.map(a => `<li>${E(a)}</li>`).join('')}</ul></section>
      <section class="panel panel-pad" data-nokit id="grSeo"><h3 style="margin-top:0">SEO agent</h3><div class="ops-skel"></div></section>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Funnel</h3>
        <p class="muted" style="margin-top:0">Each step shows the people who reached it and, below, the share of the step before.${D.analytics ? ' Visitors are people who allowed analytics on the cookie banner, so the real number is higher.' : ' Visitors show as unavailable until Analytics is switched on in Integrations.'}</p>
        <div style="overflow:auto"><table class="ad-table"><thead><tr><th></th>${Object.values(st).map(l => `<th>${E(l)}</th>`).join('')}</tr></thead><tbody>${funnel(D.today, 'Today')}${funnel(D.week, 'Last 7 days')}${funnel(m, 'This month')}</tbody></table></div>
        ${D.bottleneck ? `<p style="margin-bottom:0">Weakest step this month: <b>${E(st[D.bottleneck.from])} → ${E(st[D.bottleneck.to])}</b> (${D.bottleneck.rate}%).</p>` : ''}</section>
      <div class="ops-two">
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">This month by plan</h3>${plans.length ? `<table class="ad-table"><tbody>${plans.map(([k, v]) => `<tr><td>${E(k)}</td><td>${n(v.count)}</td><td>${inr(v.paise)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">No sales yet this month.</p>'}
          <p class="muted" style="margin-bottom:0">Marketing mails sent: ${n(m.marketingSent)} · Outreach mails: ${n(m.outreachSent)} · Unsubscribes: ${n(m.unsubscribes)} · Ad spend: Data unavailable</p></section>
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Where visitors came from (this month)</h3>${Object.keys(m.sources || {}).length ? `<table class="ad-table"><tbody>${Object.entries(m.sources).map(([k, v]) => `<tr><td>${E(k)}</td><td>${n(v)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Data unavailable.</p>'}</section>
      </div>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">First-year plan</h3><div style="overflow:auto"><table class="ad-table"><thead><tr><th>Month</th><th>Purchases target</th><th>Actual</th><th>Gross target</th><th>Actual</th></tr></thead><tbody>
        ${D.year.rows.map(r => `<tr><td>${E(monthName(r.month))}</td><td>${n(r.target)}</td><td>${r.purchases === null ? '—' : n(r.purchases)}</td><td>${inr(r.targetPaise)}</td><td>${r.grossPaise === null ? '—' : inr(r.grossPaise)}</td></tr>`).join('')}</tbody></table></div>
        <p class="muted" style="margin-bottom:0">Actuals fill in from each day's stored numbers. Gross target = purchases × ${inr(D.settings.avgPaise)} average.</p></section>
      <div class="ops-two">
        <section class="panel panel-pad"><h3 style="margin-top:0">AI spending this month</h3>
          <p style="margin-top:0"><b>${inr(B.spentPaise)}</b> ${B.capPaise ? 'of ' + inr(B.capPaise) + ' cap' + (B.pct === null ? '' : ' (' + B.pct + '%)') : 'spent (no limit set; monitoring only)'}</p>
          <div class="ops-bar" style="height:8px;border-radius:4px;background:var(--line,#e5e7eb);overflow:hidden"><div style="height:100%;width:${Math.min(100, B.pct || 0)}%;background:${B.pct >= 90 ? '#dc2626' : B.pct >= 75 ? '#d97706' : '#16a34a'}"></div></div>
          <p class="muted">${Object.entries(B.byPurpose).map(([k, v]) => `${E(k === 'customer' ? 'Customer add-ons' : k)}: ${inr(v.paise)} (${n(v.calls)} requests)`).join(' · ') || 'No requests yet this month.'}</p>
          <p class="muted" style="margin-bottom:0">Alerts at 50%, 75% and 90% go to the Error Log and the reports. At 100% growth tasks stop; paid customer add-ons keep working. Cost is worked out from the token prices below, so keep them in line with your AI provider's pricing page.</p></section>
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Colleges with the most users</h3>
          <p class="muted" style="margin-top:0">Totals of users who agreed to emails, read from their resumes. Use it to choose which placement cells to contact first. It never shows who the students are.</p>
          ${D.colleges.length ? `<table class="ad-table"><tbody>${D.colleges.map(c => `<tr><td>${E(c.college)}</td><td>${n(c.users)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Nothing yet.</p>'}</section>
      </div>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Reports</h3>
        <div class="ops-filterbox"><label class="ops-f">Time<select id="grS"><option value="all">All</option><option value="weekly">Weekly</option><option value="midday">2 PM</option><option value="evening">7 PM</option></select></label>
          <label class="ops-f">Band<select id="grB"><option value="all">All</option>${Object.entries(BAND).map(([k, v]) => `<option value="${k}">${E(v[0])}</option>`).join('')}</select></label>
          <button class="button secondary small" id="grNow">Send a report now</button> <button class="button secondary small" id="grSet">Targets and settings</button></div>
        <div style="overflow:auto"><table class="ad-table" id="grT"><tbody><tr><td>Loading…</td></tr></tbody></table></div><div class="ops-pager" id="grP"></div></section>`;
    $('#grS', h).value = R.slot; $('#grB', h).value = R.band;
    $('#grS', h).onchange = e => { R.slot = e.target.value; R.page = 1; reports(h); };
    $('#grB', h).onchange = e => { R.band = e.target.value; R.page = 1; reports(h); };
    $('#grNow', h).onclick = async () => { try { const hr = new Date(Date.now() + 5.5 * 3600e3).getUTCHours(); await api('/api/admin/growth/report-now', { method: 'POST', body: { slot: D.settings.reportEvery === 'daily' ? (hr >= 19 ? 'evening' : 'midday') : 'weekly' } }); toast('Report saved and emailed'); R.page = 1; reports(h); } catch (x) { toast(err(x)); } };
    $('#grSet', h).onclick = settings;
    reports(h); seo(h);
  }

  const KIND = { meta: 'New title and description', links: 'Links from related pages' };
  const ST = { pending: ['Waiting for you', 'mid'], approved: ['Live', 'ok'], rejected: ['Rejected', ''], reverted: ['Undone', ''] };
  const P = { page: 1, per: 20, status: 'pending', kind: 'all' };
  async function seo(h) {
    const box = $('#grSeo', h); if (!box) return;
    let d, cov = []; try { const j = await api('/api/admin/growth/seo'); d = j.seo; cov = j.coverage || []; } catch (e) { box.innerHTML = `<h3 style="margin-top:0">SEO agent</h3><p role="alert">${E(err(e))}</p>`; return; }
    const kw = (rows, f) => rows.length ? `<table class="ad-table"><tbody>${rows.map(f).join('')}</tbody></table>` : '<p class="muted">Nothing yet.</p>';
    box.innerHTML = `<h3 style="margin-top:0">SEO agent</h3>
      <p class="muted" style="margin-top:0">Every day from 11 AM (Monday to Saturday) it reads Search Console, finds searches close to page 1, pages that are seen but rarely clicked and searches split across pages, and proposes changes. Nothing goes live until you approve it, and every live change can be undone.</p>
      ${d.ready ? '' : '<div class="panel panel-pad" role="alert" style="border-left:4px solid #d97706;margin-bottom:10px">Search Console is not connected, so rankings are unavailable. Add the property and service-account key in Integrations → Analytics.</div>'}
      <div class="ops-kpis"><div class="kpi"><small>Google clicks (4 weeks)</small><b>${n(d.clicks)}</b><small>${d.endDate ? 'data up to ' + E(d.endDate) : ''}</small></div><div class="kpi"><small>Impressions</small><b>${n(d.impressions)}</b><small>times CVTara was shown</small></div><div class="kpi"><small>Waiting for you</small><b>${n(d.pending)}</b><small>${n(d.live)} live</small></div></div>
      <div class="ops-two"><div><h4>Close to page 1</h4>${kw(d.striking, r => `<tr><td>${E(r.query)}<br><small class="muted">${E(r.path)}</small></td><td>#${r.position}</td><td>${n(r.impressions)}</td></tr>`)}</div>
        <div><h4>Seen but rarely clicked</h4>${kw(d.lowCtr, r => `<tr><td>${E(r.path)}</td><td>#${Math.round(r.position * 10) / 10}</td><td>${(Math.round(r.ctr * 1000) / 10)}% clicked</td></tr>`)}
        <h4>Searches split across two pages</h4>${kw(d.split, r => `<tr><td>${E(r.query)}<br><small class="muted">${r.pages.map(E).join(' · ')}</small></td></tr>`)}
        <h4>Searches with no good page yet</h4>${kw(d.gaps, r => `<tr><td>${E(r.query)}</td><td>best #${r.best}</td><td>${n(r.impressions)}</td></tr>`)}</div></div>
      <h4>Proposed changes</h4>
      <div class="ops-filterbox"><label class="ops-f">Status<select id="spS"><option value="all">All</option>${Object.entries(ST).map(([k, v]) => `<option value="${k}">${E(v[0])}</option>`).join('')}</select></label>
        <label class="ops-f">Kind<select id="spK"><option value="all">All</option>${Object.entries(KIND).map(([k, v]) => `<option value="${k}">${E(v)}</option>`).join('')}</select></label>
        <button class="button secondary small" id="spRun">Check Search Console now</button></div>
      <div id="spL"></div><div class="ops-pager" id="spP"></div>
      <h4>Which pages Google is showing</h4>
      ${(D2 => D2.length ? `<table class="ad-table"><thead><tr><th>Section</th><th>Pages in sitemap</th><th>Shown by Google (4 weeks)</th><th>Impressions</th></tr></thead><tbody>${D2.map(c => `<tr><td>${E(c.section)}</td><td>${n(c.pages)}</td><td>${n(c.seen)} (${c.pages ? Math.round(c.seen * 100 / c.pages) : 0}%)</td><td>${n(c.impressions)}</td></tr>`).join('')}</tbody></table>` : '')(cov)}
      <h4>Tracked pages and searches</h4>
      <p class="muted" style="margin-top:0">Updated with every Search Console check and kept week by week. Changes compare with the week before.</p>
      <div class="ops-filterbox"><label class="ops-f">Show<select id="stK"><option value="page">Pages</option><option value="query">Searches</option></select></label>
        <label class="ops-f">Filter<select id="stB"></select></label>
        <label class="ops-f">Search<input id="stQ" type="search" placeholder="Page or search words"></label></div>
      <div style="overflow-x:auto"><table class="ad-table" id="stT"></table></div><div class="ops-pager" id="stP"></div>`;
    $('#spS', box).value = P.status; $('#spK', box).value = P.kind;
    $('#spS', box).onchange = e => { P.status = e.target.value; P.page = 1; proposals(box); };
    $('#spK', box).onchange = e => { P.kind = e.target.value; P.page = 1; proposals(box); };
    $('#spRun', box).onclick = async e => { e.target.disabled = true; try { const r = await api('/api/admin/growth/seo/run', { method: 'POST', body: {} }); toast(`Read ${n(r.pairs)} searches · ${r.meta} title and ${r.links} link proposals`); seo(h); } catch (x) { toast(err(x)); e.target.disabled = false; } };
    proposals(box); track(box);
  }
  const TR = { kind: 'page', band: 'all', q: '', page: 1, per: 20 };
  const BANDS = { page: [['all', 'All pages'], ['top', 'On page 1'], ['close', 'Close to page 1 (11–20)'], ['far', 'Beyond page 2'], ['lowctr', 'Seen but rarely clicked'], ['notarget', 'Title misses its main search'], ['seen', 'Shown by Google'], ['unseen', 'Not shown by Google yet']],
    query: [['all', 'All searches'], ['nobrand', 'Without other brands'], ['top', 'On page 1'], ['close', 'Close to page 1 (11–20)'], ['far', 'Beyond page 2'], ['lowctr', 'Seen but rarely clicked'], ['brand', 'Naming other brands']] };
  const delta = (v, good) => v === null || v === undefined || v === 0 ? '' : ` <small style="color:${(v > 0) === good ? '#15803d' : '#b91c1c'}">${v > 0 ? '+' : ''}${n(v)}</small>`;
  async function track(box) {
    const t = $('#stT', box); if (!t) return;
    const b = $('#stB', box);
    if (b.dataset.kind !== TR.kind) { b.innerHTML = BANDS[TR.kind].map(([k, v]) => `<option value="${k}">${E(v)}</option>`).join(''); b.dataset.kind = TR.kind; b.value = TR.band; }
    $('#stK', box).onchange = e => { TR.kind = e.target.value; TR.band = 'all'; TR.page = 1; track(box); };
    b.onchange = e => { TR.band = e.target.value; TR.page = 1; track(box); };
    let tm; $('#stQ', box).oninput = e => { clearTimeout(tm); tm = setTimeout(() => { TR.q = e.target.value.trim(); TR.page = 1; track(box); }, 300); };
    try {
      const qs = new URLSearchParams({ kind: TR.kind, page: String(TR.page), per: String(TR.per) }); if (TR.band !== 'all') qs.set('band', TR.band); if (TR.q) qs.set('q', TR.q);
      const d = await api('/api/admin/growth/seo/tracked?' + qs);
      const pos = r => r.position ? '#' + (Math.round(r.position * 10) / 10) + delta(r.positionChange, true) : '—';
      const pct = r => (Math.round(r.ctr * 1000) / 10) + '%';
      t.innerHTML = !d.rows.length ? '<tbody><tr><td class="muted">Nothing tracked yet. It fills in after the first Search Console check.</td></tr></tbody>'
        : TR.kind === 'page' ? `<thead><tr><th>Page</th><th>Main search</th><th>Impressions</th><th>Clicks</th><th>Clicked</th><th>Position</th><th>Searches it shows for</th></tr></thead><tbody>${d.rows.map(r => `<tr><td><a href="${E(r.key)}" target="_blank" rel="noopener">${E(r.key)}</a></td><td>${E(r.target || '—')}${r.titleHasTarget === false ? '<br><small style="color:#b91c1c">not in the title</small>' : ''}</td><td>${r.unseen ? '<span class="pill mid">Not shown yet</span>' : n(r.impressions) + delta(r.impressionsChange, true)}</td><td>${n(r.clicks)}</td><td>${pct(r)}</td><td>${pos(r)}</td><td><small>${r.queries.slice(0, 5).map(q => `${E(q[0])} (#${Math.round(q[2] * 10) / 10})`).join('<br>') || '—'}</small></td></tr>`).join('')}</tbody>`
        : `<thead><tr><th>Search</th><th>Impressions</th><th>Clicks</th><th>Clicked</th><th>Position</th><th>Page shown</th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${E(r.key)}${r.brand ? ' <span class="pill">other brand</span>' : ''}</td><td>${n(r.impressions)}${delta(r.impressionsChange, true)}</td><td>${n(r.clicks)}</td><td>${pct(r)}</td><td>${pos(r)}</td><td><small>${r.pages.map(E).join('<br>') || '—'}</small></td></tr>`).join('')}</tbody>`;
      K.pager($('#stP', box), d, (p, per) => { TR.page = p; TR.per = per; track(box); });
    } catch (e) { t.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }
  async function proposals(box) {
    const l = $('#spL', box); if (!l) return;
    try {
      const qs = new URLSearchParams({ page: String(P.page), per: String(P.per) }); if (P.status !== 'all') qs.set('status', P.status); if (P.kind !== 'all') qs.set('kind', P.kind);
      const d = await api('/api/admin/growth/seo/proposals?' + qs);
      l.innerHTML = d.rows.length ? d.rows.map(p => `<div class="panel panel-pad" style="margin-bottom:8px"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><b>${E(KIND[p.kind] || p.kind)} · <a href="${E(p.path)}" target="_blank" rel="noopener">${E(p.path)}</a></b><span class="pill ${(ST[p.status] || ['', ''])[1]}">${E((ST[p.status] || [p.status])[0])}</span></div>
        <p class="muted" style="margin:6px 0">${E(p.reason)}</p>
        ${p.kind === 'meta' ? `<table class="ad-table"><tbody><tr><th>Now</th><td>${E(p.before.title)}<br><small>${E(p.before.description)}</small></td></tr><tr><th>Proposed</th><td><b>${E(p.data.title)}</b><br><small>${E(p.data.description)}</small></td></tr></tbody></table>`
          : `<p style="margin:6px 0">Add a link "<b>${E(p.data.label)}</b>" on:</p><ul style="margin:0;padding-left:18px">${p.data.from.map(f => `<li><a href="${E(f)}" target="_blank" rel="noopener">${E(f)}</a></li>`).join('')}</ul>`}
        <div style="margin-top:8px">${p.status === 'pending' ? `<button class="button primary small" data-a="approve" data-id="${E(p.id)}">Approve</button> <button class="button secondary small" data-a="reject" data-id="${E(p.id)}">Reject</button>` : p.status === 'approved' ? `<button class="button secondary small" data-a="undo" data-id="${E(p.id)}">Undo</button>` : ''}</div></div>`).join('') : '<p class="muted">No proposals here.</p>';
      l.querySelectorAll('button[data-a]').forEach(b => { b.onclick = async () => { b.disabled = true; try { await api('/api/admin/growth/seo/decide', { method: 'POST', body: { id: b.dataset.id, action: b.dataset.a } }); toast(b.dataset.a === 'approve' ? 'Live. Search engines are told within the hour.' : b.dataset.a === 'undo' ? 'Undone' : 'Rejected'); proposals(box); } catch (x) { toast(err(x)); b.disabled = false; } }; });
      K.pager($('#spP', box), d, (p, per) => { P.page = p; P.per = per; proposals(box); });
    } catch (e) { l.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  async function reports(h) {
    const tbl = $('#grT', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(R.page), per: String(R.per) }); if (R.slot !== 'all') qs.set('slot', R.slot); if (R.band !== 'all') qs.set('band', R.band);
      const d = await api('/api/admin/growth/reports?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Day</th><th>Report</th><th>Band</th><th></th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${E(String(r.day).slice(0, 10))}</td><td>${({ weekly: 'Weekly', midday: '2 PM', evening: '7 PM' })[r.slot] || E(r.slot)}</td><td><span class="pill ${(BAND[r.band] || BAND.none)[1]}">${E((BAND[r.band] || BAND.none)[0])}</span></td><td><button class="button secondary small" data-id="${E(r.id)}">Read</button></td></tr>`).join('')}</tbody>` : `<tbody><tr><td class="muted">No reports yet. ${D.settings.reportEvery === 'daily' ? 'The first one goes out at 2 PM.' : 'The first one goes out on Monday morning.'}</td></tr></tbody>`;
      tbl.querySelectorAll('button[data-id]').forEach(b => { b.onclick = async () => { try { const r = (await api('/api/admin/growth/report?id=' + encodeURIComponent(b.dataset.id))).report; modal('Growth report', `<pre style="white-space:pre-wrap;font:inherit;margin:0">${E(r.body.replace(/^\[button:.*$/m, '').replace(/^#+ /gm, ''))}</pre>`); } catch (x) { toast(err(x)); } }; });
      K.pager($('#grP', h), d, (p, per) => { R.page = p; R.per = per; reports(h); });
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  function settings() {
    const s = D.settings;
    const m = modal('Targets and settings', `<form id="grForm" class="ops-form">
      <label>Monthly AI spending cap (₹, 0 = no limit)<input name="budgetInr" type="number" min="0" step="100" value="${E(s.budgetInr)}" required></label>
      <label>Stretch target (% of committed)<input name="stretchPct" type="number" min="100" max="500" value="${E(s.stretchPct)}" required></label>
      <label>Average purchase (₹)<input name="avg" type="number" min="1" step="0.01" value="${E(s.avgPaise / 100)}" required></label>
      <label>Send reports to (blank = every owner account)<input name="reportEmail" type="email" value="${E(s.reportEmail)}"></label>
      <label class="wide" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="reports" ${s.reports ? 'checked' : ''}> Email me the growth report</label>
      <label>How often<select name="reportEvery"><option value="weekly">Weekly (Monday morning)</option><option value="daily">Daily at 2 PM and 7 PM (Monday to Saturday)</option></select></label>
      <label>US$ to ₹<input name="usdInr" type="number" min="1" step="0.01" value="${E(s.usdInr)}" required></label>
      <label>Input price (US$ per million tokens)<input name="inputUsdPerM" type="number" min="0" step="0.01" value="${E(s.inputUsdPerM)}" required></label>
      <label>Output price (US$ per million tokens)<input name="outputUsdPerM" type="number" min="0" step="0.01" value="${E(s.outputUsdPerM)}" required></label>
      <fieldset class="wide" style="border:0;padding:0;margin:0"><legend>Purchases target per month</legend><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px">
        ${Object.entries(s.ladder).map(([k, v]) => `<label class="ops-f">${E(monthName(k))}<input type="number" min="0" data-month="${E(k)}" value="${E(v)}"></label>`).join('')}</div></fieldset>
      <div class="wide"><button class="button primary">Save</button> <span role="status" id="grSt"></span></div></form>`);
    const fm = $('#grForm', m.el); fm.reportEvery.value = s.reportEvery || 'weekly';
    fm.onsubmit = async e => {
      e.preventDefault(); const st = $('#grSt', m.el);
      const ladder = {}; fm.querySelectorAll('[data-month]').forEach(i => { ladder[i.dataset.month] = Number(i.value || 0); });
      const body = { budgetInr: Number(fm.budgetInr.value), stretchPct: Number(fm.stretchPct.value), avgPaise: Math.round(Number(fm.avg.value) * 100), reportEmail: fm.reportEmail.value.trim(), reports: fm.reports.checked, reportEvery: fm.reportEvery.value, usdInr: Number(fm.usdInr.value), inputUsdPerM: Number(fm.inputUsdPerM.value), outputUsdPerM: Number(fm.outputUsdPerM.value), ladder };
      try { if (!(await ensureRecent())) return; await api('/api/admin/growth/settings', { method: 'POST', body }); toast('Saved'); m.close(); growth(); } catch (x) { st.textContent = err(x); }
    };
  }

  K.register('growth', growth, ['📈', 'Growth', 'This month against the first-year plan, the funnel, the SEO agent, the owner reports and AI spending.']);
})();
