/* Owner console: Growth — the first-year target ladder, this month against plan, the funnel, the 2 PM / 7 PM reports and the Claude spending cap. Every number comes from the server; a number the site cannot measure shows as "Data unavailable". */
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
    h.innerHTML = `<div class="pg-head"><p>Where CVTara stands against the first-year plan of ${n(D.year.totalTarget)} purchases (${inr(D.year.totalPaise)} gross). Numbers are counted from payments, sign-ups and resumes; nothing is estimated. Reports go to you at 2 PM and 7 PM, Monday to Saturday.</p></div>
      <div class="ops-kpis">
        <div class="kpi"><small>${E(M.label)} purchases</small><b>${n(m.purchases)}</b><small>${M.target ? 'of ' + n(M.target) + ' committed · ' + n(M.stretch) + ' stretch' : 'no target set'}</small></div>
        <div class="kpi"><small>Gross sales</small><b>${inr(m.grossPaise)}</b><small>${M.targetPaise ? 'of ' + inr(M.targetPaise) + ' · net ' + inr(m.netPaise) : 'net ' + inr(m.netPaise)}</small></div>
        <div class="kpi"><small>Against pace</small><b>${M.attainment === null ? '—' : M.attainment + '%'}</b><small><span class="pill ${b[1]}">${E(b[0])}</span></small></div>
        <div class="kpi"><small>Needed per day</small><b>${M.perDayNeeded === null ? '—' : n(M.perDayNeeded)}</b><small>purchases for the rest of the month</small></div>
        <div class="kpi"><small>New paying customers</small><b>${n(m.newCustomers)}</b><small>${n(m.repeatPurchases)} repeat purchases</small></div>
      </div>
      <section class="panel panel-pad"><h3 style="margin-top:0">Next actions</h3><ul style="margin:0;padding-left:18px">${D.actions.map(a => `<li>${E(a)}</li>`).join('')}</ul></section>
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
        <section class="panel panel-pad"><h3 style="margin-top:0">Claude spending this month</h3>
          <p style="margin-top:0"><b>${inr(B.spentPaise)}</b> of ${inr(B.capPaise)} cap${B.pct === null ? '' : ' (' + B.pct + '%)'}</p>
          <div class="ops-bar" style="height:8px;border-radius:4px;background:var(--line,#e5e7eb);overflow:hidden"><div style="height:100%;width:${Math.min(100, B.pct || 0)}%;background:${B.pct >= 90 ? '#dc2626' : B.pct >= 75 ? '#d97706' : '#16a34a'}"></div></div>
          <p class="muted">${Object.entries(B.byPurpose).map(([k, v]) => `${E(k === 'customer' ? 'Customer add-ons' : k)}: ${inr(v.paise)} (${n(v.calls)} requests)`).join(' · ') || 'No requests yet this month.'}</p>
          <p class="muted" style="margin-bottom:0">Alerts at 50%, 75% and 90% go to the Error Log and the reports. At 100% growth tasks stop; paid customer add-ons keep working. Cost is worked out from the token prices below, so keep them in line with the Claude pricing page.</p></section>
        <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Colleges with the most users</h3>
          <p class="muted" style="margin-top:0">Totals of users who agreed to emails, read from their resumes. Use it to choose which placement cells to contact first. It never shows who the students are.</p>
          ${D.colleges.length ? `<table class="ad-table"><tbody>${D.colleges.map(c => `<tr><td>${E(c.college)}</td><td>${n(c.users)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">Nothing yet.</p>'}</section>
      </div>
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Reports</h3>
        <div class="ops-filterbox"><label class="ops-f">Time<select id="grS"><option value="all">Both</option><option value="midday">2 PM</option><option value="evening">7 PM</option></select></label>
          <label class="ops-f">Band<select id="grB"><option value="all">All</option>${Object.entries(BAND).map(([k, v]) => `<option value="${k}">${E(v[0])}</option>`).join('')}</select></label>
          <button class="button secondary small" id="grNow">Send a report now</button> <button class="button secondary small" id="grSet">Targets and settings</button></div>
        <div style="overflow:auto"><table class="ad-table" id="grT"><tbody><tr><td>Loading…</td></tr></tbody></table></div><div class="ops-pager" id="grP"></div></section>`;
    $('#grS', h).value = R.slot; $('#grB', h).value = R.band;
    $('#grS', h).onchange = e => { R.slot = e.target.value; R.page = 1; reports(h); };
    $('#grB', h).onchange = e => { R.band = e.target.value; R.page = 1; reports(h); };
    $('#grNow', h).onclick = async () => { try { const hr = new Date(Date.now() + 5.5 * 3600e3).getUTCHours(); await api('/api/admin/growth/report-now', { method: 'POST', body: { slot: hr >= 19 ? 'evening' : 'midday' } }); toast('Report saved and emailed'); R.page = 1; reports(h); } catch (x) { toast(err(x)); } };
    $('#grSet', h).onclick = settings;
    reports(h);
  }

  async function reports(h) {
    const tbl = $('#grT', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(R.page), per: String(R.per) }); if (R.slot !== 'all') qs.set('slot', R.slot); if (R.band !== 'all') qs.set('band', R.band);
      const d = await api('/api/admin/growth/reports?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Day</th><th>Report</th><th>Band</th><th></th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${E(String(r.day).slice(0, 10))}</td><td>${r.slot === 'midday' ? '2 PM' : '7 PM'}</td><td><span class="pill ${(BAND[r.band] || BAND.none)[1]}">${E((BAND[r.band] || BAND.none)[0])}</span></td><td><button class="button secondary small" data-id="${E(r.id)}">Read</button></td></tr>`).join('')}</tbody>` : '<tbody><tr><td class="muted">No reports yet. The first one goes out at 2 PM.</td></tr></tbody>';
      tbl.querySelectorAll('button[data-id]').forEach(b => { b.onclick = async () => { try { const r = (await api('/api/admin/growth/report?id=' + encodeURIComponent(b.dataset.id))).report; modal('Growth report', `<pre style="white-space:pre-wrap;font:inherit;margin:0">${E(r.body.replace(/^\[button:.*$/m, '').replace(/^#+ /gm, ''))}</pre>`); } catch (x) { toast(err(x)); } }; });
      K.pager($('#grP', h), d, (p, per) => { R.page = p; R.per = per; reports(h); });
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  function settings() {
    const s = D.settings;
    const m = modal('Targets and settings', `<form id="grForm" class="ops-form">
      <label>Monthly Claude cap (₹)<input name="budgetInr" type="number" min="0" step="100" value="${E(s.budgetInr)}" required></label>
      <label>Stretch target (% of committed)<input name="stretchPct" type="number" min="100" max="500" value="${E(s.stretchPct)}" required></label>
      <label>Average purchase (₹)<input name="avg" type="number" min="1" step="0.01" value="${E(s.avgPaise / 100)}" required></label>
      <label>Send reports to (blank = every owner account)<input name="reportEmail" type="email" value="${E(s.reportEmail)}"></label>
      <label class="wide" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="reports" ${s.reports ? 'checked' : ''}> Email the 2 PM and 7 PM reports (Monday to Saturday)</label>
      <label>US$ to ₹<input name="usdInr" type="number" min="1" step="0.01" value="${E(s.usdInr)}" required></label>
      <label>Input price (US$ per million tokens)<input name="inputUsdPerM" type="number" min="0" step="0.01" value="${E(s.inputUsdPerM)}" required></label>
      <label>Output price (US$ per million tokens)<input name="outputUsdPerM" type="number" min="0" step="0.01" value="${E(s.outputUsdPerM)}" required></label>
      <fieldset class="wide" style="border:0;padding:0;margin:0"><legend>Purchases target per month</legend><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px">
        ${Object.entries(s.ladder).map(([k, v]) => `<label class="ops-f">${E(monthName(k))}<input type="number" min="0" data-month="${E(k)}" value="${E(v)}"></label>`).join('')}</div></fieldset>
      <div class="wide"><button class="button primary">Save</button> <span role="status" id="grSt"></span></div></form>`);
    const fm = $('#grForm', m.el);
    fm.onsubmit = async e => {
      e.preventDefault(); const st = $('#grSt', m.el);
      const ladder = {}; fm.querySelectorAll('[data-month]').forEach(i => { ladder[i.dataset.month] = Number(i.value || 0); });
      const body = { budgetInr: Number(fm.budgetInr.value), stretchPct: Number(fm.stretchPct.value), avgPaise: Math.round(Number(fm.avg.value) * 100), reportEmail: fm.reportEmail.value.trim(), reports: fm.reports.checked, usdInr: Number(fm.usdInr.value), inputUsdPerM: Number(fm.inputUsdPerM.value), outputUsdPerM: Number(fm.outputUsdPerM.value), ladder };
      try { if (!(await ensureRecent())) return; await api('/api/admin/growth/settings', { method: 'POST', body }); toast('Saved'); m.close(); growth(); } catch (x) { st.textContent = err(x); }
    };
  }

  K.register('growth', growth, ['📈', 'Growth', 'This month against the first-year plan, the funnel, the 2 PM / 7 PM reports and the Claude spending cap.']);
})();
