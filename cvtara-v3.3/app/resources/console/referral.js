/* Owner console: Referral Ops — the single place for every referral rule, affiliate, creator, payout identity and withdrawal. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api, short, parse, dmy, pill, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const chk = (n, t) => `<label class="ops-check"><input type="checkbox" name="${n}"> ${t}</label>`;
  const post = async (path, body) => { if (!(await ensureRecent())) throw new Error('Please confirm your identity to continue.'); return api('/api/admin/' + path, { method: 'POST', body }); };
  let D = null;

  async function referral() {
    const h = $('#admin-referral'); if (!h) return;
    if (!D) h.innerHTML = '<div class="ops-skel"></div>';
    try { D = await api('/api/admin/referrals'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const r = D.rules || {}, aff = D.affiliates || [], wd = D.withdrawals || [], cr = D.creators || [], pf = D.profiles || [];
    const num = (n, label, v, o = '') => `<label>${label}<input type="number" name="${n}" value="${v}" ${o}></label>`;
    const sw = (n, label, on) => `<label>${label}<span class="ops-sw"><input type="checkbox" name="${n}" ${on ? 'checked' : ''}> <em>${on ? 'On' : 'Off'}</em></span></label>`;
    const pend = wd.filter(w => w.status === 'requested').length, pcount = pf.filter(p => p.status !== 'approved' && p.status !== 'rejected').length, ccount = cr.filter(c => c.status === 'pending').length;
    h.innerHTML = `<div class="pg-head"><h2>Referral Ops</h2><p>Every referral rule, affiliate, creator application, payout identity and withdrawal is managed here.</p></div>
      <div class="ops-kpis"><div class="kpi"><span>Affiliates</span><b>${aff.length}</b></div><div class="kpi"><span>Withdrawals waiting</span><b>${pend}</b></div><div class="kpi"><span>Identity reviews</span><b>${pcount}</b></div><div class="kpi"><span>Creator applications</span><b>${ccount}</b></div></div>
      <section class="panel panel-pad"><h3>Referral rules</h3><form id="rfForm" class="ops-form four">
        ${sw('enabled', 'Programme', r.enabled)}${num('rateBps', 'Standard commission (%)', (r.rateBps || 0) / 100, 'min="0" max="50" step="0.5"')}${num('tierThreshold', 'Tier threshold (paying friends)', r.tierThreshold, 'min="1"')}${num('tierRateBps', 'Tier commission (%)', (r.tierRateBps || 0) / 100, 'min="0" max="50" step="0.5"')}
        ${num('minimumPaise', 'Minimum withdrawal (₹)', (r.minimumPaise || 0) / 100, 'min="1"')}${num('holdDays', 'Hold period (days)', r.holdDays, 'min="1" max="60"')}${num('payoutHours', 'Payout window (hours)', r.payoutHours, 'min="1" max="720"')}${num('minQualifying', 'Minimum qualifying order (₹)', (r.minQualifyingPaise || 0) / 100, 'min="0"')}
        ${num('downloadThreshold', 'Free-download reward (paying friends)', r.downloadThreshold, 'min="1"')}${num('premiumThreshold', 'Premium reward (paying friends)', r.premiumThreshold, 'min="2"')}${num('premiumDays', 'Premium reward length (days)', r.premiumDays, 'min="1" max="365"')}
        ${sw('requirePan', 'Require PAN for payouts', r.requirePan)}${sw('proOnly', 'Commission on paid plans only', r.proOnly)}
        <div class="ops-end"><button class="button primary">Save rules</button> <span id="rfSt" role="status"></span></div></form></section>
      <h3 class="ops-h3">Withdrawal queue</h3><section class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>Customer</th><th>Amount</th><th>Destination</th><th>Requested</th><th>Status</th><th class="r">Actions</th></tr></thead><tbody>${wd.map(w => `<tr><td><b>${E(w.name)}</b><br><small>${E(w.email)}</small></td><td><b>${short(w.amount_paise)}</b></td><td>${E(w.destination_masked || '—')}</td><td>${dmy(parse(w.created_at))}</td><td>${pill(w.status)}${w.approved_at ? ' <small>reviewed</small>' : ''}</td><td class="r">${wdActions(w)}</td></tr>`).join('') || '<tr><td colspan="6">No withdrawal requests right now.</td></tr>'}</tbody></table></section>
      <h3 class="ops-h3">Payout identity reviews</h3><section class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>Customer</th><th>Destination</th><th>Status</th><th>Risk flags</th><th class="r">Actions</th></tr></thead><tbody>${pf.map(p => `<tr><td><b>${E(p.name)}</b><br><small>${E(p.email)}</small></td><td>${E(p.destination_masked || '—')}</td><td>${pill(p.status)}</td><td>${E(flags(p.risk_flags_json))}</td><td class="r"><button class="button small secondary" data-pf-view="${p.owner_id}">View</button> ${p.status !== 'approved' ? `<button class="button small ops-ok" data-pf="${p.owner_id}">Review</button>` : ''}</td></tr>`).join('') || '<tr><td colspan="5">No payout identities submitted yet.</td></tr>'}</tbody></table></section>
      <h3 class="ops-h3">Creator applications</h3><section class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>Applicant</th><th>Rate</th><th>Status</th><th>Note</th><th class="r">Actions</th></tr></thead><tbody>${cr.map(c => `<tr><td><b>${E(c.name)}</b><br><small>${E(c.email)}</small></td><td>${(c.rate_bps || 0) / 100}%</td><td>${pill(c.status)}</td><td>${E(c.review_note || '')}</td><td class="r"><button class="button small ops-ok" data-cr="${c.owner_id}" data-rate="${c.rate_bps || r.rateBps || 0}">Review</button></td></tr>`).join('') || '<tr><td colspan="5">No creator applications.</td></tr>'}</tbody></table></section>
      <h3 class="ops-h3">Affiliate balances</h3><section class="panel" style="overflow:auto"><table class="ad-table"><thead><tr><th>Name</th><th>Email</th><th>Rate</th><th>Available</th><th>In review</th><th>Total paid</th></tr></thead><tbody>${aff.map(a => `<tr><td><b>${E(a.name)}</b></td><td>${E(a.email)}</td><td>${(a.rateBps || 0) / 100}%</td><td>${short(a.availablePaise)}</td><td>${short((a.pendingPaise || 0) + (a.reservedPaise || 0))}</td><td>${short(a.paidPaise)}</td></tr>`).join('') || '<tr><td colspan="6">No affiliates yet.</td></tr>'}</tbody></table></section>`;
    $('#rfForm').onsubmit = save; h.onclick = click;
  }
  const flags = j => { try { const a = JSON.parse(j || '[]'); return (Array.isArray(a) ? a : Object.keys(a)).join(', ') || 'none'; } catch (e) { return 'none'; } };
  function wdActions(w) {
    if (['paid', 'rejected'].includes(w.status)) return `<button class="button small secondary" data-wd-view="${w.id}">Destination</button>`;
    const b = [`<button class="button small secondary" data-wd-view="${w.id}">Destination</button>`];
    if (w.status === 'requested' && !w.approved_at) b.push(`<button class="button small ops-ok" data-wd-review="${w.id}">Review</button>`);
    else { b.push(`<button class="button small ops-ok" data-wd-send="${w.id}" data-amt="${w.amount_paise}">Send</button>`, `<button class="button small secondary" data-wd-manual="${w.id}">Mark…</button>`); if (w.provider_transfer_id) b.push(`<button class="button small secondary" data-wd-sync="${w.id}">Sync</button>`); }
    return b.join(' ');
  }
  async function save(e) {
    e.preventDefault(); const f = e.target.elements, st = $('#rfSt'); st.textContent = 'Saving…';
    const n = k => Number(f[k].value), body = { enabled: f.enabled.checked, requirePan: f.requirePan.checked, proOnly: f.proOnly.checked, holdDays: n('holdDays'), rateBps: Math.round(n('rateBps') * 100), tierRateBps: Math.round(n('tierRateBps') * 100), tierThreshold: n('tierThreshold'), minimumPaise: Math.round(n('minimumPaise') * 100), payoutHours: n('payoutHours'), minQualifyingPaise: Math.round(n('minQualifying') * 100), downloadThreshold: n('downloadThreshold'), premiumThreshold: n('premiumThreshold'), premiumDays: n('premiumDays') };
    try { await post('referrals/settings', body); st.textContent = 'Saved.'; toast('Referral rules saved'); D = null; referral(); } catch (x) { st.textContent = err(x); toast(err(x), true); }
  }
  const dest = d => Object.entries(d || {}).map(([k, v]) => `<div><small>${E(k)}</small><br><b>${E(String(v))}</b></div>`).join('') || 'No details.';
  const checks = () => `${chk('identityChecked', 'Identity matches the account holder')}${chk('destinationChecked', 'Destination details verified')}${chk('riskChecked', 'No risk flags outstanding')}${chk('taxChecked', 'Tax details collected where required')}<label>Review note (min 10 characters)<textarea name="note" rows="3" required minlength="10"></textarea></label>`;
  function form(title, inner, submit, run) {
    const m = modal(title, `<form class="ops-form" id="rfm">${inner}<div class="ops-end"><button class="button primary">${submit}</button> <span id="rfmSt" role="status"></span></div></form>`);
    m.body.querySelector('#rfm').onsubmit = async ev => { ev.preventDefault(); const st = $('#rfmSt', m.body); st.textContent = 'Working…'; try { await run(ev.target.elements); m.close(); toast('Done'); D = null; referral(); } catch (x) { st.textContent = err(x); } };
  }
  async function click(e) {
    const t = e.target.closest('button'); if (!t) return; const ds = t.dataset;
    try {
      if (ds.wdView) { const d = await api('/api/admin/referrals/withdrawals/' + ds.wdView); modal('Payout destination', `<div class="ops-form">${dest(d.destination)}</div>`); }
      else if (ds.pfView) { const d = await api('/api/admin/referrals/profiles/' + ds.pfView); modal('Payout identity', `<div class="ops-form">${dest(d.destination)}</div>`); }
      else if (ds.wdReview) form('Review withdrawal', checks(), 'Approve for payout', f => post(`referrals/withdrawals/${ds.wdReview}/review`, { identityChecked: f.identityChecked.checked, destinationChecked: f.destinationChecked.checked, riskChecked: f.riskChecked.checked, taxChecked: f.taxChecked.checked, note: f.note.value }));
      else if (ds.wdSend) form('Send payout', `<p>Type the amount to confirm: <b>${short(Number(ds.amt))}</b></p><label>Amount (₹)<input name="amt" type="number" step="0.01" required></label>`, 'Send now', f => post(`referrals/withdrawals/${ds.wdSend}/send`, { confirmAmountPaise: Math.round(Number(f.amt.value) * 100), confirmSend: true }));
      else if (ds.wdManual) form('Update withdrawal', `<label>Status<select name="status"><option value="processing">Processing</option><option value="paid">Paid</option><option value="rejected">Rejected</option></select></label><label>Bank reference<input name="reference" maxlength="120"></label><label>Note<textarea name="note" rows="2"></textarea></label>`, 'Save', f => post(`referrals/withdrawals/${ds.wdManual}`, { status: f.status.value, reference: f.reference.value, note: f.note.value }));
      else if (ds.wdSync) { await post(`referrals/withdrawals/${ds.wdSync}/sync`, {}); toast('Status synced'); D = null; referral(); }
      else if (ds.pf) form('Review payout identity', `<label>Decision<select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select></label>${checks()}`, 'Save decision', f => post(`referrals/profiles/${ds.pf}`, { status: f.status.value, note: f.note.value, identityChecked: f.identityChecked.checked, destinationChecked: f.destinationChecked.checked, riskChecked: f.riskChecked.checked, taxChecked: f.taxChecked.checked }));
      else if (ds.cr) form('Review creator application', `<label>Decision<select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select></label><label>Commission (%)<input name="rate" type="number" min="0" max="50" step="0.5" value="${Number(ds.rate) / 100}"></label><label>Note (min 10 characters)<textarea name="note" rows="3" required minlength="10"></textarea></label>`, 'Save decision', f => post(`referrals/creators/${ds.cr}`, { status: f.status.value, rateBps: Math.round(Number(f.rate.value) * 100), note: f.note.value }));
    } catch (x) { toast(err(x), true); }
  }
  K.register('referral', referral, ['🎁', 'Referral Ops', 'Every referral rule, affiliate, creator application, payout identity and withdrawal in one place.']);
})();
