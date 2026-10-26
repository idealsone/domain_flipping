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
    const r = D.rules || {}, c = D.counts || {};
    const num = (n, label, v, o = '') => `<label>${label}<input type="number" name="${n}" value="${v}" ${o}></label>`;
    const sw = (n, label, on) => `<label>${label}<span class="ops-sw"><input type="checkbox" name="${n}" ${on ? 'checked' : ''}> <em>${on ? 'On' : 'Off'}</em></span></label>`;
    const wallet = (r.mode || 'wallet') === 'wallet', rs = p => '₹' + ((p || 0) / 100).toLocaleString('en-IN');
        h.innerHTML = `<div class="pg-head"><h2>Referral Ops</h2><p>Every referral rule, affiliate, creator application, payout identity and withdrawal is managed here.</p></div>
      <div class="ops-kpis"><div class="kpi"><span>Affiliates</span><b>${(c.affiliates || 0).toLocaleString('en-IN')}</b></div><div class="kpi"><span>Withdrawals waiting</span><b>${c.withdrawals || 0}</b></div><div class="kpi"><span>Identity reviews</span><b>${c.profiles || 0}</b></div><div class="kpi"><span>Creator applications</span><b>${c.creators || 0}</b></div></div>
      <section class="panel panel-pad"><h3>Referral rules</h3><form id="rfForm" class="ops-form four">
        <p class="ops-note wide" style="grid-column:1/-1;margin:0">${wallet ? `<b>Live now: Give ${rs(r.friendDiscountPaise)}, get ${rs(r.rewardPaise)}.</b> A friend gets ${rs(r.friendDiscountPaise)} off a first order of ${rs(r.minQualifyingPaise)} or more. ${r.qualifyDays} days later, if they have not asked for a refund, the customer who shared the link gets ${rs(r.rewardPaise)} wallet credit (usable for ${r.walletDays} days, up to ${rs(r.monthlyCapPaise)} a month). ${r.downloadThreshold} qualified friends unlock ${r.premiumDays} free days of Job Hunt.` : '<b>Live now: cash commission.</b> Customers earn a percentage of each friend’s payment and withdraw it to their bank.'}</p>
        ${sw('enabled', 'Programme', r.enabled)}<label>Reward type<select name="mode"><option value="wallet" ${wallet ? 'selected' : ''}>Give ₹, get ₹ wallet credit</option><option value="commission" ${wallet ? '' : 'selected'}>Cash commission (older)</option></select></label>${num('friendDiscount', 'Friend’s discount (₹)', (r.friendDiscountPaise || 0) / 100, 'min="0" max="1000"')}${num('reward', 'Credit for the customer who shared (₹)', (r.rewardPaise || 0) / 100, 'min="0" max="1000"')}
        ${num('minQualifying', 'Smallest order that earns the referral (₹)', (r.minQualifyingPaise || 0) / 100, 'min="0"')}${num('qualifyDays', 'Credit released after (days, the refund window)', r.qualifyDays, 'min="0" max="60"')}${num('walletDays', 'Wallet credit can be used for (days)', r.walletDays, 'min="7" max="730"')}${num('monthlyCap', 'Most credit one customer can earn a month (₹)', (r.monthlyCapPaise || 0) / 100, 'min="0"')}
        ${num('downloadThreshold', 'Qualified friends for the milestone reward', r.downloadThreshold, 'min="1"')}${num('premiumDays', 'Milestone reward: free Job Hunt days', r.premiumDays, 'min="1" max="365"')}
        <details class="wide" style="grid-column:1/-1" ${wallet ? '' : 'open'}><summary><b>Cash commission settings</b> <small>${wallet ? '(not used while “Give ₹, get ₹” is on; kept for balances customers earned before)' : ''}</small></summary><div class="ops-form four" style="margin-top:10px">
        ${num('rateBps', 'Standard commission (%)', (r.rateBps || 0) / 100, 'min="0" max="50" step="0.5"')}${num('tierThreshold', 'Tier threshold (paying friends)', r.tierThreshold, 'min="1"')}${num('tierRateBps', 'Tier commission (%)', (r.tierRateBps || 0) / 100, 'min="0" max="50" step="0.5"')}${num('minimumPaise', 'Minimum withdrawal (₹)', (r.minimumPaise || 0) / 100, 'min="1"')}
        ${num('holdDays', 'Hold period (days)', r.holdDays, 'min="1" max="60"')}${num('payoutHours', 'Payout window (hours)', r.payoutHours, 'min="1" max="720"')}${sw('requirePan', 'Require PAN for payouts', r.requirePan)}${sw('proOnly', 'Commission only for referrers on a paid plan', r.proOnly)}</div></details>
        <div class="ops-end"><button class="button primary">Save rules</button> <span id="rfSt" role="status"></span></div></form></section>
      <h3 class="ops-h3">Withdrawal queue</h3><section class="panel" id="rfWd"></section>
      <h3 class="ops-h3">Payout identity reviews</h3><section class="panel" id="rfPf"></section>
      <h3 class="ops-h3">Creator applications</h3><section class="panel" id="rfCr"></section>
      <h3 class="ops-h3">Affiliate balances</h3><section class="panel" id="rfAff"></section>`;
    // Each list is its own server-paged table; row buttons are handled by click() below.
    const who = x => `<b>${E(x.name)}</b><br><small>${E(x.email)}</small>`, url = k => '/api/admin/referrals/list/' + k;
    K.grid($('#rfWd'), { id: 'ref-wd', url: url('withdrawals'), search: 'Search customer or bank reference', sort: 'date', empty: 'No withdrawal requests right now.',
      filters: [{ key: 'status', label: 'Status', options: [['requested', 'Requested'], ['processing', 'Processing'], ['paid', 'Paid'], ['rejected', 'Rejected']] }, { key: 'from', label: 'From', type: 'date' }, { key: 'to', label: 'To', type: 'date' }],
      columns: [{ label: 'Customer', html: who }, { label: 'Amount', sort: 'amount', html: w => `<b>${short(w.amount_paise)}</b>` }, { label: 'Destination', html: w => E(w.destination_masked || '—') }, { label: 'Requested', sort: 'date', html: w => dmy(parse(w.created_at)) }, { label: 'Status', sort: 'status', html: w => `${pill(w.status)}${w.approved_at ? ' <small>reviewed</small>' : ''}` }, { label: 'Actions', cls: 'r', html: wdActions }] });
    K.grid($('#rfPf'), { id: 'ref-pf', url: url('profiles'), search: 'Search customer', sort: 'date', empty: 'No payout identities submitted yet.',
      filters: [{ key: 'status', label: 'Status', options: [['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected']] }],
      columns: [{ label: 'Customer', sort: 'name', html: who }, { label: 'Destination', html: p => E(p.destination_masked || '—') }, { label: 'Status', sort: 'status', html: p => pill(p.status) }, { label: 'Risk flags', html: p => E(flags(p.risk_flags_json)) }, { label: 'Actions', cls: 'r', html: p => `<button class="button small secondary" data-pf-view="${p.owner_id}">View</button> ${p.status !== 'approved' ? `<button class="button small ops-ok" data-pf="${p.owner_id}" data-hash="${E(p.destination_hash || '')}">Review</button>` : ''}` }] });
    K.grid($('#rfCr'), { id: 'ref-cr', url: url('creators'), search: 'Search applicant', sort: 'date', empty: 'No creator applications.',
      filters: [{ key: 'status', label: 'Status', options: [['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Rejected']] }],
      columns: [{ label: 'Applicant', sort: 'name', html: who }, { label: 'Rate', html: x => `${(x.rate_bps || 0) / 100}%` }, { label: 'Status', sort: 'status', html: x => pill(x.status) }, { label: 'Note', html: x => E(x.review_note || '') }, { label: 'Actions', cls: 'r', html: x => `<button class="button small ops-ok" data-cr="${x.owner_id}" data-rate="${x.rate_bps || r.rateBps || 0}">Review</button>` }] });
    K.grid($('#rfAff'), { id: 'ref-aff', url: url('affiliates'), search: 'Search name, email or referral code', sort: 'joined', empty: 'No affiliates yet.',
      columns: [{ label: 'Name', sort: 'name', html: a => `<b>${E(a.name)}</b>` }, { key: 'email', label: 'Email', sort: 'email' }, { label: 'Rate', html: a => `${(a.rateBps || 0) / 100}%` }, { label: 'Available', html: a => short(a.availablePaise) }, { label: 'In review', html: a => short((a.pendingPaise || 0) + (a.reservedPaise || 0)) }, { label: 'Total paid', html: a => short(a.paidPaise) }] });
    $('#rfForm').onsubmit = save; h.onclick = click;
  }
  const flags = j => { try { const a = JSON.parse(j || '[]'); return (Array.isArray(a) ? a : Object.keys(a)).join(', ') || 'none'; } catch (e) { return 'none'; } };
  function wdActions(w) {
    if (['paid', 'rejected'].includes(w.status)) return `<button class="button small secondary" data-wd-view="${w.id}">Destination</button>`;
    const b = [`<button class="button small secondary" data-wd-view="${w.id}">Destination</button>`];
    if (w.status === 'requested' && !w.approved_at) b.push(`<button class="button small ops-ok" data-wd-review="${w.id}">Review</button>`);
    else { b.push(`<button class="button small ops-ok" data-wd-manual="${w.id}">Mark paid…</button>`); }
    return b.join(' ');
  }
  async function save(e) {
    e.preventDefault(); const f = e.target.elements, st = $('#rfSt'); st.textContent = 'Saving…';
    const n = k => Number(f[k].value), body = { enabled: f.enabled.checked, requirePan: f.requirePan.checked, proOnly: f.proOnly.checked, holdDays: n('holdDays'), rateBps: Math.round(n('rateBps') * 100), tierRateBps: Math.round(n('tierRateBps') * 100), tierThreshold: n('tierThreshold'), minimumPaise: Math.round(n('minimumPaise') * 100), payoutHours: n('payoutHours'), minQualifyingPaise: Math.round(n('minQualifying') * 100), downloadThreshold: n('downloadThreshold'), premiumDays: n('premiumDays'), mode: f.mode.value, friendDiscountPaise: Math.round(n('friendDiscount') * 100), rewardPaise: Math.round(n('reward') * 100), qualifyDays: n('qualifyDays'), walletDays: n('walletDays'), monthlyCapPaise: Math.round(n('monthlyCap') * 100) };
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
      else if (ds.wdManual) form('Update withdrawal', `<label>Status<select name="status"><option value="processing">Processing</option><option value="paid">Paid</option><option value="rejected">Rejected</option></select></label><label>Bank reference<input name="reference" maxlength="120"></label><label>Note<textarea name="note" rows="2"></textarea></label>`, 'Save', f => post(`referrals/withdrawals/${ds.wdManual}`, { status: f.status.value, reference: f.reference.value, note: f.note.value }));
      else if (ds.pf) form('Review payout identity', `<label>Decision<select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select></label>${checks()}`, 'Save decision', f => post(`referrals/profiles/${ds.pf}`, { destinationHash: ds.hash || null, status: f.status.value, note: f.note.value, identityChecked: f.identityChecked.checked, destinationChecked: f.destinationChecked.checked, riskChecked: f.riskChecked.checked, taxChecked: f.taxChecked.checked }));
      else if (ds.cr) form('Review creator application', `<label>Decision<select name="status"><option value="approved">Approve</option><option value="rejected">Reject</option></select></label><label>Commission (%)<input name="rate" type="number" min="0" max="50" step="0.5" value="${Number(ds.rate) / 100}"></label><label>Note (min 10 characters)<textarea name="note" rows="3" required minlength="10"></textarea></label>`, 'Save decision', f => post(`referrals/creators/${ds.cr}`, { status: f.status.value, rateBps: Math.round(Number(f.rate.value) * 100), note: f.note.value }));
    } catch (x) { toast(err(x), true); }
  }
  K.register('referral', referral, ['🎁', 'Referral Ops', 'Every referral rule, affiliate, creator application, payout identity and withdrawal in one place.']);
})();
