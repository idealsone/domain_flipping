/* Owner console: B2B Partners (3.54.0, PartnerHub). Colleges, placement cells, coaching institutes, companies and
   creators bring sign-ups through their own campaign links. Each sign-up is locked to the partner at sign-up. A separate
   commission ledger (30% of plans, 40% of add-ons, nothing on the ₹99 Quick Download) stays pending through the 7-day
   refund window, then becomes available; the owner marks it paid with a payout reference. Partners see numbers only in
   their portal, never a student's name or documents. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent, dmy, parse } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const post = (path, body) => api(path, { method: 'POST', body });
  const rs = p => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN');
  const day = d => d ? dmy(parse(d)) : '—';
  let KINDS = {}, tab = 'partners';

  async function load() {
    const h = $('#admin-partners'); if (!h) return;
    h.innerHTML = `<div class="pg-head"><h2>B2B Partners</h2><p>Colleges, placement cells, coaching institutes, companies and creators who bring students and job seekers through their own links. Sign-ups are locked to the partner when the account is made; commission is kept apart from customer referrals.</p></div>
      <div class="ops-kpis" id="ptKpi"></div>
      <div class="ops-tabs" role="tablist" style="margin:12px 0">${[['partners', 'Partners'], ['commissions', 'Commission ledger'], ['applications', 'Applications']].map(([k, l]) => `<button type="button" role="tab" class="button small ${tab === k ? 'primary' : 'secondary'}" data-pt-tab="${k}" aria-selected="${tab === k}">${l}</button>`).join(' ')}
      <button type="button" class="button small primary" id="ptNew" style="float:right">+ Add partner</button></div>
      <section class="panel" id="ptGrid"></section>`;
    h.querySelectorAll('[data-pt-tab]').forEach(b => b.onclick = () => { tab = b.dataset.ptTab; load(); });
    $('#ptNew').onclick = () => edit(null);
    ({ partners, commissions, applications })[tab]();
  }

  function kpis(c) {
    $('#ptKpi').innerHTML = [['Partners', c.partners], ['Active', c.active], ['New applications', c.applications], ['Commission pending', rs(c.pendingPaise)], ['Ready to pay', rs(c.availablePaise)]]
      .map(([l, v]) => `<div class="kpi"><span>${l}</span><b>${typeof v === 'number' ? v.toLocaleString('en-IN') : E(v)}</b></div>`).join('');
  }

  function partners() {
    const g = K.grid($('#ptGrid'), {
      id: 'partners', url: '/api/admin/partners', rowsKey: 'partners', search: 'Search by name, email or city', sort: 'added', dates: 'Added',
      filters: [{ key: 'kind', label: 'Kind', options: () => Object.entries(KINDS) }, { key: 'status', label: 'Status', options: [['active', 'Active'], ['paused', 'Paused'], ['ended', 'Ended']] }],
      row: p => `data-pid="${E(p.id)}" tabindex="0" style="cursor:pointer"`,
      columns: [
        { label: 'Partner', sort: 'name', html: p => `<b>${E(p.name)}</b><br><small>${E(KINDS[p.kind] || p.kind)}${p.city ? ' · ' + E(p.city) : ''}</small>` },
        { label: 'Status', html: p => `<span class="pill ${p.status === 'active' ? 'st-paid' : p.status === 'paused' ? 'st-queued' : 'st-failed'}">${E(p.status)}</span>` },
        { label: 'Commission', html: p => `${p.plan_bps / 100}% plans<br><small>${p.addon_bps / 100}% add-ons</small>` },
        { label: 'Agreement', html: p => `${day(p.agreement_from)} – ${p.agreement_to ? day(p.agreement_to) : 'open'}` },
        { label: 'Sign-ups', cls: 'r', html: p => String(p.signups || 0) },
        { label: 'Owed', cls: 'r', html: p => rs(p.owedPaise) }
      ],
      empty: 'No partners yet. Add one, or approve an application.',
      onData: d => { KINDS = d.kinds || KINDS; kpis(d.counts || {}); }
    });
    $('#ptGrid').addEventListener('click', e => { const tr = e.target.closest('tr[data-pid]'); if (tr) open(Number(tr.dataset.pid)); });
    return g;
  }

  async function open(id) {
    let d; try { d = await api('/api/admin/partners/' + id); } catch (e) { toast(err(e), true); return; }
    const p = d.partner; KINDS = d.kinds || KINDS;
    const months = d.months.length ? `<table class="ops-table"><thead><tr><th>Month</th><th>Sign-ups</th><th>Buyers</th><th>Revenue</th></tr></thead><tbody>${d.months.map(m => `<tr><td>${E(m.month)}</td><td>${m.signups}</td><td>${m.buyers}</td><td>${rs(m.revenuePaise)}</td></tr>`).join('')}</tbody></table>` : '<p class="muted">No sign-ups yet.</p>';
    const links = `<table class="ops-table"><thead><tr><th>Link</th><th>Campaign</th><th>Clicks</th><th>Sign-ups</th><th></th></tr></thead><tbody>${d.links.map(l => `<tr><td><code>${E(l.url)}</code>${l.label ? '<br><small>' + E(l.label) + '</small>' : ''}</td><td>${E(l.campaign || '—')}</td><td>${l.clicks}</td><td>${l.signups}</td><td><button type="button" class="button small secondary" data-link="${l.id}" data-on="${l.active ? 0 : 1}">${l.active ? 'Pause' : 'Turn on'}</button></td></tr>`).join('') || '<tr><td colspan="5">No links yet.</td></tr>'}</tbody></table>`;
    const members = d.members.map(m => `<li>${E(m.name)} &lt;${E(m.email)}&gt; · ${E(m.role)} <button type="button" class="button small ops-no" data-rm="${E(m.email)}">Remove</button></li>`).join('') || '<li class="muted">Nobody can open the partner portal yet.</li>';
    const m = modal(p.name, `<div class="ops-kpis"><div class="kpi"><span>Sign-ups</span><b>${d.signups}</b></div><div class="kpi"><span>Buyers</span><b>${d.buyers}</b></div><div class="kpi"><span>Revenue</span><b>${rs(d.revenuePaise)}</b></div><div class="kpi"><span>Pending</span><b>${rs(d.commission.pendingPaise)}</b></div><div class="kpi"><span>Ready to pay</span><b>${rs(d.commission.availablePaise)}</b></div><div class="kpi"><span>Paid</span><b>${rs(d.commission.paidPaise)}</b></div></div>
      <p><button type="button" class="button small secondary" id="ptEdit">Edit partner and agreement</button></p>
      <h4>Campaign links</h4>${links}
      <form class="ops-form" id="ptLink"><label>Link name (optional)<input name="code" maxlength="40" placeholder="e.g. abc-college-2026"></label><label>Campaign<input name="campaign" maxlength="80" placeholder="e.g. placement-week"></label><label>Note<input name="label" maxlength="120"></label><div class="wide"><button class="button primary small">Add link</button> <span class="ops-st" role="status"></span></div></form>
      <h4>Cohorts by month</h4>${months}
      <h4>Portal members</h4><p class="muted" style="margin-top:0">People at the partner who can open the partner portal (numbers only, their own organisation). They need a CVTara account first.</p><ul>${members}</ul>
      <form class="ops-form" id="ptMem"><label>Email<input name="email" type="email" required maxlength="190"></label><label>Role<select name="role"><option value="viewer">Viewer</option><option value="manager">Manager</option></select></label><div class="wide"><button class="button primary small">Add member</button> <span class="ops-st" role="status"></span></div></form>`, { wide: true });
    const B = m.body;
    $('#ptEdit', B).onclick = () => { m.close(); edit(p); };
    B.addEventListener('click', async e => {
      const l = e.target.closest('[data-link]'); const rm = e.target.closest('[data-rm]');
      try {
        if (l) { await post('/api/admin/partners/links/' + l.dataset.link, { active: l.dataset.on === '1' }); m.close(); open(id); }
        if (rm) { if (!(await ensureRecent())) return; await post(`/api/admin/partners/${id}/members`, { email: rm.dataset.rm, role: 'viewer', remove: true }); m.close(); open(id); }
      } catch (x) { toast(err(x), true); }
    });
    $('#ptLink', B).onsubmit = async ev => { ev.preventDefault(); const st = $('.ops-st', ev.target); try { const r = await post(`/api/admin/partners/${id}/links`, Object.fromEntries(new FormData(ev.target))); toast('Link /p/' + r.code + ' added'); m.close(); open(id); } catch (x) { st.textContent = err(x); } };
    $('#ptMem', B).onsubmit = async ev => { ev.preventDefault(); const st = $('.ops-st', ev.target); try { if (!(await ensureRecent())) return; await post(`/api/admin/partners/${id}/members`, Object.fromEntries(new FormData(ev.target))); toast('Member added'); m.close(); open(id); } catch (x) { st.textContent = err(x); } };
  }

  function edit(p) {
    p = p || { kind: 'college', status: 'active', plan_bps: 3000, addon_bps: 4000 };
    const opt = (list, v) => list.map(([k, l]) => `<option value="${E(k)}" ${k === v ? 'selected' : ''}>${E(l)}</option>`).join('');
    const kinds = Object.keys(KINDS).length ? Object.entries(KINDS) : [['college', 'College'], ['tpo', 'Placement cell (TPO)'], ['coaching', 'Coaching institute'], ['corporate', 'Company'], ['influencer', 'Influencer / creator'], ['other', 'Other partner']];
    const m = modal(p.id ? 'Edit partner' : 'Add partner', `<form class="ops-form" id="ptForm">
      <label class="wide">Organisation name<input name="name" required minlength="2" maxlength="160" value="${E(p.name || '')}"></label>
      <label>Kind<select name="kind">${opt(kinds, p.kind)}</select></label><label>Status<select name="status">${opt([['active', 'Active'], ['paused', 'Paused'], ['ended', 'Ended']], p.status)}</select></label>
      <label>Contact name<input name="contactName" maxlength="120" value="${E(p.contact_name || '')}"></label><label>Contact email<input name="contactEmail" type="email" maxlength="190" value="${E(p.contact_email || '')}"></label>
      <label>Contact phone<input name="contactPhone" maxlength="20" value="${E(p.contact_phone || '')}"></label><label>City<input name="city" maxlength="80" value="${E(p.city || '')}"></label>
      <label>Commission on plans (%)<input name="planPercent" type="number" min="0" max="60" step="0.5" value="${p.plan_bps / 100}"></label><label>Commission on add-ons (%)<input name="addonPercent" type="number" min="0" max="60" step="0.5" value="${p.addon_bps / 100}"></label>
      <label>Agreement reference<input name="agreementRef" maxlength="120" value="${E(p.agreement_ref || '')}"></label><span></span>
      <label>Agreement from<input name="agreementFrom" type="date" value="${E((p.agreement_from || '').slice(0, 10))}"></label><label>Agreement until (empty = open)<input name="agreementTo" type="date" value="${E((p.agreement_to || '').slice(0, 10))}"></label>
      <label class="wide">Notes<textarea name="notes" rows="3" maxlength="2000">${E(p.notes || '')}</textarea></label>
      <p class="wide muted" style="margin:0">The ₹99 Quick Download never earns commission. Commission waits 7 days (the refund window) before it can be paid, and a refund or chargeback cancels it.</p>
      <div class="wide"><button class="button primary">Save</button> <span class="ops-st" role="status"></span></div></form>`, { wide: true });
    $('#ptForm', m.body).onsubmit = async ev => {
      ev.preventDefault(); const st = $('.ops-st', ev.target);
      const v = Object.fromEntries([...new FormData(ev.target)].filter(([, x]) => x !== ''));
      if (p.id) v.id = p.id;
      try { if (!(await ensureRecent())) return; const r = await post('/api/admin/partners/save', v); toast('Partner saved'); m.close(); tab = 'partners'; await load(); open(r.id); } catch (x) { st.textContent = err(x); }
    };
  }

  function commissions() {
    const sel = new Set();
    const g = K.grid($('#ptGrid'), {
      id: 'partner-commissions', url: '/api/admin/partners/commissions', rowsKey: 'commissions', search: 'Search partner or payout reference', sort: 'date', dates: 'Order date',
      filters: [{ key: 'status', label: 'Status', options: [['pending', 'Pending (refund window)'], ['available', 'Ready to pay'], ['paid', 'Paid'], ['void', 'Cancelled']] }],
      columns: [
        { label: '', html: c => c.status === 'available' ? `<input type="checkbox" data-csel="${E(c.id)}" aria-label="Select">` : '' },
        { label: 'Partner', html: c => E(c.partner) },
        { label: 'Order', html: c => `${E(c.plan)}<br><small>${E(c.kind === 'addon' ? 'Add-on' : 'Plan')} · ${rs(c.base_paise)}</small>` },
        { label: 'Commission', sort: 'amount', cls: 'r', html: c => `<b>${rs(c.amount_paise)}</b><br><small>${c.rate_bps / 100}%</small>` },
        { label: 'Status', html: c => `${E({ pending: 'Pending', available: 'Ready to pay', paid: 'Paid', void: 'Cancelled' }[c.status] || c.status)}<br><small>${c.status === 'pending' ? 'from ' + day(c.available_at) : c.status === 'paid' ? day(c.paid_at) + ' · ' + E(c.payout_ref || '') : E(c.void_reason || '')}</small>` },
        { label: 'Date', sort: 'date', html: c => day(c.created_at) }
      ],
      empty: 'No partner commission yet.',
      onData: () => sel.clear()
    });
    const bar = document.createElement('p'); bar.innerHTML = '<button type="button" class="button small primary" id="ptPay">Mark selected as paid</button> <small class="muted">Owner only. Pay the partner first (bank transfer), then record the reference here.</small>';
    $('#ptGrid').prepend(bar);
    $('#ptGrid').addEventListener('change', e => { const c = e.target.closest('[data-csel]'); if (c) c.checked ? sel.add(Number(c.dataset.csel)) : sel.delete(Number(c.dataset.csel)); });
    $('#ptPay').onclick = async () => {
      if (!sel.size) return toast('Select commissions that are ready to pay', true);
      const ref = prompt('Payout reference (bank UTR or note)'); if (!ref || ref.trim().length < 3) return;
      try { if (!(await ensureRecent())) return; const r = await post('/api/admin/partners/commissions/pay', { ids: [...sel], reference: ref.trim() }); toast(`${r.paid} marked paid`); g && g.reload(); } catch (x) { toast(err(x), true); }
    };
    api('/api/admin/partners?per=1').then(d => { KINDS = d.kinds || KINDS; kpis(d.counts || {}); }).catch(() => {});
  }

  function applications() {
    const g = K.grid($('#ptGrid'), {
      id: 'partner-applications', url: '/api/admin/partners/applications', rowsKey: 'applications', search: 'Search organisation, email or city', sort: 'date', dates: 'Received',
      filters: [{ key: 'status', label: 'Status', options: [['new', 'New'], ['approved', 'Approved'], ['declined', 'Declined']] }],
      columns: [
        { label: 'Organisation', html: a => `<b>${E(a.org_name)}</b><br><small>${E(KINDS[a.kind] || a.kind)}${a.city ? ' · ' + E(a.city) : ''}${a.size ? ' · ' + E(a.size) : ''}</small>` },
        { label: 'Contact', html: a => `${E(a.contact_name)}<br><small>${E(a.email)}${a.phone ? ' · ' + E(a.phone) : ''}</small>` },
        { label: 'Message', html: a => `<small>${E((a.message || '').slice(0, 220))}</small>` },
        { label: 'Received', sort: 'date', html: a => day(a.created_at) },
        { label: '', cls: 'r', html: a => a.status === 'new' ? `<button type="button" class="button small primary" data-dec="approved" data-aid="${a.id}">Approve</button> <button type="button" class="button small ops-no" data-dec="declined" data-aid="${a.id}">Decline</button>` : E(a.status) }
      ],
      empty: 'No applications yet. The form is on the For colleges and partners page.'
    });
    $('#ptGrid').addEventListener('click', async e => {
      const b = e.target.closest('[data-dec]'); if (!b) return;
      const note = prompt(b.dataset.dec === 'approved' ? 'Note (optional). The partner is added as Paused until you add the agreement and a link.' : 'Reason (optional, kept for your records)') ?? null;
      if (note === null) return;
      try { const r = await post('/api/admin/partners/applications/' + b.dataset.aid, { decision: b.dataset.dec, note }); toast(b.dataset.dec === 'approved' ? 'Partner added (paused)' : 'Declined'); g && g.reload(); if (r.partnerId) open(r.partnerId); } catch (x) { toast(err(x), true); }
    });
    api('/api/admin/partners?per=1').then(d => { KINDS = d.kinds || KINDS; kpis(d.counts || {}); }).catch(() => {});
  }

  K.register('partners', load, ['🤝', 'B2B Partners', 'Colleges, coaching and company partners: links, sign-ups, commission ledger and applications.']);
})();
