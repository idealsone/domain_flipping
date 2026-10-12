(() => {
  'use strict';
  // Owner console: "Funnel & packages" tab. Shows how visitors move from the free ATS check to paying,
  // and the packages exactly as customers see them. Prices are edited in "Plans & payments".
  const host = document.getElementById('admin-funnel');
  if (!host || !window.CVTaraAPI) return;
  const api = p => window.CVTaraAPI.request(p);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rupees = paise => '\u20B9' + Math.round(paise / 100).toLocaleString('en-IN');
  const pct = (a, b) => (b > 0 ? Math.round((a / b) * 100) + '%' : '\u2013');
  let days = 30, loaded = false;

  const STEPS = [
    ['visitors', 'Visited the website', 'Only visitors who accepted analytics cookies are counted, so this is a minimum'],
    ['atsChecks', 'Ran the free ATS check', 'Uploaded or pasted a resume on the landing page'],
    ['buildClicks', 'Clicked "Build my resume"', 'Moved from the check result into the builder'],
    ['signups', 'Created an account', 'New customer accounts'],
    ['resumesSaved', 'Saved a resume', 'Customers with at least one saved resume'],
    ['tailored', 'Tailored it to a job description', 'Pasted a job description in the builder'],
    ['checkoutStarted', 'Started a purchase', 'Customers with a purchase attempt'],
    ['paid', 'Paid (live)', 'Verified live payments only; test purchases are excluded'],
  ];

  function render(d) {
    const f = d.funnel, top = Math.max(1, ...STEPS.map(s => f[s[0]] || 0));
    const bars = STEPS.map(([k, label, hint], i) => {
      const n = f[k] || 0, prev = i ? f[STEPS[i - 1][0]] || 0 : 0;
      return `<div class="fnx-row"><div class="fnx-label"><strong>${E(label)}</strong><small>${E(hint)}</small></div>
        <div class="fnx-bar"><span style="width:${Math.max(n ? 2 : 0, Math.round((n / top) * 100))}%"></span></div>
        <div class="fnx-num"><b>${n.toLocaleString('en-IN')}</b><small>${i ? pct(n, prev) + ' of previous' : ''}</small></div></div>`;
    }).join('');
    const clicks = Object.fromEntries((d.planClicks || []).map(c => [c.detail, c.n]));
    const sold = Object.fromEntries((d.byPlan || []).map(c => [c.plan, c]));
    const plans = d.plans.map(p => {
      const s = sold[p.id] || { count: 0, paise: 0 };
      return `<tr><td><strong>${E(p.name)}</strong><br><small>${E(p.id)}</small></td><td>${p.price ? rupees(p.price * 100) : 'Free'}</td>
        <td>${E(p.savedResumes)} resumes &middot; ${E(p.dailyMatches)} job matches/day &middot; ${E(p.exports ?? '')} exports</td>
        <td>${p.accessDays ? E(p.accessDays) + (p.bonusDays ? ' (incl. ' + E(p.bonusDays) + ' bonus)' : '') + ' days' : 'No expiry'}</td>
        <td>${clicks[p.id] || 0}</td><td>${s.count}</td><td>${rupees(s.paise)}</td></tr>`;
    }).join('');
    host.innerHTML = `
      <div class="admin-heading"><div><h2>Funnel &amp; packages</h2>
        <p>How visitors move from the free ATS check, to building a resume for a job, to buying a package.</p></div>
        <label>Period <select id="fnxDays">${[7, 30, 90, 365].map(n => `<option value="${n}"${n === d.days ? ' selected' : ''}>Last ${n} days</option>`).join('')}</select></label></div>
      <section class="panel panel-pad"><h3>Customer journey</h3>${bars}</section>
      <section class="panel panel-pad"><h3>Revenue</h3>
        <p><strong style="font-size:1.6rem">${rupees(d.revenue.livePaise)}</strong> from verified live payments in the last ${d.days} days.
        ${d.revenue.testPurchases ? `<br><small>${d.revenue.testPurchases} test purchase(s) are not counted.</small>` : ''}</p></section>
      <section class="panel panel-pad"><h3>Packages customers see right now</h3>
        <p>These come from your saved plans. Change a price or limit in <a href="#" id="fnxEdit">Plans &amp; payments</a> and the landing page, builder and checkout update at once.</p>
        <div style="overflow:auto"><table class="fnx-table"><thead><tr><th>Package</th><th>Price</th><th>Includes</th><th>Access</th><th>Clicked</th><th>Sold</th><th>Revenue</th></tr></thead><tbody>${plans}</tbody></table></div>
        <p><a class="button" href="/#pricing" target="_blank" rel="noopener">View as customer</a>
        <button class="button primary" type="button" id="fnxEdit2">Edit packages &amp; prices</button></p></section>
      <style>.fnx-row{display:grid;grid-template-columns:minmax(180px,1.2fr) 3fr 110px;gap:14px;align-items:center;padding:8px 0;border-bottom:1px solid rgba(128,128,128,.18)}
      .fnx-label small,.fnx-num small{display:block;opacity:.65;font-size:.78rem}.fnx-bar{background:rgba(128,128,128,.15);border-radius:99px;height:14px;overflow:hidden}
      .fnx-bar span{display:block;height:100%;background:linear-gradient(90deg,#4f46e5,#7c3aed);border-radius:99px}.fnx-num{text-align:right}
      .fnx-table{width:100%;border-collapse:collapse}.fnx-table th,.fnx-table td{padding:8px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,.18)}
      @media(max-width:700px){.fnx-row{grid-template-columns:1fr}}</style>`;
    host.querySelector('#fnxDays').onchange = e => { days = +e.target.value; load(); };
    const edit = e => { e.preventDefault(); document.querySelector('[data-admin-view="commerce"]')?.click(); };
    host.querySelector('#fnxEdit').onclick = edit;
    host.querySelector('#fnxEdit2').onclick = edit;
  }

  async function load() {
    try { render(await api('/api/admin/system/funnel?days=' + days)); loaded = true; }
    catch (e) { host.innerHTML = '<section class="panel panel-pad"><p role="status" style="color:#b42318">Could not load the funnel: ' + E(e.message || e) + '</p></section>'; }
  }

  const nav = document.querySelector('[data-admin-view="funnel"]');
  nav?.addEventListener('click', () => load());
  if (new URLSearchParams(location.search).get('tab') === 'funnel') { nav?.click(); load(); }
})();
