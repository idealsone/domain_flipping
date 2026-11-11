/* CVTara partner portal (3.54.0, PartnerHub). Shown only to people a partner organisation added as portal members.
   Numbers for their own organisation only: sign-ups, buyers, revenue and commission by month and by campaign link.
   Never a student's name, email or documents. Every number comes from the server (/api/partner). */
(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace') return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const rs = p => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN');
  const day = s => { if (!s) return ''; const d = new Date(String(s).slice(0, 10) + 'T00:00:00'); return isNaN(d) ? '' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }); };
  let data = null;

  async function get() {
    const r = await fetch('/api/partner', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return r.ok ? r.json() : null;
  }

  function addNav() {
    if (document.querySelector('[data-workspace-view="partner"]')) return;
    const anchor = document.querySelector('[data-workspace-view="interview"]');
    if (!anchor) return;
    const b = document.createElement('button'); b.dataset.workspaceView = 'partner'; b.innerHTML = '<i aria-hidden="true">◇</i>Partner portal';
    anchor.after(b);
    if (!document.getElementById('view-partner')) {
      const s = document.createElement('section'); s.className = 'workspace-view'; s.id = 'view-partner'; s.innerHTML = '<p>Loading…</p>';
      const ref = document.getElementById('view-interview'); (ref ? ref.parentNode : document.querySelector('main') || document.body).insertBefore(s, ref ? ref.nextSibling : null);
    }
  }

  function draw() {
    const h = document.getElementById('view-partner'); if (!h || !data) return;
    const p = data.partner, c = data.commission;
    const copy = u => `<button type="button" class="button secondary small" data-pp-copy="${E(u)}">Copy</button>`;
    h.innerHTML = `<div class="ip-card"><h2 style="margin:0 0 4px">${E(p.name)}</h2><p class="ip-muted">${E(p.kind)} · ${p.status === 'active' ? 'Active partner' : 'Partnership ' + E(p.status)}${p.agreementFrom ? ' · agreement from ' + day(p.agreementFrom) : ''}${p.agreementTo ? ' to ' + day(p.agreementTo) : ''}</p>
      <p class="ip-muted">Commission: ${p.planPercent}% of Job Hunt, Pro and other plans, ${p.addonPercent}% of add-ons. The ₹${p.excludedUpToInr} Quick Download earns none. Each amount waits ${p.holdDays} days (the refund window) before it is ready to pay, and a refund cancels it. You see totals only, never a student's details.</p></div>
      <div class="ops-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px">${[['Sign-ups', data.signups], ['Buyers', data.buyers], ['Revenue', rs(data.revenuePaise)], ['Pending', rs(c.pendingPaise)], ['Ready to pay', rs(c.availablePaise)], ['Paid to you', rs(c.paidPaise)]].map(([l, v]) => `<div class="ip-card" style="margin:0"><span class="ip-muted">${l}</span><br><b style="font-size:20px">${E(v)}</b></div>`).join('')}</div>
      <div class="ip-card"><h3>Your links</h3><p class="ip-muted">Share these with your students. Anyone who signs up within 30 days of opening a link is counted for you.</p>
        ${data.links.length ? data.links.map(l => `<div class="ip-row" style="padding:8px 0;border-top:1px solid var(--line,#e2e8f0)"><code style="flex:1;min-width:220px">${E(l.url)}</code>${copy(l.url)}<span class="ip-muted">${E(l.campaign || '')} · ${l.clicks} visits · ${l.signups} sign-ups${l.active ? '' : ' · paused'}</span></div>`).join('') : '<p class="ip-muted">Your links will appear here once CVTara sets them up.</p>'}</div>
      <div class="ip-card"><h3>By month</h3>${data.months.length ? `<table style="width:100%;border-collapse:collapse"><thead><tr><th align="left">Month</th><th align="right">Sign-ups</th><th align="right">Buyers</th><th align="right">Revenue</th></tr></thead><tbody>${data.months.map(m => `<tr><td>${E(m.month)}</td><td align="right">${m.signups}</td><td align="right">${m.buyers}</td><td align="right">${rs(m.revenuePaise)}</td></tr>`).join('')}</tbody></table>` : '<p class="ip-muted">No sign-ups yet.</p>'}</div>`;
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-pp-copy]'); if (!b) return;
    navigator.clipboard?.writeText(b.dataset.ppCopy).then(() => { b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1500); });
  });
  document.addEventListener('cvtara-workspace-view', async e => { if (e.detail === 'partner') { data = await get().catch(() => null) || data; draw(); } });
  get().then(j => { if (j && j.partner) { data = j; addNav(); } }).catch(() => {});
})();
