/* Owner console: Users, Orders and Error Log. Uses the shared kit from admin-ops.js. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api, short, parse, dmy, pill, modal, toast, ensureRecent } = K;
  const dtm = d => { const x = parse(d); return isNaN(x) ? '' : x.toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  const err = e => (e && e.message) || 'Something went wrong.';
  let planCache = null;
  const plans = async () => planCache || (planCache = (await api('/api/admin/plans')).plans || {});
  // Plan keys sold before the 3.16 packages still sit on old purchases (Settings::planAlias).
  const OLD = { 'fresher-basic': 'job-hunt', 'fresher-standard': 'job-hunt', 'pro-basic': 'job-hunt', 'pro-standard': 'job-hunt', 'fresher-pro': 'pro', 'pro-pro': 'pro' };
  const planLabel = (pl, key) => (pl[key] && pl[key].name) || (pl[OLD[key]] && pl[OLD[key]].name) || key || '';

  /* ======================= USERS ======================= */
  const U = { q: undefined, grid: null };
  async function people() {
    const h = $('#admin-people'); if (!h) return;
    h.innerHTML = `<div class="pg-head"><h2>Users</h2><p>Search accounts, mark customers as paid with subscription details, edit or remove them. Customers get a thank-you email instantly.</p></div>
      <div class="ops-kpis" id="pkpi"></div><section class="panel" id="pgrid"></section>`;
    const pl = U.pl = await plans().catch(() => ({}));
    const q = U.q; U.q = undefined;
    U.grid = K.grid($('#pgrid'), {
      id: 'users', url: '/api/admin/users', rowsKey: 'users', search: 'Search by name or email', q, sort: 'joined',
      dates: 'Joined', filters: [{ key: 'f', label: 'Show', options: [['normal', 'Normal users'], ['paid', 'Paid (active)'], ['expired', 'Expired'], ['blocked', 'Blocked'], ['owner', 'Owner']] }],
      row: u => `data-uid="${E(u.owner_id)}"`,
      columns: [
        { label: 'User', sort: 'name', html: u => `<b>${E(u.name || '—')}</b><br><small>${E(u.email)}</small>` },
        { label: 'Subscription', html: u => u.paid ? `<span class="pill st-paid">${E(planLabel(pl, u.last_plan))}</span><br><small>until ${dmy(parse(u.accessUntil))}</small>` : u.ever_paid ? `<span class="pill st-failed">Expired</span><br><small>was ${E(planLabel(pl, u.last_plan))}</small>` : '<span class="pill st-free">Normal user</span>' },
        { label: 'Resumes', html: u => `${u.resumes ?? 0}<small> · ${u.applications ?? 0} apps</small>` },
        { label: 'Joined', sort: 'joined', html: u => dmy(parse(u.created_at)) },
        { label: 'Status', html: u => u.isAdmin ? '<span class="pill st-queued">Owner</span>' : u.blocked ? '<span class="pill st-failed">Blocked</span>' : '<span class="pill st-paid">Active</span>' },
        { label: 'Actions', cls: 'r', html: u => u.isAdmin ? '' : `<button class="button small secondary" data-act="edit" title="Edit details">✏️ Edit</button> <button class="button small ${u.paid ? 'secondary' : 'primary'}" data-act="sub" title="Subscription">${u.paid ? '💳 Manage' : '💳 Mark paid'}</button> <button class="button small secondary" data-act="block" title="${u.blocked ? 'Unblock' : 'Block'}">${u.blocked ? '✔ Unblock' : '🚫 Block'}</button> <button class="button small ops-no" data-act="delete" title="Delete account">🗑</button>` }
      ],
      empty: 'No users yet.',
      onData: d => { $('#pkpi').innerHTML = [['Total users', d.totalUsers], ['Paid (active)', d.paidUsers], ['Normal users', d.totalUsers - d.paidUsers], ['Blocked', d.blockedUsers ?? 0]].map(([l, v]) => `<div class="kpi"><span>${l}</span><b>${Number(v || 0).toLocaleString('en-IN')}</b></div>`).join(''); },
      onClick: (e, g) => {
        const b = e.target.closest('[data-act]'); if (!b || !g.data) return;
        const u = (g.data.users || []).find(x => String(x.owner_id) === b.closest('tr').dataset.uid); if (!u) return;
        ({ edit: editUser, sub: subscription, block: blockUser, delete: deleteUser })[b.dataset.act](u);
      }
    });
  }
  const refresh = () => (U.grid && document.contains(U.grid.host) ? U.grid.reload() : people());
  function editUser(u) {
    const m = modal('Edit user', `<form class="ops-form"><label>Full name<input name="name" required maxlength="120" value="${E(u.name)}"></label><label>Email<input name="email" type="email" required value="${E(u.email)}"></label><label>Phone<input name="phone" maxlength="40" value="${E(u.phone || '')}"></label><label>City<input name="city" maxlength="120" value="${E(u.city || '')}"></label><label>Profession / target role<input name="profession" maxlength="120" value="${E(u.profession || '')}"></label><label>Level<select name="experienceLevel"><option value="fresher" ${u.experience_level === 'fresher' ? 'selected' : ''}>Fresher</option><option value="experienced" ${u.experience_level === 'experienced' ? 'selected' : ''}>Experienced</option></select></label><div class="wide"><button class="button primary">Save changes</button> <span role="status" class="ops-st"></span></div></form>`);
    m.body.querySelector('form').onsubmit = async ev => {
      ev.preventDefault(); const st = m.body.querySelector('.ops-st'); st.textContent = 'Saving…';
      try { if (!(await ensureRecent())) { st.textContent = 'Verification cancelled.'; return; } const v = Object.fromEntries(new FormData(ev.target)); await api('/api/admin/users/update', { method: 'POST', body: { userId: Number(u.owner_id), ...v } }); toast('User updated'); m.close(); refresh(); } catch (x) { st.textContent = err(x); }
    };
  }
  async function subscription(u) {
    const pl = await plans();
    const keys = Object.keys(pl).filter(k => k !== 'free' && pl[k].sale), grp = t => keys.filter(k => pl[k].type === t).sort((a, b) => pl[a].price - pl[b].price);
    const opt = k => `<option value="${k}">${E(pl[k].name)} — ₹${pl[k].price}${pl[k].accessDays ? ' · ' + pl[k].accessDays + ' days' : ''}</option>`;
    const m = modal(u.paid ? 'Manage subscription' : 'Mark as paid', `<p class="ops-note" style="margin-top:0"><b>${E(u.name)}</b> · ${E(u.email)} — ${u.paid ? `currently <b>${E(planLabel(pl, u.last_plan))}</b> until ${dmy(parse(u.accessUntil))}` : 'a normal user with no subscription'}.</p>
      <form class="ops-form"><label class="wide">Plan<select name="plan" required><optgroup label="Packages">${grp('plan').map(opt).join('')}</optgroup>${grp('addon').length ? `<optgroup label="Add-on packs (need an active plan)">${grp('addon').map(opt).join('')}</optgroup>` : ''}</select></label>
      <label>Access days<input name="days" type="number" min="1" max="1095" placeholder="plan default"></label><label>Amount actually received (₹)<input name="amount" type="number" min="0" step="1" value="0" required title="Enter 0 for a complimentary plan. Only enter an amount you have really received."></label>
      <label class="wide">Note (kept in the audit log)<input name="note" required minlength="3" maxlength="300" placeholder="e.g. Paid by UPI on 14 Oct, ref 4821"></label>
      <label class="wide ops-check"><input type="checkbox" name="sendEmail" checked> Email the customer a thank-you now (plan, validity and download link)</label>
      <p class="ops-note wide" id="subPreview"></p>
      <div class="wide"><button class="button primary">${u.paid ? 'Add / extend plan' : 'Activate subscription'}</button> ${u.paid ? '<button type="button" class="button ops-no" id="revokeBtn">Revoke access</button>' : ''} <span role="status" class="ops-st"></span></div></form>`, { wide: false });
    const f = m.body.querySelector('form'), st = m.body.querySelector('.ops-st');
    const sum = () => { const p = pl[f.plan.value]; if (!p) return; const d = Number(f.days.value) || p.accessDays || 0; const until = new Date(Date.now() + d * 864e5); $('#subPreview', m.body).innerHTML = p.type === 'addon' ? `Adds <b>${p.savedResumes}</b> resumes and <b>${p.savedLetters}</b> cover letters until the base plan ends.` : `Gives <b>1 premium primary resume + ${p.savedResumes}</b> tailored resumes, <b>${p.savedLetters}</b> cover letters, clean PDF/Word downloads, valid about <b>${d} days</b> (until ${dmy(until)}).`; };
    f.plan.onchange = () => { const p = pl[f.plan.value]; f.days.placeholder = p.accessDays ? String(p.accessDays) : 'until base plan ends'; sum(); }; f.days.oninput = sum; f.plan.onchange();
    f.onsubmit = async ev => {
      ev.preventDefault(); st.textContent = 'Activating…';
      try {
        if (!(await ensureRecent())) { st.textContent = 'Verification cancelled.'; return; }
        const body = { userId: Number(u.owner_id), plan: f.plan.value, note: f.note.value, sendEmail: f.sendEmail.checked }; if (f.days.value) body.days = Number(f.days.value); if (f.amount.value !== '') body.amount = Number(f.amount.value);
        const r = await api('/api/admin/users/grant', { method: 'POST', body });
        toast(f.sendEmail.checked ? (r.mail && r.mail.sent ? '✓ Subscription active — thank-you email sent' : '✓ Subscription active — email queued (check User Mailer if it does not arrive)') : '✓ Subscription active'); m.close(); refresh();
      } catch (x) { st.textContent = err(x); }
    };
    const rv = $('#revokeBtn', m.body); if (rv) rv.onclick = async () => {
      const note = await askNote('Revoke access', 'Reason (kept in the audit log)'); if (!note) return;
      try { if (!(await ensureRecent())) return; const r = await api('/api/admin/users/revoke', { method: 'POST', body: { userId: Number(u.owner_id), note } }); toast(`Access revoked (${r.revoked} purchase${r.revoked === 1 ? '' : 's'})`); m.close(); refresh(); } catch (x) { st.textContent = err(x); }
    };
  }
  function askNote(title, label) {
    return new Promise(resolve => {
      let done = false; const fin = v => { if (!done) { done = true; resolve(v); } };
      const m = modal(title, `<form class="ops-form"><label class="wide">${E(label)}<input name="n" required minlength="5" maxlength="300" autocomplete="off"></label><div class="wide"><button class="button ops-no">Confirm</button> <button type="button" class="button secondary" data-no>Cancel</button></div></form>`, { onClose: () => fin(null) });
      m.body.querySelector('[data-no]').onclick = () => m.close();
      m.body.querySelector('form').onsubmit = e => { e.preventDefault(); fin(e.target.n.value.trim()); m.close(); };
    });
  }
  function blockUser(u) {
    const blocking = !u.blocked;
    const m = modal(blocking ? 'Block user' : 'Unblock user', `<p>${blocking ? 'Blocked users are signed out immediately and cannot sign in.' : 'The user will be able to sign in again.'}</p><form class="ops-form"><label class="wide">Reason (audit log)<input name="note" required minlength="5" maxlength="500" placeholder="Why are you doing this?"></label><div class="wide"><button class="button ${blocking ? 'ops-no' : 'primary'}">${blocking ? 'Block user' : 'Unblock user'}</button> <span class="ops-st" role="status"></span></div></form>`);
    m.body.querySelector('form').onsubmit = async ev => { ev.preventDefault(); const st = m.body.querySelector('.ops-st'); try { if (!(await ensureRecent())) return; await api('/api/admin/users/block', { method: 'POST', body: { userId: Number(u.owner_id), blocked: blocking, note: ev.target.note.value } }); toast(blocking ? 'User blocked' : 'User unblocked'); m.close(); refresh(); } catch (x) { st.textContent = err(x); } };
  }
  function deleteUser(u) {
    const m = modal('Delete account', `<p>This removes <b>${E(u.name)}</b>’s account from the server for good: sign-in, profile, resumes, letters, applications, ATS scans, support messages and screenshots. Payment and refund records stay for your accounts, filed under “Deleted customers” with no name or email. <b>This cannot be undone.</b></p><form class="ops-form"><label class="wide">Type <b>DELETE</b> to confirm<input name="c" autocomplete="off" required></label><div class="wide"><button class="button ops-no">Delete permanently</button> <span class="ops-st" role="status"></span></div></form>`);
    m.body.querySelector('form').onsubmit = async ev => { ev.preventDefault(); const st = m.body.querySelector('.ops-st'); if (ev.target.c.value !== 'DELETE') { st.textContent = 'Type DELETE exactly.'; return; } try { if (!(await ensureRecent())) return; await api('/api/admin/users/delete', { method: 'POST', body: { userId: Number(u.owner_id), confirm: 'DELETE' } }); toast('Account deleted'); m.close(); refresh(); } catch (x) { st.textContent = err(x); } };
  }

  /* ======================= ORDERS ======================= */
  const O = { q: '', status: 'all', plan: 'all', provider: 'all', from: '', to: '', min: '', max: '' };
  async function orders() {
    const h = $('#admin-orders'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div>';
    try {
      const pl = await plans(), planOpts = Object.keys(pl).filter(k => k !== 'free').map(k => [k, planLabel(pl, k)]), provOpts = [['razorpay', 'Razorpay'], ['phonepe', 'PhonePe'], ['manual', 'Recorded by owner'], ['referral_reward', 'Referral reward'], ['demo', 'Demo']];
      const sel = (id, label, opts, val) => `<label class="ops-f">${label}<select id="${id}"><option value="all">All</option>${opts.map(o => `<option value="${E(o[0])}" ${val === o[0] ? 'selected' : ''}>${E(o[1])}</option>`).join('')}</select></label>`;
      O.page = O.page || 1;
      h.innerHTML = `<div class="pg-head"><h2>All Orders</h2><p>Complete transaction history — every filter runs on the server, so nothing is cut off. Click any order for every detail.</p></div>
        <section class="panel panel-pad ops-filterbox" data-nokit style="overflow:visible"><label class="ops-f grow">Search<input id="oq" type="search" placeholder="Customer, email, reference or order no." value="${E(O.q)}"></label>
          ${sel('os', 'Status', [['paid', 'Paid'], ['refunded', 'Reversed by bank'], ['partially_refunded', 'Partly reversed'], ['disputed', 'Disputed'], ['pending', 'Pending'], ['created', 'Started, not paid']], O.status)}${sel('op', 'Plan', planOpts, O.plan)}${sel('ov', 'Payment source', provOpts, O.provider)}
          ${K.rangeHtml(O.from, O.to)}<label class="ops-f">Min ₹<input id="omin" type="number" min="0" value="${O.min}" style="width:90px"></label><label class="ops-f">Max ₹<input id="omax" type="number" min="0" value="${O.max}" style="width:90px"></label>
          <div class="ops-f"><span>&nbsp;</span><span><button class="button secondary small" id="oreset">Reset</button> <button class="button secondary small" id="ocustom">Custom report…</button> <span class="kit-menu"><button class="button primary small" data-k="exp" id="oexp">⬇ Download ▾</button><div class="kit-pop" hidden><button type="button" data-xf="csv">CSV (opens in Excel / Sheets)</button><button type="button" data-xf="xlsx">Excel (.xlsx)</button><button type="button" data-xf="pdf">PDF (to read or print)</button><button type="button" data-xf="json">JSON</button></div></span></span></div></section>
        <div class="ops-kpis" id="okpi"></div>
        <section class="panel" data-nokit><table class="ad-table ops-click"><thead><tr><th>Order ID</th><th>Customer</th><th>Plan</th><th>Amount</th><th>Source</th><th>Status</th><th>Date</th></tr></thead><tbody id="obody"></tbody></table></section><div class="ops-pager" id="opager"></div>`;
      const prm = () => { const p = { dataset: 'orders', q: O.q, status: O.status, plan: O.plan, provider: O.provider, from: O.from, to: O.to, min: O.min, max: O.max, columns: 'order,date,customer,email,plan,amount_inr,discount_inr,reversed_inr,net_inr,coupon,status,provider,reference,access_until', per: String(O.per || 20), page: String(O.page) }; Object.keys(p).forEach(k => { if (p[k] === '' || p[k] === 'all') delete p[k]; }); return p; };
      const draw = async () => {
        $('#obody').innerHTML = '<tr><td colspan="7">Loading…</td></tr>';
        const d = await api('/api/admin/report?' + new URLSearchParams({ ...prm(), format: 'json', columns: 'order,date,customer,email,plan,amount_inr,reversed_inr,status,provider' })), s = d.sums || {};
        $('#okpi').innerHTML = `<div class="kpi"><span>Orders found</span><b>${d.total.toLocaleString('en-IN')}</b></div><div class="kpi"><span>Billed</span><b>${short((s.amount_inr || 0) * 100)}</b></div><div class="kpi"><span>Reversed by bank</span><b>${short((s.reversed_inr || 0) * 100)}</b></div><div class="kpi"><span>Net</span><b>${short((s.net_inr || 0) * 100)}</b></div>`;
        $('#obody').innerHTML = d.rows.map(p => `<tr data-oid="${E(p.id || '')}" tabindex="0"><td><b>${E(p.order)}</b></td><td>${E(p.customer || 'Customer')}<br><small>${E(p.email || '')}</small></td><td>${E(planLabel(pl, p.plan))}</td><td><b>${short(p.amount_inr * 100)}</b>${p.reversed_inr ? `<br><small>reversed ${short(p.reversed_inr * 100)}</small>` : ''}</td><td>${E(p.provider === 'manual' ? 'Recorded by owner' : p.provider)}</td><td>${pill(p.status)}</td><td>${dtm(p.date)}</td></tr>`).join('') || '<tr><td colspan="7">No orders match these filters.</td></tr>';
        K.pager($('#opager'), d, (p, per) => { O.page = p; O.per = per; draw().catch(x => toast(err(x), true)); });
      };
      const bind = (id, k, ev = 'input') => { $('#' + id).addEventListener(ev, e => { O[k] = e.target.value; O.page = 1; clearTimeout(bind.t); bind.t = setTimeout(() => draw().catch(x => toast(err(x), true)), ev === 'input' ? 350 : 0); }); };
      bind('oq', 'q'); bind('os', 'status', 'change'); bind('op', 'plan', 'change'); bind('ov', 'provider', 'change');  bind('omin', 'min'); bind('omax', 'max');
      $('#admin-orders .ops-filterbox').addEventListener('change', e => { const rg = K.rangeChange(e, e.currentTarget); if (rg) { [O.from, O.to] = rg; O.page = 1; draw().catch(x => toast(err(x), true)); } });
      $('#oreset').onclick = () => { Object.assign(O, { q: '', status: 'all', plan: 'all', provider: 'all', from: '', to: '', min: '', max: '', page: 1 }); orders(); };
      $('#ocustom').onclick = () => K.openReport({ dataset: 'orders', q: O.q, status: O.status, plan: O.plan, provider: O.provider, from: O.from, to: O.to, min: O.min, max: O.max });
      h.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.k) { b.nextElementSibling.hidden = !b.nextElementSibling.hidden; }
        else if (b.dataset.xf) { b.closest('.kit-pop').hidden = true; toast('Preparing export…'); K.exportReport(prm(), b.dataset.xf).then(() => toast('Export ready'), x => toast(err(x), true)); }
      });
      const open = tr => tr && tr.dataset.oid && orderDetail(tr.dataset.oid);
      $('#obody').onclick = e => open(e.target.closest('tr[data-oid]')); $('#obody').onkeydown = e => { if (e.key === 'Enter') open(e.target.closest('tr[data-oid]')); };
      await draw();
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }
  async function orderDetail(id) {
    const m = modal('Order details', '<div class="ops-skel"></div>', { wide: true });
    try {
      const r = await api('/api/admin/orders/' + id), o = r.order, u = r.user || {}, pr = r.profile || {}, lim = r.limits || {};
      const row = (k, v) => v === undefined || v === null || v === '' ? '' : `<div><dt>${k}</dt><dd>${v}</dd></div>`;
      const active = /paid|partially/.test(o.status) && o.access_until && parse(o.access_until) > new Date();
      m.body.innerHTML = `<div class="ord-head"><div><h2>${E(r.orderNo)}</h2><p>${E(K.planName(o))} · ${dtm(o.created_at)}</p></div><div>${pill(o.status)}<b class="ord-amt">${short(o.amount_paise)}</b></div></div>
        <div class="ord-grid"><section><h4>Payment</h4><dl>${row('Net received', short(r.net))}${row('Refunded', o.refunded_paise ? short(o.refunded_paise) : '')}${row('Source', o.provider === 'manual' ? 'Recorded by owner' : o.provider === 'razorpay' || o.provider === 'phonepe' ? (o.provider === 'phonepe' ? 'PhonePe' : 'Razorpay') + (o.is_live ? ' (live)' : ' (test)') : E(o.provider))}${row('Payment reference', `<code>${E(o.payment_reference || '—')}</code>`)}${row('Provider order', `<code>${E(o.provider_order_id || '—')}</code>`)}${row('Coupon', o.coupon_code ? E(o.coupon_code) + ' (−' + short(o.discount_paise) + ')' : '')}${row('Paid at', o.purchased_at ? dtm(o.purchased_at) : '—')}${row('Access until', o.access_until ? dtm(o.access_until) + (active ? ' <span class="pill st-paid">active</span>' : ' <span class="pill st-free">ended</span>') : '—')}</dl></section>
        <section><h4>Customer</h4><dl>${row('Name', E(u.name))}${row('Email', E(u.email))}${row('Phone', E(pr.phone))}${row('City', E(pr.city))}${row('Profession', E(pr.profession))}${row('Level', E(pr.experience_level))}${row('Member since', u.created_at ? dmy(parse(u.created_at)) : '')}</dl></section>
        <section><h4>Plan snapshot at purchase</h4><dl>${row('Plan', E(lim.name || o.plan))}${row('Audience', E(lim.audience))}${row('Tailored resumes', lim.savedResumes)}${row('Cover letters', lim.savedLetters)}${row('ATS scans', lim.atsScans == null ? 'Unlimited' : lim.atsScans)}${row('Validity', lim.accessDays ? lim.accessDays + ' days' : '')}${row('Perks', (lim.perks || []).map(E).join(' · '))}</dl></section>
        <section><h4>Timeline</h4><ol class="ord-time">${(r.timeline || []).map(t => `<li><b>${E(String(t.event).replace(/_/g, ' '))}</b><small>${dtm(t.created_at)}</small></li>`).join('') || '<li><small>No events recorded.</small></li>'}</ol><h4 style="margin-top:14px">Emails sent for this customer</h4><ol class="ord-time">${(r.emails || []).map(t => `<li><b>${E(t.subject)}</b><small>${E(t.status)}${t.sent_at ? ' · ' + dtm(t.sent_at) : ''}</small></li>`).join('') || '<li><small>None.</small></li>'}</ol></section></div>
        <div class="ord-actions"><button class="button secondary" data-goto-user>View customer</button>${active ? '<button class="button ops-no" data-revoke>Revoke access</button>' : ''}<button class="button secondary" data-copy>Copy order summary</button></div>`;
      m.body.querySelector('[data-copy]').onclick = () => { const t = `${r.orderNo} · ${K.planName(o)} · ${short(o.amount_paise)} · ${o.status} · ${u.name} <${u.email}> · ref ${o.payment_reference || '-'}`; (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => toast('Copied'), () => toast(t)); };
      m.body.querySelector('[data-goto-user]').onclick = () => { m.close(); U.q = u.email || ''; const b = $('.side-nav [data-admin-view="people"]'); if (b) b.click(); else K.open('people'); };
      const rv = m.body.querySelector('[data-revoke]'); if (rv) rv.onclick = async () => { const note = await askNote('Revoke access', 'Reason (kept in the audit log)'); if (!note) return; try { if (!(await ensureRecent())) return; await api('/api/admin/users/revoke', { method: 'POST', body: { userId: Number(u.id), note } }); toast('Access revoked'); m.close(); orders(); } catch (x) { toast(err(x), true); } };
    } catch (e) { m.body.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  /* ======================= ERROR LOG ======================= */
  const L = { level: 'all', source: 'all', q: '', days: 30, from: K.rangeOf('30')[0], to: K.rangeOf('30')[1], open: false, sel: new Set(), timer: null, page: 1, per: 20, qt: null };
  const qs = () => { const q = new URLSearchParams({ days: 36500 }); if (L.from) q.set('from', L.from); if (L.to) q.set('to', L.to); if (L.level !== 'all') q.set('level', L.level); if (L.source !== 'all') q.set('source', L.source); if (L.q) q.set('q', L.q); if (L.open) q.set('state', 'open'); return q.toString(); };
  async function errors() {
    const h = $('#admin-errors'); if (!h) return;
    clearInterval(L.timer);
    if (!h.dataset.built) {
      h.dataset.built = '1';
      h.innerHTML = `<div class="pg-head"><h2>Error Log</h2><p>Every server error, browser error, failed email and daily health warning is collected here. Download it, work through it, mark items resolved.</p></div>
        <div class="ops-kpis" id="ekpi"></div>
        <section class="panel panel-pad ops-filterbox"><label class="ops-f grow">Search<input id="eq" type="search" placeholder="Search messages"></label><label class="ops-f">Level<select id="el"><option value="all">All</option><option value="error">Errors</option><option value="warning">Warnings</option><option value="info">Info</option></select></label><label class="ops-f">Source<select id="es"><option value="all">All</option><option value="server">Server</option><option value="browser">Browser</option><option value="mail">Email</option><option value="payment">Payments</option><option value="cron">Daily checks</option></select></label>${K.rangeHtml(L.from, L.to)}<label class="ops-f ops-check"><span>&nbsp;</span><span><input type="checkbox" id="eo"> Open only</span></label></section>
        <div class="ops-bar"><span id="edlbox">${K.dlHtml()}</span><button class="button secondary small" id="edj">⬇ JSON</button><span class="grow"></span><button class="button secondary small" id="eres">✓ Mark selected resolved</button><button class="button secondary small" id="eclr">Delete resolved</button><button class="button secondary small" id="eref">↻ Refresh</button></div>
        <div class="ops-term"><div class="ops-term-bar"><span class="live-dot"></span> <span id="eupd">loading…</span><label style="margin-left:auto"><input type="checkbox" id="eauto" checked> auto-refresh 30s</label></div><div id="elist" class="ops-elist"></div></div><div class="ops-pager" id="epg"></div>`;
      const set = (id, k, ev = 'input') => $('#' + id).addEventListener(ev, e => { L[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value; L.page = 1; clearTimeout(L.qt); L.qt = setTimeout(errors, ev === 'input' ? 350 : 0); });
      set('eq', 'q'); set('el', 'level', 'change'); set('es', 'source', 'change');
      $('#admin-errors .ops-filterbox').addEventListener('change', e => { const rg = K.rangeChange(e, e.currentTarget); if (rg) { [L.from, L.to] = rg; L.page = 1; errors(); } }); set('eo', 'open', 'change');
      $('#eref').onclick = () => errors();
      $('#eres').onclick = async () => { if (!L.sel.size) return toast('Select entries first', true); try { await api('/api/admin/errors/clear', { method: 'POST', body: { mode: 'resolve', ids: [...L.sel].filter(x => !String(x).startsWith('mail-')).map(Number) } }); L.sel.clear(); toast('Marked resolved'); errors(); } catch (e) { toast(err(e), true); } };
      $('#eclr').onclick = async () => { try { const r = await api('/api/admin/errors/clear', { method: 'POST', body: { mode: 'purge-resolved' } }); toast((r.affected ?? 0) + ' resolved entries deleted'); errors(); } catch (e) { toast(err(e), true); } };
      // Downloads come from the server with every entry that matches the filters, not just the page on screen.
      const dl = fmt => { const a = document.createElement('a'); a.href = '/api/admin/errors/download?' + qs() + (fmt === 'json' ? '&format=json' : ''); a.download = ''; document.body.append(a); a.click(); a.remove(); };
      $('#edj').onclick = () => dl('json');
      K.wireDownload($('#edlbox'), fmt => '/api/admin/errors?' + qs() + '&export=' + fmt);
      $('#eauto').onchange = e => { clearInterval(L.timer); if (e.target.checked) L.timer = setInterval(() => $('.admin-view.active')?.id === 'admin-errors' && errors(), 30000); };
      h.addEventListener('change', e => { const c = e.target.closest('[data-esel]'); if (c) { c.checked ? L.sel.add(c.dataset.esel) : L.sel.delete(c.dataset.esel); } });
    }
    if ($('#eauto') && $('#eauto').checked) L.timer = setInterval(() => $('.admin-view.active')?.id === 'admin-errors' && errors(), 30000);
    try {
      const d = await api('/api/admin/errors?' + qs() + `&page=${L.page}&per=${L.per}`); L.rows = d.errors || []; L.page = d.page || 1;
      K.pager($('#epg'), d, (p, per) => { L.page = p; L.per = per; errors(); });
      const s = d.summary || {};
      $('#ekpi').innerHTML = `<div class="kpi"><span>Errors</span><b style="color:#ef4444">${s.error || 0}</b></div><div class="kpi"><span>Warnings</span><b style="color:#f59e0b">${s.warning || 0}</b></div><div class="kpi"><span>Entries in period</span><b>${s.total || 0}</b></div><div class="kpi"><span>Browser errors</span><b>${s.browser || 0}</b></div><div class="kpi"><span>Failed emails</span><b>${s.mail || 0}</b></div>`;
      $('#eupd').textContent = 'live · updated ' + new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      $('#elist').innerHTML = L.rows.map(r => `<div class="ops-erow ${r.resolved_at ? 'done' : ''}"><input type="checkbox" data-esel="${E(r.id)}" ${L.sel.has(String(r.id)) ? 'checked' : ''} aria-label="Select"><span class="lv ${E(r.level)}">${E(r.level.toUpperCase())}</span><span class="src">${E(r.source)}</span><div class="msg"><b>${E(r.message)}</b><small>${E(r.location || '')}${r.url ? ' · ' + E(r.url) : ''}</small></div><span class="cnt" title="occurrences">×${r.occurrences}</span><span class="when">${dtm(r.last_seen)}</span></div>`).join('') || '<div class="ops-erow"><span class="msg"><b>No entries for these filters 🎉</b><small>New errors appear here automatically.</small></span></div>';
    } catch (e) { $('#elist').innerHTML = `<div class="ops-erow"><span class="msg" role="alert"><b>${E(err(e))}</b></span></div>`; }
  }

  K.register('people', people, ['👥', 'Users', 'Search accounts, manage subscriptions and keep your customers informed.']);
  K.register('orders', orders, ['🛒', 'All Orders', 'Complete transaction history']);
  K.register('errors', errors, ['🔴', 'Error Log', 'Every error and warning collected for you to download and work through.']);
})();
