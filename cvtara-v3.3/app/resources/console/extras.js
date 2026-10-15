(() => {
  'use strict';
  if (document.body.dataset.page !== 'admin' || !window.CVTaraAPI) return;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, body, method) => window.CVTaraAPI.request(p, body === undefined ? (method ? { method } : undefined) : { method: method || 'POST', body });
  const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN');
  const dt = d => d ? new Date(String(d).replace(' ', 'T') + (String(d).includes('T') ? '' : 'Z')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

  /* ---------- re-verification (email code + password) before sensitive actions ---------- */
  async function ensureRecent() {
    const s = await api('/api/security/status');
    if (s.recent) return true;
    return new Promise(resolve => {
      const d = document.createElement('dialog');
      d.className = 'ad-dialog';
      d.innerHTML = `<form class="pr-form open" style="margin:0;min-width:min(420px,86vw)"><h3 style="margin:0">Confirm it’s you</h3><p style="margin:0;color:var(--muted)">This action needs a fresh check. We’ll email a 6-digit code; enter it with your password. It unlocks sensitive actions for 10 minutes.</p><div class="pr-actions"><button type="button" class="button secondary" data-send>Email me a code</button></div><label>6-digit code<input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></label><label>Current password<input type="password" name="password" autocomplete="current-password"></label><p role="status" data-st></p><div class="pr-actions"><button class="button primary">Verify</button><button type="button" class="button secondary" data-cancel>Cancel</button></div></form>`;
      document.body.append(d); d.showModal();
      const st = $('[data-st]', d), f = $('form', d);
      $('[data-send]', d).onclick = async () => { st.textContent = 'Sending…'; try { await api('/api/security/send-code', {}); st.textContent = 'Code sent. Check your email (it can take a minute).'; } catch (e) { st.textContent = e.message; } };
      $('[data-cancel]', d).onclick = () => { d.close(); d.remove(); resolve(false); };
      f.onsubmit = async e => { e.preventDefault(); st.textContent = 'Verifying…'; try { await api('/api/security/verify-code', Object.fromEntries(new FormData(f))); d.close(); d.remove(); resolve(true); } catch (err) { st.textContent = err.message; } };
    });
  }

  window.CVTaraEnsureRecent = ensureRecent;

  /* Any owner write that the server protects with the step-up check opens the confirm dialog once, then retries. */
  const baseRequest = window.CVTaraAPI.request;
  window.CVTaraAPI.request = async (path, options) => {
    try { return await baseRequest(path, options); }
    catch (e) {
      if (!/^\/api\/admin\//.test(path) || !/Verify your email code/.test(e.message || '')) throw e;
      if (!(await ensureRecent())) throw e;
      return baseRequest(path, options);
    }
  };

  /* ---------- users ---------- */
  const U = { page: 1, q: '', plans: null };
  async function usersPanel() {
    const host = $('#admin-users');
    if (!host) return;
    if (!U.plans) { try { U.plans = (await api('/api/admin/plans')).plans; } catch { U.plans = {}; } }
    let d;
    try { d = await api(`/api/admin/users?page=${U.page}&q=${encodeURIComponent(U.q)}`); } catch (e) { host.innerHTML = `<p role="alert">${E(e.message)}</p>`; return; }
    const from = d.usersTotal ? (d.page - 1) * d.perPage + 1 : 0, to = Math.min(d.usersTotal, d.page * d.perPage);
    host.innerHTML = `<div id="adUsers"><div class="admin-heading"><div><h2>Users &amp; access</h2><p>${d.usersTotal} account${d.usersTotal === 1 ? '' : 's'} · search, review plans and block abusive accounts.</p></div></div>
      <div class="ad-toolbar"><input type="search" id="adUserQ" placeholder="Search by name or email" value="${E(U.q)}" aria-label="Search users"><button class="button secondary" id="adUserGo">Search</button></div>
      <div class="panel panel-pad" style="overflow:auto"><table class="ad-table"><thead><tr><th>User</th><th>Plan</th><th>Resumes</th><th>Applications</th><th>Joined</th><th>Status</th><th></th></tr></thead><tbody>${d.users.map(u => `<tr><td><b>${E(u.name || 'Name not added')}</b><br><small>${E(u.email)}</small></td><td>${u.paid ? `<span class="ad-pill ok">${E(U.plans[u.last_plan]?.name || u.last_plan)}</span><br><small>until ${dt(u.accessUntil)}</small>` : u.ever_paid ? '<span class="ad-pill mid">Expired</span>' : '<span class="ad-pill mid">Free</span>'}</td><td>${u.resumes}</td><td>${u.applications}</td><td>${dt(u.created_at)}</td><td>${u.blocked ? '<span class="ad-pill bad">Blocked</span>' : '<span class="ad-pill ok">Active</span>'}${u.isAdmin ? ' <span class="ad-pill mid">Owner</span>' : ''}</td><td>${u.isAdmin ? '' : `<button class="button secondary small" data-block="${E(u.owner_id)}" data-state="${u.blocked ? 0 : 1}" ${u.blocked ? '' : 'style="color:#b91c1c"'}>${u.blocked ? 'Unblock' : 'Block'}</button>`}</td></tr>`).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--muted)">No users match.</td></tr>'}</tbody></table>
      <div class="ad-toolbar" style="margin:14px 0 0;align-items:center"><button class="button secondary small" data-pg="-1" ${d.page <= 1 ? 'disabled' : ''}>← Previous</button><span style="color:var(--muted)">Showing ${from}–${to} of ${d.usersTotal}</span><button class="button secondary small" data-pg="1" ${to >= d.usersTotal ? 'disabled' : ''}>Next →</button></div></div><p role="status" id="adUserSt"></p></div>`;
    const go = () => { U.q = $('#adUserQ').value.trim(); U.page = 1; usersPanel(); };
    $('#adUserGo').onclick = go; $('#adUserQ').onkeydown = e => { if (e.key === 'Enter') go(); };
    $$('[data-pg]', host).forEach(b => b.onclick = () => { U.page += Number(b.dataset.pg); usersPanel(); });
    $$('[data-block]', host).forEach(b => b.onclick = async () => {
      const blocking = b.dataset.state === '1';
      const note = prompt(blocking ? 'Why are you blocking this account? (kept in the audit log)' : 'Note for unblocking (kept in the audit log)', '');
      if (note === null) return;
      if (note.trim().length < 5) { $('#adUserSt').textContent = 'Please enter a note of at least 5 characters.'; return; }
      if (!(await ensureRecent())) return;
      try { await api('/api/admin/users/block', { userId: Number(b.dataset.block), blocked: blocking, note: note.trim() }); $('#adUserSt') && ($('#adUserSt').textContent = blocking ? 'Account blocked and signed out.' : 'Account unblocked.'); const m = $('#adUserSt')?.textContent; await usersPanel(); $('#adUserSt').textContent = m; } catch (e) { $('#adUserSt').textContent = e.message; }
    });
  }

  /* ---------- pricing & add-ons ---------- */
  async function pricingPanel() {
    const host = $('#admin-pricing');
    if (!host) return;
    let plans;
    try { plans = (await api('/api/admin/plans')).plans; } catch (e) { host.innerHTML = `<p role="alert">${E(e.message)}</p>`; return; }
    let tab = host.dataset.tab || 'fresher';
    const num = (k, v, extra = '') => `<input type="number" min="0" data-k="${k}" value="${v ?? ''}" ${extra}>`;
    const card = (k, p) => `<section class="ad-plan" data-key="${E(k)}"><div class="ad-plan-head"><h3>${E(p.name)} <small style="color:var(--muted);font-weight:500">· ${E(k)}</small></h3><div style="display:flex;gap:16px">${p.type === 'plan' && k !== 'free' ? `<label class="ad-check"><input type="checkbox" data-k="popular" ${p.popular ? 'checked' : ''}> Mark as popular</label><label class="ad-check"><input type="checkbox" data-k="referral" ${p.referral ? 'checked' : ''}> Referral access</label>` : ''}</div></div>
      <div class="ad-fields"><label class="wide">Name<input data-k="name" value="${E(p.name)}" maxlength="120"></label><label class="wide">Tagline<input data-k="tagline" value="${E(p.tagline)}" maxlength="200"></label>
      <label>Price (₹)${num('price', p.price, 'required')}</label><label>Original price / MRP (₹)${num('mrp', p.mrp)}</label><label>Validity (days)${num('accessDays', p.accessDays || (p.type === 'addon' ? 45 : 0))}</label><label>Resume downloads${num('savedResumes', p.savedResumes)}</label>
      <label>Cover-letter downloads${num('savedLetters', p.savedLetters)}</label><p class="wide" style="margin:0;color:var(--muted);font-size:13px">Customers can save unlimited drafts for free. A clean download uses <b>1 credit of its own kind</b>: <b>${+p.savedResumes || 0} resume downloads</b> and <b>${+p.savedLetters || 0} cover-letter downloads</b>, counted separately (one primary resume free once for life after a first purchase, not on renewals; the same version in another format is free; an edited version needs a new credit). ${p.type === 'addon' ? 'Add-on credits are added to the customer\'s separate resume and letter balances, stay valid for the days above and carry into renewals.' : 'Previews always show a watermark.'}</p>${p.type === 'plan' ? `<label>ATS scans (0 = unlimited)${num('atsScans', p.atsScans ?? 0)}</label><label>Daily scan cap${num('dailyMatches', p.dailyMatches)}</label>` : ''}
      ${p.type === 'plan' && k !== 'free' ? `<label class="full">Included perks (one per line)<textarea data-k="perks" rows="3">${E((p.perks || []).join('\n'))}</textarea></label>` : ''}</div></section>`;
    const draw = () => {
      host.dataset.tab = tab;
      const groups = { fresher: Object.keys(plans).filter(k => plans[k].type === 'plan' && plans[k].audience === 'fresher'), experienced: Object.keys(plans).filter(k => plans[k].type === 'plan' && plans[k].audience === 'experienced'), addons: Object.keys(plans).filter(k => plans[k].type === 'addon'), free: ['free'] };
      host.innerHTML = `<div class="admin-heading"><div><h2>Pricing &amp; add-ons</h2><p>Everything here is live on the website, checkout and dashboards the moment you save. Each plan = 1 premium primary resume + the JD-tailored resumes and cover letters below.</p></div><a class="button secondary" href="/#pricing" target="_blank" rel="noopener">View public pricing ↗</a></div>
        <div class="ad-tabs">${[['fresher', '🎓 Fresher plans'], ['experienced', '💼 Experienced plans'], ['addons', '➕ Add-on packs'], ['free', '👤 Normal user']].map(([t, l]) => `<button type="button" class="${tab === t ? 'active' : ''}" data-tab="${t}">${l}</button>`).join('')}</div>
        <form id="adPlansForm">${groups[tab].map(k => card(k, plans[k])).join('')}<div class="ad-save"><button class="button primary">Save ${tab === 'addons' ? 'add-ons' : 'plans'}</button><span role="status" id="adPlanSt"></span></div></form>`;
      $$('[data-tab]', host).forEach(b => b.onclick = () => { collect(); tab = b.dataset.tab; draw(); });
      $('#adPlansForm').onsubmit = async e => {
        e.preventDefault(); collect();
        const st = $('#adPlanSt'); st.textContent = 'Saving…';
        try { const r = await api('/api/admin/plans', { plans }); plans = r.plans; window.CVTaraBusiness = null; st.textContent = 'Saved. Prices and limits are live.'; } catch (err) { st.textContent = err.message; }
      };
    };
    function collect() {
      $$('.ad-plan', host).forEach(sec => {
        const p = plans[sec.dataset.key];
        $$('[data-k]', sec).forEach(i => {
          const k = i.dataset.k;
          if (i.type === 'checkbox') p[k] = i.checked;
          else if (k === 'perks') p.perks = i.value.split('\n').map(x => x.trim()).filter(Boolean);
          else if (i.type === 'number') p[k] = i.value === '' ? null : Number(i.value);
          else p[k] = i.value;
        });
      });
    }
    draw();
  }

  /* ---------- offers & popup ---------- */
  async function popupPanel() {
    const host = $('#admin-popup');
    if (!host) return;
    let r;
    try { r = await api('/api/admin/content/popup'); } catch (e) { host.innerHTML = `<p role="alert">${E(e.message)}</p>`; return; }
    let d = r.data, rev = r.revision;
    const draw = () => {
      host.innerHTML = `<div class="admin-heading"><div><h2>Offers &amp; social-proof popup</h2><p>A small notice appears at the bottom-left of public pages and the customer dashboard after the delay below, then rotates through the messages.</p></div></div>
        <form id="adPopup" class="ad-plan"><div class="ad-fields"><label class="ad-check full"><input type="checkbox" name="enabled" ${d.enabled ? 'checked' : ''}> Show the popup to visitors</label>
        <label>First popup after (seconds)<input type="number" name="delaySeconds" min="0" max="600" value="${d.delaySeconds}"></label><label>Show each message for (seconds)<input type="number" name="showSeconds" min="3" max="30" value="${d.showSeconds}"></label><label>Gap between messages (seconds)<input type="number" name="gapSeconds" min="3" max="120" value="${d.gapSeconds}"></label>
        <label class="ad-check full"><input type="checkbox" name="useLiveActivity" ${d.useLiveActivity ? 'checked' : ''}> Include real activity (resumes saved today, ATS checks today, new members, plans started) and live plan savings. These use your real counts and only appear above a minimum number.</label></div>
        <h3 style="margin:20px 0 8px">Your own messages</h3><p style="color:var(--muted);margin:0 0 12px">Add offers, announcements or tips. Keep them truthful — do not invent customers, counts or deadlines.</p>
        <div id="adMsgs">${d.custom.map((m, i) => `<div class="ad-fields" style="margin-bottom:12px;padding:12px;border:1px dashed var(--line);border-radius:12px" data-i="${i}"><label>Icon<input data-m="icon" value="${E(m.icon || '✨')}" maxlength="8"></label><label class="wide">Title<input data-m="title" value="${E(m.title || '')}" maxlength="60"></label><label class="ad-check"><input type="checkbox" data-m="enabled" ${m.enabled ? 'checked' : ''}> Active</label><label class="full">Message<input data-m="text" value="${E(m.text || '')}" maxlength="200"></label><button type="button" class="button secondary small" data-rm="${i}">Remove</button></div>`).join('') || '<p style="color:var(--muted)">No custom messages yet.</p>'}</div>
        <div class="ad-save"><button type="button" class="button secondary" id="adAddMsg">+ Add message</button><button class="button primary">Save popup settings</button><button type="button" class="button secondary" id="adPreviewPop">Preview now</button><span role="status" id="adPopSt"></span></div></form>`;
      $('#adAddMsg').onclick = () => { read(); d.custom.push({ icon: '🎉', title: '', text: '', enabled: true }); draw(); };
      $$('[data-rm]', host).forEach(b => b.onclick = () => { read(); d.custom.splice(Number(b.dataset.rm), 1); draw(); });
      $('#adPreviewPop').onclick = () => { read(); const m = d.custom[0] || { icon: '👋', title: 'Preview', text: 'This is how a popup message looks.' }; let el = $('.fomo'); if (!el) { el = document.createElement('aside'); el.className = 'fomo'; document.body.append(el); } el.innerHTML = `<i>${E(m.icon)}</i><div><b>${E(m.title || 'CVTara')}</b><p>${E(m.text)}</p></div>`; el.classList.add('show'); setTimeout(() => el.classList.remove('show'), 5000); };
      $('#adPopup').onsubmit = async e => {
        e.preventDefault(); read();
        const st = $('#adPopSt'); st.textContent = 'Saving…';
        d.custom = d.custom.filter(m => m.text.trim()); try { const out = await api('/api/admin/content/popup', { data: d, revision: rev }); d = out.data; rev = out.revision; st.textContent = 'Saved. Visitors see the new settings within a minute.'; } catch (err) { st.textContent = err.message; }
      };
    };
    function read() {
      const f = $('#adPopup'); if (!f) return;
      d.enabled = f.elements.enabled.checked; d.useLiveActivity = f.elements.useLiveActivity.checked;
      ['delaySeconds', 'showSeconds', 'gapSeconds'].forEach(k => d[k] = Number(f.elements[k].value));
      d.custom = $$('[data-i]', f).map(row => ({ icon: $('[data-m=icon]', row).value, title: $('[data-m=title]', row).value, text: $('[data-m=text]', row).value, enabled: $('[data-m=enabled]', row).checked }));
    }
    draw();
  }

  /* ---------- setup & health ---------- */
  async function setupPanel() {
    const host = $('#admin-setup');
    if (!host) return;
    let s;
    try { s = await api('/api/admin/setup/status'); } catch (e) { host.innerHTML = `<p role="alert">${E(e.message)}</p>`; return; }
    const labels = { https: 'Site served over HTTPS', privateFolder: 'App folder is outside the public web root', previewOff: 'Demo mode is off', emailConfigured: 'Email sender configured', scheduler: 'Scheduler (cron) ran in the last 5 minutes', phoneVerification: 'Phone verification (Twilio) enabled', payoutPolicy: 'Payout identity & tax policy recorded' };
    const op = s.operator || {};
    host.innerHTML = `<div class="admin-heading"><div><h2>Setup &amp; health</h2><p>Launch checklist, business details, payout policy and held reward reviews.</p></div><button class="button secondary" id="adSetupRefresh">Refresh</button></div>
      <div class="panel panel-pad"><h3>Launch checklist</h3><div class="ad-check-list">${Object.entries(labels).map(([k, l]) => `<div><span class="ad-pill ${s.checks[k] ? 'ok' : 'bad'}">${s.checks[k] ? 'OK' : 'To do'}</span><b>${l}</b></div>`).join('')}</div>
      <p style="margin-top:14px;color:var(--muted)">Cron command (use your host’s PHP path): <code>/usr/bin/php ${E((s.cronCommand || '').split(' ').slice(1).join(' '))}</code><br>Webhooks: Razorpay <code>${E(s.webhooks.razorpay)}</code></p></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Business details</h3><p style="color:var(--muted)">Shown on contact and legal pages.</p><form id="adBiz" class="ad-fields"><label class="wide">Legal / trading name<input name="legalName" required maxlength="120" value="${E(op.legalName || '')}"></label><label class="wide">Support email<input type="email" name="supportEmail" required value="${E(op.supportEmail || '')}"></label><label>Support phone<input name="supportPhone" required maxlength="30" value="${E(op.supportPhone || '')}"></label><label class="wide">Registered address<input name="address" required minlength="10" maxlength="500" value="${E(op.address || '')}"></label><div class="ad-save full"><button class="button primary">Save business details</button><span role="status" id="adBizSt"></span></div></form></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Payout policy</h3><p style="color:var(--muted)">Required before Cashfree payouts can be sent. Needs a fresh email-code check.</p><form id="adPolicy" class="ad-fields"><label class="wide">Identity verification process<textarea name="identityProcess" required minlength="20" maxlength="2000" rows="3"></textarea></label><label class="wide">Tax handling process<textarea name="taxProcess" required minlength="20" maxlength="2000" rows="3"></textarea></label><label>Data retention (days)<input type="number" name="retentionDays" min="1" max="3650" value="365" required></label><label>Responsible reviewer<input name="reviewer" required minlength="3" maxlength="120"></label><label class="ad-check full"><input type="checkbox" name="confirmed" required> I confirm these processes are approved and followed.</label><div class="ad-save full"><button class="button primary">Record payout policy</button><span role="status" id="adPolSt"></span></div></form></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Held reward reviews</h3>${s.rewardReviews.length ? `<table class="ad-table"><thead><tr><th>User</th><th>Phone</th><th>Reason</th><th></th></tr></thead><tbody>${s.rewardReviews.map(r => `<tr><td>${E(r.name)}<br><small>${E(r.email)}</small></td><td>${E(r.phone_masked)}</td><td>${E(r.review_note)}</td><td><button class="button secondary small" data-rv="${r.owner_id}" data-d="verified">Verify</button> <button class="button secondary small" data-rv="${r.owner_id}" data-d="rejected" style="color:#b91c1c">Reject</button></td></tr>`).join('')}</tbody></table>` : '<p style="color:var(--muted)">Nothing is waiting for review.</p>'}<p role="status" id="adRvSt"></p></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Maintenance tools</h3><p style="display:flex;gap:10px;flex-wrap:wrap"><button class="button secondary" id="adCache">Clear compiled cache</button><button class="button secondary" id="adEmailTest">Send a test email to me</button></p><p role="status" id="adToolSt"></p></div>`;
    $('#adSetupRefresh').onclick = setupPanel;
    $('#adBiz').onsubmit = async e => { e.preventDefault(); try { await api('/api/admin/setup/business', Object.fromEntries(new FormData(e.target))); $('#adBizSt').textContent = 'Saved.'; } catch (err) { $('#adBizSt').textContent = err.message; } };
    $('#adPolicy').onsubmit = async e => {
      e.preventDefault(); if (!(await ensureRecent())) return;
      const v = Object.fromEntries(new FormData(e.target)); v.retentionDays = Number(v.retentionDays); v.confirmed = true;
      try { await api('/api/admin/setup/payout-policy', v); $('#adPolSt').textContent = 'Recorded.'; setupPanel(); } catch (err) { $('#adPolSt').textContent = err.message; }
    };
    $$('[data-rv]', host).forEach(b => b.onclick = async () => {
      const note = prompt('Reason for your decision (min 10 characters)', ''); if (!note || note.trim().length < 10) return;
      if (!(await ensureRecent())) return;
      try { await api('/api/admin/setup/reward-review', { ownerId: Number(b.dataset.rv), decision: b.dataset.d, note: note.trim() }); setupPanel(); } catch (err) { $('#adRvSt').textContent = err.message; }
    });
    $('#adCache').onclick = async () => { try { await api('/api/admin/setup/cache', {}); $('#adToolSt').textContent = 'Compiled caches cleared.'; } catch (err) { $('#adToolSt').textContent = err.message; } };
    $('#adEmailTest').onclick = async () => { $('#adToolSt').textContent = 'Sending…'; try { const r = await api('/api/admin/setup/email-test', {}); $('#adToolSt').textContent = `Queued and processed: ${r.delivery.sent} sent.`; } catch (err) { $('#adToolSt').textContent = err.message; } };
  }

  const panels = { users: usersPanel, pricing: pricingPanel, popup: popupPanel, setup: setupPanel };
  const loaded = {};
  const run = k => { if (panels[k]) { loaded[k] = true; panels[k](); } };
  document.addEventListener('click', e => { const b = e.target.closest('[data-admin-view]'); if (b && panels[b.dataset.adminView]) setTimeout(() => run(b.dataset.adminView), 0); });
  // other scripts render #admin-users too; make sure ours is what stays on screen
  const obs = new MutationObserver(() => { const h = $('#admin-users'); if (h && loaded.users && !$('#adUsers') && !h.dataset.busy) { h.dataset.busy = '1'; usersPanel().finally(() => delete h.dataset.busy); } });
  addEventListener('load', () => setTimeout(() => {
    const tab = new URLSearchParams(location.search).get('tab');
    ['users', 'pricing', 'popup', 'setup'].forEach(k => { if (tab === k || k === 'users') run(k); });
    const h = $('#admin-users'); if (h) obs.observe(h, { childList: true });
  }, 900));
})();
