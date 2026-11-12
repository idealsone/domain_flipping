/* Console: Team & security (3.37.0). Owner adds staff with a role, reads the audit log; everyone can switch on an
   authenticator app. Staff only see the parts of the menu their role allows (the server checks every call anyway). */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const VIEW = { dashboard: 'overview.view', assistant: 'owner', people: 'customers.view', orders: 'orders.view', resumelib: 'content.edit', letterlib: 'content.edit', sitedesign: 'content.edit', business: 'owner', referral: 'referrals.manage', mailer: 'growth.view', reports: 'reports.view', promos: 'growth.view', growth: 'growth.view|seo.view', tara: 'owner', campaigns: 'growth.view', outreach: 'growth.view', whatsnew: 'content.edit', interviews: 'content.edit|customers.view', partners: 'partners.manage|growth.view|referrals.manage', costs: 'orders.view|growth.view', feedback: 'support.view', speed: 'ops.view', errors: 'ops.view', bloghub: 'content.edit|seo.view', desk: 'content.edit', pricing: 'owner', settings: 'staff' };
  let ME = null;
  const can = p => !!ME && (ME.role === 'owner' || p === 'staff' || (p !== 'owner' && p.split('|').some(x => ME.permissions.includes(x))));

  K.HUB.push('team');
  K.register('team', team, ['👥', 'Team & security', 'Staff accounts and their roles, the audit log of every console change, and authenticator-app sign-in codes.']);

  async function whoami() {
    try { ME = await api('/api/admin/me'); } catch (e) { return; }
    if (ME.role === 'owner') return;
    document.body.classList.add('staff-limited');
    const css = document.createElement('style'); css.textContent = 'body.staff-limited #tbDock{display:none!important}'; document.head.appendChild(css);
    $$('.side-nav [data-admin-view]').forEach(b => { const need = VIEW[b.dataset.adminView]; if (need && !can(need)) b.hidden = true; });
    K.HUB.splice(0, K.HUB.length, 'team');
    const brand = $('.side-nav'); if (brand && !$('#staffRole')) brand.insertAdjacentHTML('afterbegin', `<p id="staffRole" class="muted" style="margin:6px 14px;font-size:12px">Signed in as <b>${E(ME.roleName)}</b></p>`);
  }
  // Other views (Growth) wait on this to hide tabs a role cannot use.
  window.CVTaraWho = whoami().then(() => ME && { role: ME.role, can });

  async function team() {
    const h = $('#admin-team'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    if (!ME) await whoami();
    const owner = ME && ME.role === 'owner';
    let T = null;
    if (owner) { try { T = await api('/api/admin/team'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; } }
    h.innerHTML = `
      <section class="panel panel-pad"><h3 style="margin-top:0">Authenticator app</h3>
        <p class="muted" style="max-width:760px">${ME && ME.authenticator ? 'On. Console sign-in asks for the 6-digit code from your authenticator app instead of an emailed code.' : 'Off. Console sign-in uses an emailed code. Switch on an authenticator app (Google Authenticator, Microsoft Authenticator or Authy) so a hacked mailbox alone cannot open the console.'}</p>
        <button class="button ${ME && ME.authenticator ? 'secondary' : 'primary'} small" data-tm="${ME && ME.authenticator ? 'totp-off' : 'totp-on'}">${ME && ME.authenticator ? 'Switch off' : 'Set up authenticator app'}</button></section>
      ${owner ? `<section class="panel panel-pad"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><h3 style="margin:0;margin-right:auto">Team</h3><button class="button primary small" data-tm="add">Add a staff member</button></div>
        <p class="muted">Staff sign in at your private console address with their own password and a sign-in code. Each role sees only its part of the console; keys, prices, deletes, roles and the business assistant stay with you.</p>
        <div class="cv-grid-wrap"><table class="ad-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Authenticator</th><th></th></tr></thead><tbody>
        ${T.owners.map(o => `<tr><td>${E(o.name)}</td><td>${E(o.email)}</td><td><b>${E(o.roleName)}</b></td><td>${o.authenticator ? 'on' : 'off'}</td><td></td></tr>`).join('')}
        ${T.staff.map(s => `<tr data-id="${s.id}"><td>${E(s.name)}</td><td>${E(s.email)}</td><td>${E(s.roleName)}</td><td>${s.authenticator ? 'on' : 'off'}</td><td style="white-space:nowrap"><button class="button secondary small" data-tm="role" data-email="${E(s.email)}" data-role="${E(s.role)}">Change role</button> <button class="button secondary small" data-tm="remove">Remove</button></td></tr>`).join('') || '<tr><td colspan="5" class="muted">No staff yet. Only you can open the console.</td></tr>'}
        </tbody></table></div>
        <details style="margin-top:10px"><summary>What each role can do</summary>${T.roles.map(r => `<p><b>${E(r.name)}</b>: ${E(r.about)}<br><span class="muted" style="font-size:13px">${r.can.map(E).join(' · ')}</span></p>`).join('')}</details></section>
      <section class="panel panel-pad"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><h3 style="margin:0;margin-right:auto">Audit log</h3><button class="button secondary small" data-tm="verify">Check the log is intact</button></div>
        <p class="muted">Every change made in the console, by whom, with settings before and after. Entries cannot be edited or deleted from CVTara, and each one is chained to the one before it.</p><div id="auditGrid"></div></section>` : ''}`;
    if (owner && K.grid) {
      K.grid($('#auditGrid'), { id: 'audit', url: '/api/admin/audit', rowsKey: 'rows', search: 'Action, account, reference', sort: 'at',
        row: r => `data-detail="${E(JSON.stringify({ before: r.before_json, after: r.after_json, reason: r.reason, ip: r.ip }))}"`,
        columns: [{ key: 'at', label: 'When (UTC)', sort: 'at' }, { label: 'Who', html: r => E((r.actor || 'system') + (r.actor_role ? ' (' + r.actor_role + ')' : '')) }, { label: 'What', html: r => E(String(r.action).replace(/^console:/, '')) }, { key: 'target', label: 'Target' }, { label: 'Result', html: r => r.status >= 400 ? `<span class="pill st-failed">${E(r.status)}</span>` : 'done' }, { label: 'Reference', html: r => `<code>${E(r.request_id)}</code>` }, { label: '', html: () => '<button class="button secondary small" data-tm="detail">Details</button>' }] });
    }
    h.onclick = async e => {
      const b = e.target.closest('[data-tm]'); if (!b) return;
      const k = b.dataset.tm;
      try {
        if (k === 'totp-on') return totpSetup();
        if (k === 'totp-off') { if (!(await ensureRecent())) return; await api('/api/security/totp/disable', { method: 'POST', body: {} }); ME.authenticator = false; toast('Authenticator app switched off. Sign-in uses emailed codes again.'); return team(); }
        if (k === 'add' || k === 'role') return staffForm(b.dataset.email || '', b.dataset.role || 'support_agent', T.roles);
        if (k === 'remove') { const id = +b.closest('tr').dataset.id; if (!confirm('Remove this person from the console? Their customer account stays.')) return; if (!(await ensureRecent())) return; await api('/api/admin/team/remove', { method: 'POST', body: { userId: id } }); toast('Removed from the team.'); return team(); }
        if (k === 'verify') { const r = await api('/api/admin/audit/verify', { method: 'POST', body: {} }); toast(r.ok ? `The audit log is intact (${r.rows} entries).` : `The audit log was changed outside CVTara at entry ${r.brokenAt}. See the Error Log.`); return; }
        if (k === 'detail') { const d = JSON.parse(b.closest('tr').dataset.detail || '{}'); const j = x => { try { return JSON.parse(x || 'null'); } catch (_) { return x; } }; modal('Audit entry', `<pre style="white-space:pre-wrap;font-size:12px">${E(JSON.stringify({ before: j(d.before), after: j(d.after), reason: d.reason, ip: d.ip }, null, 2))}</pre>`, { wide: true }); }
      } catch (x) { toast(err(x)); }
    };
  }

  function staffForm(email, role, roles) {
    const m = modal(email ? 'Change role' : 'Add a staff member', `<form id="tmForm" class="ops-form">
      <label>Email<input name="email" type="email" required value="${E(email)}" ${email ? 'readonly' : ''}></label>
      ${email ? '' : '<label>Name<input name="name" maxlength="120"></label>'}
      <label>Role<select name="role">${roles.map(r => `<option value="${E(r.key)}" ${r.key === role ? 'selected' : ''}>${E(r.name)}</option>`).join('')}</select></label>
      <p class="muted" style="font-size:13px">A new person gets an email with a link to set their own password. Their sign-ins end now so the role applies at once.</p>
      <p role="alert" id="tmErr" style="color:var(--danger)"></p><button class="button primary" type="submit">Save</button></form>`);
    $('#tmForm', m.el).onsubmit = async ev => {
      ev.preventDefault(); const f = ev.target;
      try { if (!(await ensureRecent())) return; const r = await api('/api/admin/team/save', { method: 'POST', body: { email: f.email.value.trim(), name: f.name ? f.name.value.trim() : '', role: f.role.value } }); m.close(); toast(r.invited ? 'Added. They will get an email to set their password.' : 'Role saved.'); team(); } catch (x) { $('#tmErr', m.el).textContent = err(x); }
    };
  }

  async function totpSetup() {
    let s; try { s = await api('/api/security/totp/setup', { method: 'POST', body: {} }); } catch (x) { toast(err(x)); return; }
    const m = modal('Set up an authenticator app', `<ol style="padding-left:18px"><li>Open your authenticator app and choose <b>Add account → Enter a setup key</b>.</li><li>Account: <b>CVTara console</b>. Key: <code style="font-size:15px">${E(s.secret)}</code> (time based).</li><li>Type the 6-digit code the app shows.</li></ol>
      <form id="tfForm" class="ops-form"><label>Code<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label><p role="alert" id="tfErr" style="color:var(--danger)"></p><button class="button primary" type="submit">Switch on</button></form>
      <p class="muted" style="font-size:12px">Keep the key somewhere safe: it is the only way to add the same account to a new phone. If you lose the phone, the emergency switch OWNER_LOGIN_CODE=off in the server settings lets you sign in with your password.</p>`);
    $('#tfForm', m.el).onsubmit = async ev => {
      ev.preventDefault();
      try { await api('/api/security/totp/enable', { method: 'POST', body: { code: ev.target.code.value.trim() } }); m.close(); ME.authenticator = true; toast('Authenticator app is on. Next sign-in asks for its code.'); team(); } catch (x) { $('#tfErr', m.el).textContent = err(x); }
    };
  }
})();
