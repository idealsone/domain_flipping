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

  /* ---------- pricing & add-ons ---------- */
  async function pricingPanel() {
    const host = $('#admin-pricing');
    if (!host) return;
    let plans, promo, svc;
    try { [plans, promo, svc] = await Promise.all([api('/api/admin/plans').then(r => r.plans), api('/api/admin/promos').catch(() => ({ campaigns: [] })), api('/api/admin/services').then(r => r.settings || {}).catch(() => ({}))]); } catch (e) { host.innerHTML = `<p role="alert">${E(e.message)}</p>`; return; }
    let tab = ['packages', 'services', 'addons', 'free'].includes(host.dataset.tab) ? host.dataset.tab : 'packages';
    // Offers that change what customers pay right now (Offers & promotions). Shown here so prices and offers are checked together.
    const offers = (promo.campaigns || []).filter(c => ['live', 'scheduled', 'upcoming'].includes(c.status));
    const after = (price, label) => { const m = /^(\d+)% OFF$/.exec(label || ''), f = /^₹([\d,]+) OFF$/.exec(label || ''); return m ? Math.round(price * (100 - +m[1]) / 100) : f ? Math.max(0, price - +f[1].replace(/,/g, '')) : price; };
    // Customers see a service only while it is On sale. Tara Expert Review is written automatically only while Tara AI is on.
    const svcNote = () => { const er = plans['expert-review'] || {}; const auto = svc.taraEnabled && svc.autoReview !== false; return `<section class="panel panel-pad" style="margin:0 0 16px;border-left:4px solid ${er.sale && auto ? '#22c55e' : '#f59e0b'}"><b>Tara Expert Review: ${er.sale ? 'customers can buy it' : 'hidden from customers (not on sale)'}</b><p class="ops-note" style="margin:4px 0 0">${er.sale ? '' : 'Tick <b>On sale</b> below and save to show it on the pricing page, checkout and the customer dashboard. '}${auto ? 'Tara AI is on, so each review is written and emailed automatically.' : 'Tara AI is off, so reviews wait in Refunds &amp; reviews for you to write. Add the Tara AI key and switch Tara AI on in Settings → Integrations to make it automatic.'}</p></section>`; };
    const offerBox = () => `<section class="panel panel-pad" style="margin:0 0 16px"><h3 style="margin-top:0">Offers running with these prices</h3>${offers.length ? offers.map(c => { const v = c.view || {}; const sale = Object.keys(plans).filter(k => plans[k].type === 'plan' && k !== 'free' && plans[k].sale && (!v.plan || v.plan === k)); return `<p style="margin:6px 0"><b>${E(c.name || v.heading || 'Offer')}</b> · ${E(c.status === 'live' ? 'Live now' : c.status === 'scheduled' ? 'Running on schedule' : 'Starts later')}${v.code ? ` · code <b>${E(v.code)}</b> (${E(v.discount || 'no discount')})` : ''}${c.couponOk ? '' : ' · <b style="color:#ef4444">coupon is off or expired, so visitors do not see this offer</b>'}<br><small>${sale.map(k => `${E(plans[k].name)}: ₹${plans[k].price}${v.discount ? ` → <b>₹${after(plans[k].price, v.discount)}</b>` : ''}`).join(' · ')}</small></p>`; }).join('') : '<p class="ops-note" style="margin:0">No offer is running. Create one in Offers &amp; promotions.</p>'}<p class="ops-note" style="margin:8px 0 0">Referral discount: friends who use a referral link get the referral discount on orders from ₹299 (set in Referral Ops).</p></section>`;
    const num = (k, v, extra = '') => `<input type="number" min="0" data-k="${k}" value="${v ?? ''}" ${extra}>`;
    const card = (k, p) => `<section class="ad-plan" data-key="${E(k)}"><div class="ad-plan-head"><h3>${E(p.name)} <small style="color:var(--muted);font-weight:500">· ${E(k)}</small></h3><div style="display:flex;gap:16px">${k !== 'free' ? `<label class="ad-check"><input type="checkbox" data-k="sale" ${p.sale ? 'checked' : ''}> On sale</label>` : ''}${p.type === 'plan' && k !== 'free' ? `<label class="ad-check"><input type="checkbox" data-k="popular" ${p.popular ? 'checked' : ''}> Most popular</label><label class="ad-check"><input type="checkbox" data-k="bestValue" ${p.bestValue ? 'checked' : ''}> Best value</label>` : ''}</div></div>
      <div class="ad-fields"><label class="wide">Name<input data-k="name" value="${E(p.name)}" maxlength="120"></label><label class="wide">Tagline<input data-k="tagline" value="${E(p.tagline)}" maxlength="200"></label>
      <label>Price (₹)${num('price', p.price, 'required')}</label>${p.type === 'service' ? `<label>Price with an active plan (₹)${num('planPrice', p.planPrice)}</label>` : ''}<label>Original price / MRP (₹)${num('mrp', p.mrp)}</label><label>${p.type === 'addon' ? 'Validity (0 = until the plan ends)' : 'Validity (days)'}${num('accessDays', p.accessDays || 0)}</label><label>Resume downloads${num('savedResumes', p.savedResumes)}</label>
      <label>Cover-letter downloads${num('savedLetters', p.savedLetters)}</label><p class="wide" style="margin:0;color:var(--muted);font-size:13px">Customers can save unlimited drafts for free. A clean download uses <b>1 credit of its own kind</b>: <b>${+p.savedResumes || 0} resume downloads</b> and <b>${+p.savedLetters || 0} cover-letter downloads</b>, counted separately (one primary resume free once for life after a first purchase, not on renewals; the same version in another format is free; an edited version needs a new credit). ${p.type === 'addon' ? 'Add-on credits are added to the customer\'s separate resume and letter balances, last until the customer\'s plan ends and follow an early renewal.' : 'Free-account previews show a watermark; paid previews and downloads are clean. Each ATS scan is counted from the scan allowance below.'}</p>${p.type === 'plan' ? `<label>ATS scans (0 = unlimited)${num('atsScans', p.atsScans ?? 0)}</label><label>Daily scan cap${num('dailyMatches', p.dailyMatches)}</label>` : ''}
      ${p.type === 'plan' && k !== 'free' ? `<label class="full">Included perks (one per line)<textarea data-k="perks" rows="3">${E((p.perks || []).join('\n'))}</textarea></label>` : ''}</div></section>`;
    const draw = () => {
      host.dataset.tab = tab;
      const groups = { packages: Object.keys(plans).filter(k => plans[k].type === 'plan' && k !== 'free'), services: Object.keys(plans).filter(k => plans[k].type === 'service'), addons: Object.keys(plans).filter(k => plans[k].type === 'addon'), free: ['free'] };
      host.innerHTML = `<div class="admin-heading"><div><h2>Pricing &amp; add-ons</h2><p>Everything here is live on the website, checkout and dashboards the moment you save. Each plan = 1 premium primary resume + the JD-tailored resumes and cover letters below.</p></div><a class="button secondary" href="/#pricing" target="_blank" rel="noopener">View public pricing ↗</a></div>
        <div class="ad-tabs">${[['packages', '📦 Packages'], ['services', '⭐ Services'], ['addons', '➕ Add-on packs'], ['free', '👤 Free plan']].map(([t, l]) => `<button type="button" class="${tab === t ? 'active' : ''}" data-tab="${t}">${l}</button>`).join('')}</div>
        ${offerBox()}${tab === 'services' ? svcNote() : ''}<form id="adPlansForm">${groups[tab].map(k => card(k, plans[k])).join('')}<div class="ad-save"><button class="button primary">Save ${tab === 'addons' ? 'add-ons' : tab === 'services' ? 'services' : 'plans'}</button><span role="status" id="adPlanSt"></span></div></form>`;
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
        <label class="ad-check full"><input type="checkbox" name="showPurchases" ${d.showPurchases !== false ? 'checked' : ''}> Show real purchases: “Priya from Pune started Fresher Pro · 5 minutes ago”. Only customers who ticked the box at checkout are named (first name and city); nobody else is ever shown, and nothing is made up.</label>
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
      d.enabled = f.elements.enabled.checked; d.useLiveActivity = f.elements.useLiveActivity.checked; d.showPurchases = f.elements.showPurchases.checked;
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
    const labels = { https: 'Site served over HTTPS', privateFolder: 'App folder is outside the public web root', previewOff: 'Demo mode is off', emailConfigured: 'Email sender configured', scheduler: 'Scheduler (cron) ran in the last 5 minutes', payoutPolicy: 'Payout identity & tax policy recorded' };
    const op = s.operator || {};
    host.innerHTML = `<div class="admin-heading"><div><h2>Setup &amp; health</h2><p>Launch checklist, business details, and payout policy.</p></div><button class="button secondary" id="adSetupRefresh">Refresh</button></div>
      <div class="panel panel-pad"><h3>Launch checklist</h3><div class="ad-check-list">${Object.entries(labels).map(([k, l]) => `<div><span class="ad-pill ${s.checks[k] ? 'ok' : 'bad'}">${s.checks[k] ? 'OK' : 'To do'}</span><b>${l}</b></div>`).join('')}</div>
      <p style="margin-top:14px;color:var(--muted)">Cron command (use your host’s PHP path): <code>/usr/bin/php ${E((s.cronCommand || '').split(' ').slice(1).join(' '))}</code><br>Webhooks: Razorpay <code>${E(s.webhooks.razorpay)}</code>${s.webhooks.phonepe ? ` · PhonePe <code>${E(s.webhooks.phonepe)}</code>` : ''}</p></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Business details</h3><p style="color:var(--muted)">Shown on contact and legal pages.</p><form id="adBiz" class="ad-fields"><label class="wide">Legal / trading name<input name="legalName" required maxlength="120" value="${E(op.legalName || '')}"></label><label class="wide">Support email<input type="email" name="supportEmail" required value="${E(op.supportEmail || '')}"></label><label>Support phone<input name="supportPhone" required maxlength="30" value="${E(op.supportPhone || '')}"></label><label class="wide">Registered address<input name="address" required minlength="10" maxlength="500" value="${E(op.address || '')}"></label><div class="ad-save full"><button class="button primary">Save business details</button><span role="status" id="adBizSt"></span></div></form></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Payout policy</h3><p style="color:var(--muted)">Record how you check identity and tax before paying referral cash by hand. Needs a fresh email-code check.</p><form id="adPolicy" class="ad-fields"><label class="wide">Identity verification process<textarea name="identityProcess" required minlength="20" maxlength="2000" rows="3"></textarea></label><label class="wide">Tax handling process<textarea name="taxProcess" required minlength="20" maxlength="2000" rows="3"></textarea></label><label>Data retention (days)<input type="number" name="retentionDays" min="1" max="3650" value="365" required></label><label>Responsible reviewer<input name="reviewer" required minlength="3" maxlength="120"></label><label class="ad-check full"><input type="checkbox" name="confirmed" required> I confirm these processes are approved and followed.</label><div class="ad-save full"><button class="button primary">Record payout policy</button><span role="status" id="adPolSt"></span></div></form></div>
      <div class="panel panel-pad" style="margin-top:16px"><h3>Maintenance tools</h3><p style="display:flex;gap:10px;flex-wrap:wrap"><button class="button secondary" id="adCache">Clear compiled cache</button><button class="button secondary" id="adEmailTest">Send a test email to me</button></p><p role="status" id="adToolSt"></p></div>`;
    $('#adSetupRefresh').onclick = setupPanel;
    $('#adBiz').onsubmit = async e => { e.preventDefault(); try { await api('/api/admin/setup/business', Object.fromEntries(new FormData(e.target))); $('#adBizSt').textContent = 'Saved.'; } catch (err) { $('#adBizSt').textContent = err.message; } };
    $('#adPolicy').onsubmit = async e => {
      e.preventDefault(); if (!(await ensureRecent())) return;
      const v = Object.fromEntries(new FormData(e.target)); v.retentionDays = Number(v.retentionDays); v.confirmed = true;
      try { await api('/api/admin/setup/payout-policy', v); $('#adPolSt').textContent = 'Recorded.'; setupPanel(); } catch (err) { $('#adPolSt').textContent = err.message; }
    };
    $('#adCache').onclick = async () => { try { await api('/api/admin/setup/cache', {}); $('#adToolSt').textContent = 'Compiled caches cleared.'; } catch (err) { $('#adToolSt').textContent = err.message; } };
    $('#adEmailTest').onclick = async () => { $('#adToolSt').textContent = 'Sending…'; try { const r = await api('/api/admin/setup/email-test', {}); $('#adToolSt').textContent = `Queued and processed: ${r.delivery.sent} sent.`; } catch (err) { $('#adToolSt').textContent = err.message; } };
  }

  // Users live in the paged Users view (people.js); this file no longer loads a second copy of the list.
  const panels = { pricing: pricingPanel, popup: popupPanel, setup: setupPanel };
  const run = k => { if (panels[k]) panels[k](); };
  document.addEventListener('click', e => { const b = e.target.closest('[data-admin-view]'); if (b && panels[b.dataset.adminView]) setTimeout(() => run(b.dataset.adminView), 0); });
  addEventListener('load', () => setTimeout(() => {
    const tab = new URLSearchParams(location.search).get('tab');
    if (panels[tab]) run(tab);
  }, 900));
})();
