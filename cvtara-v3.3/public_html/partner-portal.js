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

  async function post(path, body) {
    const tok = window.CVTaraCSRF ? await window.CVTaraCSRF() : '';
    const r = await fetch('/api/' + path, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': tok }, body: JSON.stringify(body || {}) });
    let j = {}; try { j = await r.json(); } catch { /* not JSON */ }
    if (!r.ok || j.ok === false) throw Error(j.error || (j.errors && Object.values(j.errors)[0]?.[0]) || j.message || 'That did not work. Please try again.');
    return j;
  }
  const dl = (name, rows) => { const csv = 'name,email,personal link,note\n' + rows.map(r => [r.name, r.email, r.link, r.note].map(x => '"' + String(x || '').replace(/"/g, '""').replace(/^[=+\-@]/, "'$&") + '"').join(',')).join('\n'); const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = name; document.body.append(a); a.click(); setTimeout(() => a.remove(), 300); };

  function draw() {
    const h = document.getElementById('view-partner'); if (!h || !data) return;
    const p = data.partner, c = data.commission, mgr = p.role === 'manager';
    const copy = u => `<button type="button" class="button secondary small" data-pp-copy="${E(u)}">Copy</button>`;
    const waiting = (data.agreements || []).find(a => a.status === 'sent');
    const accepted = (data.agreements || []).find(a => a.status === 'accepted');
    const seats = s => s ? `${s.verified}${s.allocated !== null ? ' of ' + s.allocated : ''} seats used · ${s.registered} signed up · ${s.invited} invited · ${s.paid} paying${s.target !== null ? ' (target ' + s.target + ')' : ''}${s.endsOn ? ' · until ' + day(s.endsOn) : ''}${s.open ? '' : ' · <b>not taking sign-ups</b>'}` : '';
    h.innerHTML = `<div class="ip-card"><h2 style="margin:0 0 4px">${E(p.name)}</h2><p class="ip-muted">${E(p.kind)} · ${p.status === 'active' ? 'Active partner' : 'Partnership ' + E(p.status)}${p.agreementFrom ? ' · from ' + day(p.agreementFrom) : ''}${p.agreementTo ? ' to ' + day(p.agreementTo) : ''}</p>
      <p class="ip-muted">Commission: ${p.planPercent}% of Job Hunt, Pro and other plans, ${p.addonPercent}% of add-ons. The ₹${p.excludedUpToInr} Quick Download earns none. Each amount waits ${p.holdDays} days (the refund window) before it is ready to pay, and a refund cancels it. You see totals only, never a student's resume or details.</p></div>
      ${waiting ? `<div class="ip-card" style="border-color:var(--brand,#2563eb)"><h3>Agreement version ${waiting.version} to review</h3><div style="white-space:pre-wrap;max-height:320px;overflow:auto;border:1px solid var(--line,#e2e8f0);border-radius:8px;padding:12px;font-size:14px">${E(waiting.body)}</div>
        ${mgr ? `<form id="ppAccept" class="ip-form" style="grid-template-columns:1fr"><label><span><input type="checkbox" name="agree" value="1" required> I have read this agreement and accept it on behalf of ${E(p.name)}.</span></label><label>Your full name<input name="name" required minlength="3" maxlength="120"></label><button class="button mint" type="submit">Accept agreement</button><p class="ip-muted" data-pp-st role="status"></p></form>` : '<p class="ip-muted">Your organisation\'s manager can accept it.</p>'}</div>` : ''}
      <div class="ip-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px">${[['Sign-ups', data.signups], ['Buyers', data.buyers], ['Revenue', rs(data.revenuePaise)], ['Pending', rs(c.pendingPaise)], ['Ready to pay', rs(c.availablePaise)], ['Paid to you', rs(c.paidPaise)]].map(([l, v]) => `<div class="ip-card" style="margin:0"><span class="ip-muted">${l}</span><br><b style="font-size:20px">${E(v)}</b></div>`).join('')}</div>
      <div class="ip-card"><h3>Payouts</h3><p class="ip-muted">You can ask for a payout once ₹${(data.minPayoutPaise / 100).toLocaleString('en-IN')} is ready to pay. CVTara pays by bank transfer against your invoice.</p>
        ${mgr ? `<button type="button" class="button secondary small" data-pp-payout ${c.availablePaise >= data.minPayoutPaise ? '' : 'disabled'}>Request payout of ${rs(c.availablePaise)}</button> <span class="ip-muted" data-pp-st2 role="status"></span>` : ''}
        ${(data.payouts || []).map(x => `<p class="ip-muted">${day(x.created_at)}: ${rs(x.amount_paise)} · ${E({ requested: 'with CVTara', paid: 'paid', rejected: 'declined' }[x.status] || x.status)}${x.reference ? ' · ' + E(x.reference) : ''}${x.note ? ' · ' + E(x.note) : ''}</p>`).join('')}</div>
      <div class="ip-card"><h3>Your campaigns</h3><p class="ip-muted">Share these links. Anyone who signs up within 30 days of opening one is counted for you. A seat is used only by a verified sign-up.</p>
        ${data.links.length ? data.links.map(l => `<div style="padding:8px 0;border-top:1px solid var(--line,#e2e8f0)"><div class="ip-row"><code style="flex:1;min-width:220px">${E(l.url)}</code>${copy(l.url)}</div><span class="ip-muted">${E(l.campaign || '')} · ${l.clicks} visits · ${seats(l.seats)}</span></div>`).join('') : '<p class="ip-muted">Your campaign links will appear here once CVTara sets them up.</p>'}</div>
      ${mgr && data.links.length ? `<div class="ip-card"><h3>Invite your students</h3><p class="ip-muted">Upload an Excel or CSV file (one student per row: name, email), or paste the list. CVTara creates a personal sign-up link for each student for you to share. No account is created and CVTara sends them nothing; each student signs up themselves.</p>
        <form id="ppInv" class="ip-form"><label>Campaign<select name="linkId">${data.links.map(l => `<option value="${l.id}">${E(l.campaign || l.code)}</option>`).join('')}</select></label><label>File (.xlsx or .csv)<input type="file" name="file" accept=".xlsx,.csv,.txt"></label>
        <label style="grid-column:1/-1">Or paste<textarea name="text" rows="4" placeholder="Asha Rao, asha@example.com"></textarea></label>
        <label style="grid-column:1/-1"><span><input type="checkbox" name="authority" value="1" required> We may share these students' names and emails with CVTara for this purpose, and we have told them.</span></label>
        <div style="grid-column:1/-1"><button class="button mint" type="submit">Make personal links</button> <button type="button" class="button secondary small" data-pp-invites>Download all invite links</button> <span class="ip-muted" data-pp-st3 role="status"></span></div></form></div>` : ''}
      <div class="ip-card"><h3>Ranking</h3><p class="ip-muted">Each month: verified sign-ups 40 points, paying customers 40, conversion 20, compared with the best partner that month. Only you see your place.</p>
        ${(data.rankings || []).map(r => `<p>${E(r.month)}: <b>#${r.rank}</b> of ${r.of} partners · score ${r.score} · ${r.signups} verified sign-ups, ${r.buyers} paying</p>`).join('') || '<p class="ip-muted">Not ranked yet.</p>'}</div>
      ${(data.rewards || []).length ? `<div class="ip-card"><h3>Thank-you gifts</h3>${data.rewards.map(w => `<div style="padding:8px 0;border-top:1px solid var(--line,#e2e8f0)"><b>${E(w.title)}</b> · ${E({ approved: 'please confirm your address', address: 'address confirmed', dispatched: 'on its way', delivered: 'delivered', cancelled: 'cancelled' }[w.status] || w.status)}${w.courier ? ' · ' + E(w.courier) + ' ' + E(w.tracking || '') : ''}
        ${mgr && ['approved', 'address'].includes(w.status) ? `<form class="ip-form" data-pp-addr="${w.id}" style="grid-template-columns:1fr"><label>Delivery address<textarea name="address" rows="3" minlength="15" maxlength="600" required>${E(w.address || '')}</textarea></label><button class="button secondary small" type="submit">Save address</button></form>` : ''}</div>`).join('')}</div>` : ''}
      <div class="ip-card"><h3>By month</h3>${data.months.length ? `<table style="width:100%;border-collapse:collapse"><thead><tr><th align="left">Month</th><th align="right">Sign-ups</th><th align="right">Buyers</th><th align="right">Revenue</th></tr></thead><tbody>${data.months.map(m => `<tr><td>${E(m.month)}</td><td align="right">${m.signups}</td><td align="right">${m.buyers}</td><td align="right">${rs(m.revenuePaise)}</td></tr>`).join('')}</tbody></table>` : '<p class="ip-muted">No sign-ups yet.</p>'}</div>
      ${accepted ? `<div class="ip-card"><h3>Agreement</h3><p class="ip-muted">Version ${accepted.version} accepted ${day(accepted.accepted_at)} by ${E(accepted.accepted_name || '')}.</p><details><summary>Read it</summary><div style="white-space:pre-wrap;font-size:14px">${E(accepted.body || '')}</div></details></div>` : ''}`;
    const acc = h.querySelector('#ppAccept');
    if (acc) acc.onsubmit = async ev => { ev.preventDefault(); const st = h.querySelector('[data-pp-st]'); try { data = await post('partner/agreements/' + waiting.id + '/accept', { hash: waiting.body_hash, name: acc.name.value, agree: acc.agree.checked ? 1 : 0 }); draw(); } catch (x) { st.textContent = x.message; } };
    const inv = h.querySelector('#ppInv');
    if (inv) inv.onsubmit = async ev => {
      ev.preventDefault(); const st = h.querySelector('[data-pp-st3]'); st.textContent = 'Working…';
      try {
        const body = { linkId: Number(inv.linkId.value), authority: inv.authority.checked ? 1 : 0 };
        const f = inv.file.files[0];
        if (f && /\.xlsx$/i.test(f.name)) { const b = new Uint8Array(await f.arrayBuffer()); let s2 = ''; for (let i = 0; i < b.length; i += 32768) s2 += String.fromCharCode.apply(null, b.subarray(i, i + 32768)); body.sheet = btoa(s2); }
        else if (f) body.text = await f.text(); else body.text = inv.text.value;
        const r = await post('partner/invites', body);
        st.textContent = `${r.added} personal links made${r.duplicates ? ', ' + r.duplicates + ' already invited' : ''}${r.hasAccount ? ', ' + r.hasAccount + ' already have an account' : ''}${r.unreadable ? ', ' + r.unreadable + ' rows without an email' : ''}.`;
        if (r.invites.length) dl('cvtara-student-links.csv', r.invites);
        data = await get() || data;
      } catch (x) { st.textContent = x.message; }
    };
    h.querySelectorAll('[data-pp-addr]').forEach(f => f.onsubmit = async ev => { ev.preventDefault(); try { data = await post('partner/rewards/' + f.dataset.ppAddr + '/address', { address: f.address.value }); draw(); } catch (x) { alert(x.message); } });
  }

  document.addEventListener('click', async e => {
    if (e.target.closest('[data-pp-payout]')) { const st = document.querySelector('[data-pp-st2]'); try { await post('partner/payout'); data = await get() || data; draw(); } catch (x) { if (st) st.textContent = x.message; } return; }
    if (e.target.closest('[data-pp-invites]')) { try { const r = await fetch('/api/partner/invites', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(x => x.json()); dl('cvtara-student-links.csv', (r.invites || []).map(i => ({ ...i, note: i.status === 'invited' ? '' : i.status.replace('_', ' ') }))); } catch { /* ignore */ } return; }
    const b = e.target.closest('[data-pp-copy]'); if (!b) return;
    navigator.clipboard?.writeText(b.dataset.ppCopy).then(() => { b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1500); });
  });
  document.addEventListener('cvtara-workspace-view', async e => { if (e.detail === 'partner') { data = await get().catch(() => null) || data; draw(); } });
  get().then(j => { if (j && j.partner) { data = j; addNav(); if (new URLSearchParams(location.search).get('view') === 'partner') document.querySelector('[data-workspace-view="partner"]')?.click(); } }).catch(() => {});
})();
