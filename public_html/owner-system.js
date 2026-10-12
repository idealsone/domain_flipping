(() => {
  'use strict';
  // Owner console: "System & growth" tab (maintenance switch, error log, marketing senders, campaign, leads).
  const host = document.getElementById('admin-system');
  if (!host || !window.CVTaraAPI) return;
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const post = (p, body) => api('/api/admin/system/' + p, { method: 'POST', body });
  const get = p => api('/api/admin/system/' + p);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const say = (el, msg, bad) => { if (el) { el.textContent = msg; el.style.color = bad ? '#b42318' : ''; } };
  const basisLabels = { signup_form: 'Signed up on a CVTara form', customer_opt_in: 'Existing customer who opted in', event_or_referral: 'Gave permission in person / by referral', written_consent: 'Gave written permission by email' };
  const sourceLabels = { website: 'Website form', workspace: 'Workspace signup', webinar: 'Webinar / event', partner: 'Partner (with permission)', linkedin_inbound: 'LinkedIn: they contacted me', manual: 'Other', import: 'Other list (with permission)' };
  let senders = [], campaign = null, loaded = false;

  host.innerHTML = '<section class="panel panel-pad"><p role="status">Loading system tools…</p></section>';

  async function load() {
    try {
      const s = await get('status');
      senders = s.senders; campaign = s.campaign.config;
      host.innerHTML = layout(s);
      renderSenders(); renderCampaign(s); fillMaintenance(s);
      refreshErrors(); refreshLeads();
      loaded = true;
    } catch (e) {
      host.innerHTML = '<section class="panel panel-pad"><h2>System tools could not load</h2><p role="alert">' + E(e.message) + '</p><button class="button primary" data-sys-retry>Retry</button></section>';
      host.querySelector('[data-sys-retry]').onclick = load;
    }
  }

  function layout(s) {
    return `<div class="admin-heading"><div><span class="eyebrow">OWNER CONTROLS</span><h2>System & growth</h2><p>Switch the site on or off, review server errors, and manage permission-based marketing email.</p></div></div>
<section class="panel panel-pad" id="sysMaint"><h3>Maintenance mode</h3>
<p id="sysMaintState"></p>
<form class="owner-form" id="sysMaintForm">
<label>Page title<input name="title" maxlength="120"></label>
<label>Message shown to visitors<textarea name="message" rows="3" maxlength="800"></textarea></label>
<label>Allowed IP addresses (one per line). These visitors can still use the live site while maintenance is on.<textarea name="allowIps" rows="3" placeholder="203.0.113.7"></textarea></label>
<p class="owner-help">Your current IP: <b>${E(s.yourIp)}</b> <button type="button" class="button secondary small" data-add-ip="${E(s.yourIp)}">Add my IP</button>. Owner accounts, sign-in, payment webhooks and unsubscribe links always work.</p>
<div class="owner-actions"><button class="button primary" type="button" data-maint="toggle"></button><button class="button secondary" type="submit">Save message & IPs</button><p role="status"></p></div>
</form></section>

<section class="panel panel-pad" id="sysErrors"><h3>Error log</h3>
<p id="sysErrSummary"></p>
<div class="owner-actions"><label>Show <select id="sysErrLevel"><option value="warning">Warnings and errors</option><option value="error">Errors only</option><option value="info">Everything</option></select></label>
<button class="button secondary" type="button" data-err="refresh">Refresh</button><button class="button secondary" type="button" data-err="download">Download</button><button class="button secondary" type="button" data-err="clear">Clear log</button><p role="status"></p></div>
<div id="sysErrList"></div></section>

<section class="panel panel-pad" id="sysSenders"><h3>Marketing senders</h3>
<p>Your main email under Integrations keeps sending account and payment emails. Marketing emails go only through the senders below, each with its own daily limit, so a marketing problem never affects password resets or receipts. Use a mailbox your business owns and an app password where the provider offers one.</p>
<div id="sysSenderList"></div>
<div class="owner-actions"><button class="button secondary" type="button" data-sender="add">Add sender</button><button class="button primary" type="button" data-sender="save">Save senders</button><p role="status" id="sysSenderStatus"></p></div></section>

<section class="panel panel-pad" id="sysCampaign"><h3>Follow-up campaign</h3><div id="sysCampaignBody"></div></section>

<section class="panel panel-pad" id="sysLeads"><h3>Contacts who agreed to hear from you</h3>
<p>Only add people who gave permission: a signup form, a customer opt-in, or written consent. Each contact stores where it came from and why you may email them. Do not paste lists that were scraped or bought. Unsubscribed people are never emailed again.</p>
<div id="sysLeadSummary" class="owner-help"></div>
<details class="owner-details"><summary>Add one contact</summary><form class="owner-form" id="sysLeadForm">
<label>Email<input name="email" type="email" required maxlength="190"></label><label>Name<input name="name" maxlength="120"></label>
${selectHtml('source', sourceLabels, 'website', 'Where did they come from?')}${selectHtml('basis', basisLabels, 'signup_form', 'What permission did they give?')}
<label>Note (optional)<input name="note" maxlength="255" placeholder="e.g. replied to my LinkedIn post asking for tips"></label>
<label class="owner-switch"><span>I confirm this person agreed to receive emails from CVTara.</span><input name="confirm" type="checkbox"></label>
<div class="owner-actions"><button class="button primary" type="submit">Add contact</button><p role="status"></p></div></form></details>
<details class="owner-details"><summary>Import a CSV (email,name)</summary><form class="owner-form" id="sysImportForm">
<label>Paste CSV rows<textarea name="csv" rows="6" placeholder="email,name&#10;asha@example.com,Asha" maxlength="600000"></textarea></label>
${selectHtml('source', sourceLabels, 'webinar', 'Where did this list come from?')}${selectHtml('basis', basisLabels, 'event_or_referral', 'What permission did they give?')}
<label>Describe the source (required)<input name="note" required maxlength="255" placeholder="e.g. October webinar signup sheet"></label>
<label class="owner-switch"><span>I confirm everyone in this list agreed to receive emails from CVTara.</span><input name="confirm" type="checkbox"></label>
<div class="owner-actions"><button class="button primary" type="submit">Import contacts</button><p role="status"></p></div></form></details>
<div class="owner-actions"><label>Show <select id="sysLeadStatus"><option value="">All</option><option value="new">New</option><option value="nurturing">In sequence</option><option value="converted">Became customers</option><option value="unsubscribed">Unsubscribed</option></select></label><input id="sysLeadQ" type="search" placeholder="Search name or email" aria-label="Search contacts"></div>
<div id="sysLeadList"></div></section>`;
  }

  function selectHtml(name, map, selected, label) {
    return `<label>${label}<select name="${name}">${Object.entries(map).map(([k, v]) => `<option value="${k}" ${k === selected ? 'selected' : ''}>${E(v)}</option>`).join('')}</select></label>`;
  }

  /* ---------- Maintenance ---------- */
  function fillMaintenance(s) {
    const m = s.maintenance, f = host.querySelector('#sysMaintForm');
    f.elements.title.value = m.title; f.elements.message.value = m.message; f.elements.allowIps.value = (m.allowIps || []).join('\n');
    paintMaint(m.enabled);
  }
  function paintMaint(on) {
    host.querySelector('#sysMaintState').innerHTML = on ? '<b style="color:#b42318">Maintenance is ON.</b> Visitors see the maintenance page.' : '<b style="color:#067647">Site is LIVE.</b> Visitors can use CVTara normally.';
    const b = host.querySelector('[data-maint=toggle]'); b.textContent = on ? 'Turn maintenance OFF' : 'Turn maintenance ON'; b.dataset.on = on ? '1' : '';
  }
  const ips = f => f.elements.allowIps.value.split(/\s+/).map(x => x.trim()).filter(Boolean);
  async function saveMaint(enabled, status) {
    const f = host.querySelector('#sysMaintForm');
    say(status, 'Saving…');
    try { const r = await post('maintenance', { enabled, allowIps: ips(f), title: f.elements.title.value, message: f.elements.message.value }); paintMaint(r.maintenance.enabled); say(status, r.maintenance.enabled ? 'Maintenance is on.' : 'Saved. The site is live.'); }
    catch (e) { say(status, e.message, true); }
  }

  /* ---------- Error log ---------- */
  async function refreshErrors() {
    const list = host.querySelector('#sysErrList'); if (!list) return;
    try {
      const d = await get('errors?limit=100&level=' + host.querySelector('#sysErrLevel').value);
      const c = d.counts || {};
      host.querySelector('#sysErrSummary').textContent = `Log size ${(d.summary.bytes / 1024).toFixed(1)} KB. Recent entries: ${c.error || 0} errors, ${c.warning || 0} warnings, ${(c.critical || 0) + (c.alert || 0) + (c.emergency || 0)} critical.`;
      list.innerHTML = d.entries.length ? d.entries.map(x => `<details class="owner-details"><summary><b>${E(x.level.toUpperCase())}</b> ${E(x.time)} — ${E(x.message)}</summary><pre style="white-space:pre-wrap;overflow:auto;max-height:260px">${E(x.detail || 'No more details.')}</pre></details>`).join('') : '<p>No errors in the selected range.</p>';
      list._entries = d.entries;
    } catch (e) { list.innerHTML = '<p role="alert">' + E(e.message) + '</p>'; }
  }

  /* ---------- Senders ---------- */
  function renderSenders() {
    host.querySelector('#sysSenderList').innerHTML = senders.length ? senders.map((s, i) => `<form class="owner-form panel panel-pad" data-i="${i}">
<strong>${E(s.label || 'New sender')}</strong> <span class="owner-help">${s.id ? 'Sent today: ' + (s.sentToday || 0) + ' of ' + E(s.dailyCap) : 'Not saved yet'}</span>
<label>Name for your reference<input name="label" value="${E(s.label)}" maxlength="60"></label>
<label>From email<input name="fromEmail" type="email" value="${E(s.fromEmail)}"></label><label>From name<input name="fromName" value="${E(s.fromName || 'CVTara')}" maxlength="80"></label>
<label>SMTP host<input name="smtpHost" value="${E(s.smtpHost)}" placeholder="smtp.gmail.com"></label>
<label>SMTP port<select name="smtpPort">${[587, 465, 2525, 25].map(p => `<option ${Number(s.smtpPort || 587) === p ? 'selected' : ''}>${p}</option>`).join('')}</select></label>
<label>SMTP username<input name="smtpUser" value="${E(s.smtpUser)}" autocomplete="off"></label>
<label>SMTP password / app password<input name="smtpPassword" type="password" autocomplete="new-password" placeholder="${s.hasPassword ? 'Saved. Leave blank to keep it.' : ''}"></label>
<label>Emails per day limit<input name="dailyCap" type="number" min="1" max="200" value="${E(s.dailyCap || 50)}"></label>
<label class="owner-switch"><span>Enabled</span><input name="enabled" type="checkbox" ${s.enabled !== false ? 'checked' : ''}></label>
<div class="owner-actions"><button type="button" class="button secondary small" data-sender="test" ${s.id ? '' : 'disabled title="Save the sender first"'}>Send test email</button><button type="button" class="button secondary small" data-sender="remove">Remove</button></div></form>`).join('') : '<p>No marketing sender yet. Add one to start permission-based follow-ups.</p>';
  }
  function readSenders() {
    return [...host.querySelectorAll('#sysSenderList form')].map(f => {
      const i = Number(f.dataset.i), o = { id: senders[i].id || undefined };
      ['label', 'fromEmail', 'fromName', 'smtpHost', 'smtpUser', 'smtpPassword'].forEach(k => o[k] = f.elements[k].value.trim());
      o.smtpPort = Number(f.elements.smtpPort.value); o.dailyCap = Number(f.elements.dailyCap.value); o.enabled = f.elements.enabled.checked;
      return o;
    });
  }

  /* ---------- Campaign ---------- */
  function renderCampaign(s) {
    const c = campaign, sum = s ? s.campaign : null, box = host.querySelector('#sysCampaignBody');
    const warn = s && !s.addressSet ? '<p role="alert">Add your postal address under <b>Footer & policies</b>. It is required in every marketing email, and the campaign stays paused until it is set.</p>' : '';
    box.innerHTML = `${warn}<p>Each contact gets up to five follow-up emails on the days after they gave permission (at least three days apart). Sending stops when they unsubscribe or buy a plan. Every email includes your address and an unsubscribe link.</p>
<p class="owner-help"><b>This is not cold outreach.</b> Do not upload scraped, bought or rented lists. There is no mailbox warming, no 6,000-mail blast, and no Grok or other AI sending sales mail on your behalf. Use Google Workspace SMTP under Integrations for signup, OTP, password reset and receipts (for example noreply@yourdomain). Add hello@, billing@, support@ and news@ as separate senders only if those mailboxes are yours and the people you email asked to hear from you.</p>
<form class="owner-form" id="sysCampaignForm">
<label class="owner-switch"><span>Campaign is on</span><input name="enabled" type="checkbox" ${c.enabled ? 'checked' : ''}></label>
<label>Send through<select name="senderId"><option value="">Choose a sender…</option>${senders.filter(x => x.id).map(x => `<option value="${E(x.id)}" ${x.id === c.senderId ? 'selected' : ''}>${E(x.label)} (${E(x.fromEmail)})</option>`).join('')}</select></label>
<label>Maximum emails per day (consent list only)<input name="dailyLimit" type="number" min="1" max="200" value="${E(c.dailyLimit)}"></label>
<div id="sysSteps">${c.steps.map((st, i) => stepHtml(st, i)).join('')}</div>
<p class="owner-help">You can use {{name}}, {{site_url}} in the text. The unsubscribe link and your address are added automatically.</p>
<div class="owner-actions"><button class="button secondary" type="button" data-step="add">Add email</button><button class="button primary" type="submit">Save campaign</button><p role="status"></p></div></form>
<p class="owner-help">${sum ? 'Contacts: ' + sum.total + ' · Sent today: ' + sum.sentToday : ''}</p>`;
  }
  const stepHtml = (st, i) => `<fieldset class="panel panel-pad" data-step-i="${i}"><legend>Email ${i + 1}</legend><label>Send on day<input name="day" type="number" min="1" max="365" value="${E(st.day)}"></label><label>Subject<input name="subject" maxlength="150" value="${E(st.subject)}"></label><label>Message<textarea name="body" rows="5" maxlength="5000">${E(st.body)}</textarea></label><button type="button" class="button secondary small" data-step="remove">Remove</button></fieldset>`;
  const readSteps = () => [...host.querySelectorAll('#sysSteps fieldset')].map(f => ({ day: Number(f.elements.day.value), subject: f.elements.subject.value, body: f.elements.body.value }));

  /* ---------- Leads ---------- */
  async function refreshLeads() {
    const list = host.querySelector('#sysLeadList'); if (!list) return;
    try {
      const q = new URLSearchParams({ status: host.querySelector('#sysLeadStatus').value, q: host.querySelector('#sysLeadQ').value });
      const d = await get('leads?' + q);
      const by = d.summary.byStatus || {};
      host.querySelector('#sysLeadSummary').textContent = `${d.summary.total} contacts · ${by.new || 0} new · ${by.nurturing || 0} in sequence · ${by.converted || 0} became customers · ${by.unsubscribed || 0} unsubscribed`;
      list.innerHTML = '<div class="owner-table-scroll"><table class="admin-table"><thead><tr><th>Email</th><th>Name</th><th>Source</th><th>Permission</th><th>Status</th><th>Step</th><th></th></tr></thead><tbody>' + (d.leads.length ? d.leads.map(l => `<tr><td>${E(l.email)}</td><td>${E(l.name)}</td><td>${E(sourceLabels[l.source] || l.source)}${l.source_note ? '<br><small>' + E(l.source_note) + '</small>' : ''}</td><td>${E(basisLabels[l.consent_basis] || l.consent_basis)}<br><small>${E(l.consented_at)}</small></td><td>${E(l.status)}</td><td>${E(l.campaign_step)}</td><td>${l.stop_marketing ? '' : `<button class="button secondary small" data-lead="stop" data-id="${l.id}">Stop emails</button> <button class="button secondary small" data-lead="delete" data-id="${l.id}">Delete</button>`}</td></tr>`).join('') : '<tr><td colspan="7">No contacts yet.</td></tr>') + '</tbody></table></div>';
    } catch (e) { list.innerHTML = '<p role="alert">' + E(e.message) + '</p>'; }
  }

  /* ---------- Events ---------- */
  host.addEventListener('click', async e => {
    const t = e.target.closest('button'); if (!t) return;
    const status = t.closest('.owner-actions')?.querySelector('[role=status]');
    if (t.dataset.addIp) { const f = host.querySelector('#sysMaintForm'); if (!ips(f).includes(t.dataset.addIp)) f.elements.allowIps.value = (f.elements.allowIps.value.trim() + '\n' + t.dataset.addIp).trim(); }
    if (t.dataset.maint === 'toggle') {
      const turnOn = !t.dataset.on;
      if (turnOn && !confirm('Turn maintenance ON? Visitors will see the maintenance page. Your owner account and any allowed IPs can still use the site.')) return;
      await saveMaint(turnOn, status);
    }
    if (t.dataset.err === 'refresh') refreshErrors();
    if (t.dataset.err === 'clear' && confirm('Clear the error log? This cannot be undone.')) { try { await post('errors/clear', {}); say(status, 'Log cleared.'); refreshErrors(); } catch (x) { say(status, x.message, true); } }
    if (t.dataset.err === 'download') {
      const lines = (host.querySelector('#sysErrList')._entries || []).map(x => `[${x.time}] ${x.level.toUpperCase()}: ${x.message}\n${x.detail}`).join('\n\n');
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([lines || 'No entries.'], { type: 'text/plain' })); a.download = 'cvtara-errors-' + new Date().toISOString().slice(0, 10) + '.txt'; a.click(); URL.revokeObjectURL(a.href);
    }
    if (t.dataset.sender === 'add') { senders = readSenders().map((x, i) => ({ ...senders[i], ...x })); senders.push({ label: 'Marketing sender', smtpPort: 587, dailyCap: 50, enabled: true, fromName: 'CVTara' }); renderSenders(); }
    if (t.dataset.sender === 'remove') { const i = Number(t.closest('form').dataset.i); if (!confirm('Remove this sender? Save senders afterwards to apply the change.')) return; senders = readSenders().map((x, k) => ({ ...senders[k], ...x })); senders.splice(i, 1); renderSenders(); }
    if (t.dataset.sender === 'save') {
      const s = host.querySelector('#sysSenderStatus'); say(s, 'Saving…');
      try { const r = await post('senders', { senders: readSenders() }); senders = r.senders; renderSenders(); renderCampaign(); say(s, 'Senders saved.'); } catch (x) { say(s, x.message, true); }
    }
    if (t.dataset.sender === 'test') {
      const i = Number(t.closest('form').dataset.i), to = prompt('Send a test email to which address?'); if (!to) return;
      const s = host.querySelector('#sysSenderStatus'); say(s, 'Sending test…');
      try { await post('senders/test', { id: senders[i].id, to }); say(s, 'Test email sent to ' + to + '.'); } catch (x) { say(s, x.message, true); }
    }
    if (t.dataset.step === 'add') { const box = host.querySelector('#sysSteps'), n = box.children.length; if (n >= 12) return; const last = readSteps().pop(); box.insertAdjacentHTML('beforeend', stepHtml({ day: (last?.day || 0) + 14, subject: '', body: '' }, n)); }
    if (t.dataset.step === 'remove') { const box = host.querySelector('#sysSteps'); if (box.children.length > 1) t.closest('fieldset').remove(); }
    if (t.dataset.lead) {
      const id = Number(t.dataset.id), isDel = t.dataset.lead === 'delete';
      if (!confirm(isDel ? 'Delete this contact?' : 'Stop all emails to this contact?')) return;
      try { await post('leads/' + t.dataset.lead, { id }); refreshLeads(); } catch (x) { alert(x.message); }
    }
  });
  host.addEventListener('submit', async e => {
    e.preventDefault();
    const f = e.target, status = f.querySelector('[role=status]'), btn = f.querySelector('[type=submit]');
    if (btn) btn.disabled = true; say(status, 'Saving…');
    try {
      if (f.id === 'sysMaintForm') { await saveMaint(host.querySelector('[data-maint=toggle]').dataset.on === '1', status); }
      if (f.id === 'sysCampaignForm') { const r = await post('campaign', { enabled: f.elements.enabled.checked, senderId: f.elements.senderId.value, dailyLimit: Number(f.elements.dailyLimit.value), steps: readSteps() }); campaign = r.config; renderCampaign(); host.querySelector('#sysCampaignForm [role=status]').textContent = 'Campaign saved.'; }
      if (f.id === 'sysLeadForm') { const r = await post('leads/add', { email: f.elements.email.value, name: f.elements.name.value, source: f.elements.source.value, basis: f.elements.basis.value, note: f.elements.note.value, confirm: f.elements.confirm.checked }); say(status, r.result === 'added' ? 'Contact added.' : 'This contact is already in your list.'); f.reset(); refreshLeads(); }
      if (f.id === 'sysImportForm') { const r = (await post('leads/import', { csv: f.elements.csv.value, source: f.elements.source.value, basis: f.elements.basis.value, note: f.elements.note.value, confirm: f.elements.confirm.checked })).result; say(status, `Added ${r.added}. Already listed ${r.exists}, unsubscribed earlier ${r.blocked}, invalid ${r.invalid}.`); f.elements.csv.value = ''; refreshLeads(); }
    } catch (x) { say(status, x.message, true); } finally { if (btn) btn.disabled = false; }
  });
  host.addEventListener('change', e => { if (e.target.id === 'sysErrLevel') refreshErrors(); if (e.target.id === 'sysLeadStatus') refreshLeads(); });
  let timer; host.addEventListener('input', e => { if (e.target.id === 'sysLeadQ') { clearTimeout(timer); timer = setTimeout(refreshLeads, 300); } });

  // Keep the page title right when this tab is opened (the shared menu handler does not know about it).
  const retitle = () => { if (document.getElementById('admin-system')?.classList.contains('active')) { const h = document.getElementById('adminTitle'); if (h) h.textContent = 'System & growth'; } };
  document.addEventListener('click', e => { if (e.target.closest('[data-admin-view="system"]')) setTimeout(retitle, 0); });
  setTimeout(retitle, 0);
  load();
})();
