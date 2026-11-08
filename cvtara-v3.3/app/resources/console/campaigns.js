/* Owner console: Email campaigns — write one email, choose who gets it (customers who said yes, or your imported contacts), test it, then send now or at a set time in small batches. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const post = async (path, body, safe) => { if (!safe && !(await ensureRecent())) throw new Error('Please confirm your identity to continue.'); return api(path, { method: 'POST', body }); };
  const STATUS = { draft: ['Draft', ''], scheduled: ['Scheduled', 'mid'], sending: ['Sending', 'ok'], paused: ['Paused', 'mid'], done: ['Finished', 'ok'], cancelled: ['Cancelled', 'bad'] };
  const when = s => s ? new Date(s + ':00+05:30').toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';
  const n = x => Number(x || 0).toLocaleString('en-IN');
  const SAMPLE = '# Hello {{name}}\n\nWrite your message here. Keep it short and useful.\n\n- One clear point\n- Another clear point\n\n[button: Open CVTara | https://cvtara.com/]';
  let S = null; const C = { q: '', page: 1 };

  async function load() { S = await api('/api/admin/campaigns'); return S; }

  async function campaigns() {
    const h = $('#admin-campaigns'); if (!h) return;
    if (!S) h.innerHTML = '<div class="ops-skel"></div>';
    try { await load(); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const aud = k => (S.audiences.find(a => a.key === k) || {}).label || k;
    h.innerHTML = `<p class="muted" style="margin:0 0 12px;max-width:780px">Send your own email to many people at once, separate from the automatic welcome, receipt and reminder emails. Only people who said yes to emails are included, and every email has an unsubscribe link. Emails go out in small batches through your normal email settings.</p>
      ${S.emailOn ? '' : '<div class="panel panel-pad" role="alert" style="border-color:var(--danger)"><b>Email delivery is switched off.</b> Campaign emails wait in the queue until you switch email on in Settings → Integrations.</div>'}
      <div class="ops-kpis"><div class="kpi"><span>Customers who said yes</span><b>${n(S.counts.customers_all)}</b></div><div class="kpi"><span>Imported contacts you can email</span><b>${n(S.counts.contacts)}</b></div><div class="kpi"><span>Campaign emails today</span><b>${n(S.sentToday)} / ${n(S.settings.dailyCap)}</b></div><div class="kpi"><span>Do-not-mail list</span><b>${n(S.doNotMail)}</b></div></div>
      <section class="panel panel-pad"><div class="admin-heading"><div><h3>Your campaigns</h3><p class="muted" style="margin:0">Counts come from the real mail queue. “Waiting” means not sent yet.</p></div><button type="button" class="button primary" data-new>New campaign</button></div>
        ${S.campaigns.length ? `<div style="overflow:auto"><table class="ad-table"><thead><tr><th>Campaign</th><th>Who gets it</th><th>Status</th><th>Waiting</th><th>Sent</th><th>Failed</th><th>Not sent</th><th>Unsubscribed</th><th></th></tr></thead><tbody>${S.campaigns.map(c => { const st = STATUS[c.status] || [c.status, ''], s = c.stats; return `<tr data-id="${E(c.id)}">
          <td><b>${E(c.name)}</b><br><small>${E(c.subject)}</small></td><td>${E(aud(c.audience))}</td>
          <td><span class="ad-pill ${st[1]}">${E(st[0])}</span><br><small>${c.status === 'scheduled' || (c.status === 'paused' && !c.startedAt && c.scheduledAt) ? 'for ' + E(when(c.scheduledAt)) : c.startedAt ? 'started ' + E(when(c.startedAt)) : ''}</small></td>
          <td>${n(s.waiting)}</td><td>${n(s.sent)}</td><td>${n(s.failed)}</td><td>${n(s.notSent)}</td><td>${n(s.unsubscribed)}</td>
          <td style="white-space:nowrap">${!c.startedAt && ['draft', 'scheduled', 'paused'].includes(c.status) ? '<button class="button secondary small" data-act="edit">Edit &amp; send</button> ' : ''}${['scheduled', 'sending'].includes(c.status) ? '<button class="button secondary small" data-act="pause">Pause</button> ' : ''}${c.status === 'paused' ? '<button class="button secondary small" data-act="resume">Resume</button> ' : ''}${['scheduled', 'sending', 'paused'].includes(c.status) ? '<button class="button secondary small" data-act="cancel">Cancel</button> ' : ''}<button class="button secondary small" data-act="copy">Copy</button>${!c.startedAt || ['done', 'cancelled'].includes(c.status) ? ' <button class="button secondary small" data-act="del">Delete</button>' : ''}</td></tr>`; }).join('')}</tbody></table></div>` : '<p class="muted">No campaigns yet. Start with <b>New campaign</b>.</p>'}</section>
      <section class="panel panel-pad"><h3>Imported contacts</h3><p class="muted" style="margin:0 0 10px;max-width:780px">Add people who are not customers yet, for example from a job fair sign-up sheet. Paste lines like <code>email, name</code> or upload a CSV file. Bad addresses and repeats are removed. People who already have a CVTara account are reached through the customer groups instead, so their own email choice is respected.</p>
        <form id="cmImport" class="ops-form"><label class="wide">Where these contacts came from (only you see this)<input name="source" maxlength="120" placeholder="For example: Campus job fair, Oct 2026"></label>
          <label class="wide">Email addresses (one per line, name optional)<textarea name="csv" rows="6" placeholder="asha@example.com, Asha Rao&#10;ravi@example.com"></textarea></label>
          <label class="wide">Or upload a CSV file<input type="file" name="file" accept=".csv,.txt,text/csv,text/plain"></label>
          <label class="wide" style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="confirm" style="width:auto;margin-top:3px"><span>I confirm that everyone on this list agreed to receive emails from CVTara.</span></label>
          <button class="button primary" type="submit">Import contacts</button></form><p role="status" id="cmImpMsg"></p>
        <div class="ops-filterbox" style="margin-top:12px"><label class="ops-f grow">Search contacts<input id="cmQ" type="search" value="${E(C.q)}" placeholder="Email, name or where they came from…"></label></div>
        <div style="overflow:auto"><table class="ad-table" id="cmContacts"><tbody><tr><td>Loading…</td></tr></tbody></table></div><div class="ops-pager" id="cmPager"></div></section>
      <section class="panel panel-pad"><h3>Do-not-mail list</h3><p class="muted" style="margin:0 0 10px">Addresses here never get a campaign email. People who unsubscribe from a campaign are added automatically. ${n(S.doNotMail)} address${S.doNotMail === 1 ? '' : 'es'} on the list.</p>
        <form id="cmSup" class="ops-form"><label class="wide">Add addresses (one per line)<textarea name="emails" rows="3"></textarea></label><button class="button secondary" type="submit">Add to do-not-mail list</button></form></section>
      <section class="panel panel-pad"><h3>Sending limits</h3><p class="muted" style="margin:0 0 10px;max-width:780px">Shared hosting email can be blocked as spam when too many emails go out at once. Campaign emails are handed to the mailer a small batch each minute, and stop for the day at the daily limit. They continue the next day by themselves. Suggested: ${n(S.defaults.batchSize)} a minute and ${n(S.defaults.dailyCap)} a day; raise them only if your email provider allows more. The mailbox limit covers every email from your sending address in 24 hours (Hostinger Business Starter: 1,000). Tips and campaigns stop ${n(100)} short of it, so sign-up codes and receipts always go out.</p>
        <form id="cmLim" class="ops-form"><label>Emails per minute<input name="batchSize" type="number" min="1" max="100" value="${S.settings.batchSize}"></label><label>Emails per day<input name="dailyCap" type="number" min="1" max="10000" value="${S.settings.dailyCap}"></label><label>Mailbox limit per 24 hours<input name="mailboxCap" type="number" min="150" max="100000" value="${S.settings.mailboxCap || 1000}"></label><button class="button secondary" type="submit">Save limits</button></form></section>
      <p role="status" id="cmMsg"></p>`;
    const say = m => { $('#cmMsg', h).textContent = m; };
    h.querySelector('[data-new]').onclick = () => edit(null);
    $('#cmLim', h).onsubmit = async e => { e.preventDefault(); const f = e.target; try { await post('/api/admin/campaigns/settings', { batchSize: +f.batchSize.value, dailyCap: +f.dailyCap.value, mailboxCap: +f.mailboxCap.value }); toast('Limits saved'); campaigns(); } catch (x) { say(err(x)); } };
    $('#cmSup', h).onsubmit = async e => { e.preventDefault(); try { const r = await post('/api/admin/campaigns/suppress', { emails: e.target.emails.value }, true); toast(r.added + ' added to the do-not-mail list'); campaigns(); } catch (x) { say(err(x)); } };
    const imp = $('#cmImport', h);
    imp.file.onchange = () => { const fl = imp.file.files[0]; if (!fl) return; if (fl.size > 2000000) { toast('That file is larger than 2 MB. Split it into smaller files.', true); imp.file.value = ''; return; } const rd = new FileReader(); rd.onload = () => { imp.csv.value = String(rd.result || ''); }; rd.readAsText(fl); };
    imp.onsubmit = async e => {
      e.preventDefault(); const out = $('#cmImpMsg', h);
      if (!imp.confirm.checked) { out.textContent = 'Tick the box to confirm these people agreed to hear from CVTara.'; return; }
      if (!imp.csv.value.trim()) { out.textContent = 'Paste some addresses or choose a file first.'; return; }
      try {
        const r = (await post('/api/admin/campaigns/contacts/import', { csv: imp.csv.value, source: imp.source.value.trim(), confirm: true })).result;
        const parts = [`${n(r.added)} added`]; if (r.alreadyListed) parts.push(`${n(r.alreadyListed)} already on your list`); if (r.doNotMail) parts.push(`${n(r.doNotMail)} on the do-not-mail list (skipped)`); if (r.haveAccount) parts.push(`${n(r.haveAccount)} already have a CVTara account (reached through the customer groups)`); if (r.invalid) parts.push(`${n(r.invalid)} not valid: ${r.invalidSamples.join(', ')}`);
        toast(r.added + ' contacts added'); await campaigns(); $('#cmImpMsg', h).textContent = parts.join(' · ') + '.';
      } catch (x) { out.textContent = err(x); }
    };
    let t; $('#cmQ', h).oninput = e => { C.q = e.target.value; C.page = 1; clearTimeout(t); t = setTimeout(() => contacts(h), 300); };
    contacts(h);
    h.onclick = async e => {
      const b = e.target.closest('[data-act]'); if (!b || !b.closest('tr[data-id]')) return;
      const id = b.closest('tr[data-id]').dataset.id, c = S.campaigns.find(x => x.id === id);
      try {
        if (b.dataset.act === 'edit') return edit(c);
        if (b.dataset.act === 'copy') return edit({ ...c, id: '', name: c.name + ' (copy)', status: 'draft', startedAt: null });
        if (b.dataset.act === 'pause') { await post('/api/admin/campaigns/pause', { id }, true); toast('Paused. Emails not yet sent are held back.'); }
        if (b.dataset.act === 'resume') { await post('/api/admin/campaigns/resume', { id }); toast('Resumed'); }
        if (b.dataset.act === 'cancel') { if (!confirm('Cancel this campaign? Emails not yet sent will not go out. This cannot be undone.')) return; await post('/api/admin/campaigns/cancel', { id }, true); toast('Cancelled'); }
        if (b.dataset.act === 'del') { if (!confirm('Delete this campaign and its report?')) return; await post('/api/admin/campaigns/delete', { id }, true); toast('Deleted'); }
        campaigns();
      } catch (x) { say(err(x)); toast(err(x), true); }
    };
  }

  async function contacts(h) {
    const tbl = $('#cmContacts', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(C.page) }); if (C.q) qs.set('q', C.q);
      const d = await api('/api/admin/campaigns/contacts?' + qs);
      tbl.innerHTML = d.contacts.length ? `<thead><tr><th>Email</th><th>Name</th><th>Came from</th><th>Added</th><th>Status</th><th></th></tr></thead><tbody>${d.contacts.map(c => `<tr data-cid="${c.id}"><td>${E(c.email)}</td><td>${E(c.name || '—')}</td><td>${E(c.source || '—')}</td><td>${E(K.dmy(K.parse(c.created_at)))}</td><td>${c.unsubscribed_at ? '<span class="ad-pill bad">Unsubscribed</span>' : '<span class="ad-pill ok">Can be emailed</span>'}</td><td><button class="button secondary small" data-cdel>Remove</button></td></tr>`).join('')}</tbody>` : '<tbody><tr><td>No contacts yet.</td></tr></tbody>';
      const pages = Math.max(1, Math.ceil(d.total / d.per));
      $('#cmPager', h).innerHTML = d.total ? `<span>${n(d.total)} contact${d.total === 1 ? '' : 's'} · page ${d.page} of ${pages}</span> <button class="button secondary small" data-pg="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>← Prev</button> <button class="button secondary small" data-pg="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>Next →</button>` : '';
      $('#cmPager', h).onclick = e => { const b = e.target.closest('[data-pg]'); if (b && !b.disabled) { C.page = +b.dataset.pg; contacts(h); } };
      tbl.onclick = async e => { const b = e.target.closest('[data-cdel]'); if (!b) return; e.stopPropagation(); if (!confirm('Remove this contact from your list?')) return; try { await post('/api/admin/campaigns/contacts/delete', { id: +b.closest('tr').dataset.cid }, true); toast('Removed'); contacts(h); } catch (x) { toast(err(x), true); } };
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  function edit(c) {
    const f = c || { id: '', name: '', subject: '', body: SAMPLE, audience: 'customers_all', status: 'draft' };
    const soon = new Date(Date.now() + 5.5 * 3600e3 + 3600e3).toISOString().slice(0, 13) + ':00';
    const m = modal(c && c.id ? 'Edit campaign' : 'New campaign', `<form id="cmForm" class="ops-form">
      <label class="wide">Campaign name (only you see this)<input name="name" maxlength="120" required value="${E(f.name)}"></label>
      <label class="wide">Who gets it<select name="audience">${S.audiences.map(a => `<option value="${E(a.key)}" ${a.key === f.audience ? 'selected' : ''}>${E(a.label)} (${n(S.counts[a.key])})</option>`).join('')}</select></label>
      <p class="muted wide" id="cmCount" style="margin:0"></p>
      <label class="wide">Subject line<input name="subject" maxlength="180" required value="${E(f.subject)}"></label>
      <label class="wide">Message<textarea name="body" rows="12" maxlength="20000" required>${E(f.body)}</textarea></label>
      <p class="muted wide" style="margin:0;font-size:12px">Same format as the other emails: <code># Heading</code> on the first line, a blank line between paragraphs, <code>- </code> for a list, <code>## Sub-heading</code>, and <code>[button: Label | https://link]</code> on its own line. Type <code>{{name}}</code> for the person's first name. The signature and unsubscribe link are added for you.</p>
      <div class="wide" style="display:flex;gap:8px;flex-wrap:wrap"><button class="button secondary" type="submit">Save draft</button><button class="button secondary" type="button" data-prev>Preview</button><button class="button secondary" type="button" data-test>Send a test to me</button></div>
      <fieldset class="wide"><legend>Send</legend>
        <label style="display:flex;gap:8px;align-items:center"><input type="radio" name="when" value="now" checked style="width:auto"> Start sending now</label>
        <label style="display:flex;gap:8px;align-items:center"><input type="radio" name="when" value="schedule" style="width:auto" ${f.status === 'scheduled' ? 'checked' : ''}> Send at a set time (India time) <input type="datetime-local" name="at" value="${E(f.scheduledAt || soon)}" style="width:auto"></label>
        <button class="button primary" type="button" data-send style="margin-top:8px">Send</button></fieldset>
      <p role="status" id="cmEdMsg" class="wide"></p></form>`, { wide: true });
    const fm = $('#cmForm', m.el), say = x => { $('#cmEdMsg', m.el).textContent = x; };
    let id = f.id || '';
    const body = () => ({ id: id || null, name: fm.name.value.trim(), audience: fm.audience.value, subject: fm.subject.value.trim(), body: fm.body.value });
    const count = async () => { try { const r = await api('/api/admin/campaigns/count?audience=' + encodeURIComponent(fm.audience.value)); $('#cmCount', m.el).textContent = r.count ? `${n(r.count)} ${r.count === 1 ? 'person' : 'people'} will get this email right now. People who unsubscribe before their email goes out are skipped.` : 'Nobody in this group can be emailed yet.'; fm.querySelector('[data-send]').textContent = r.count ? `Send to ${n(r.count)} ${r.count === 1 ? 'person' : 'people'}` : 'Send'; } catch (x) { say(err(x)); } };
    fm.audience.onchange = count; count();
    const save = async () => { if (!fm.reportValidity()) throw new Error('Fill in the name, subject and message first.'); const r = await post('/api/admin/campaigns/save', body(), true); id = r.id; S = r; return r; };
    fm.onsubmit = async e => { e.preventDefault(); try { await save(); toast('Draft saved'); say('Saved.'); campaigns(); } catch (x) { say(err(x)); } };
    fm.querySelector('[data-prev]').onclick = async () => {
      try { const r = await post('/api/admin/campaigns/preview', { subject: fm.subject.value || '(no subject)', body: fm.body.value || ' ' }, true); const p = modal('Preview: ' + r.subject, '<iframe title="Email preview" sandbox="" style="width:100%;height:70vh;border:1px solid var(--line, #e2e8f0);border-radius:10px;background:#fff"></iframe>', { wide: true }); p.el.querySelector('iframe').srcdoc = r.html; } catch (x) { say(err(x)); }
    };
    fm.querySelector('[data-test]').onclick = async () => { try { const r = await post('/api/admin/campaigns/test', { subject: fm.subject.value, body: fm.body.value }, true); say(`Test sent to ${r.to}. It arrives within a minute or two.${r.emailOn ? '' : ' Email delivery is off, so it waits in the queue until you switch it on.'}`); } catch (x) { say(err(x)); } };
    fm.querySelector('[data-send]').onclick = async () => {
      try {
        const w = fm.querySelector('[name="when"]:checked').value;
        if (w === 'schedule' && !fm.at.value) throw new Error('Choose the date and time.');
        if (!confirm(w === 'now' ? 'Start sending this campaign now? This cannot be undone, but you can pause or cancel it while it is sending.' : 'Schedule this campaign for ' + when(fm.at.value) + '?')) return;
        await save();
        await post('/api/admin/campaigns/send', { id, when: w, at: w === 'schedule' ? fm.at.value : null });
        toast(w === 'now' ? 'Sending has started. The first batch goes out within a minute.' : 'Scheduled'); m.close(); campaigns();
      } catch (x) { say(err(x)); }
    };
  }

  K.register('campaigns', campaigns, ['📣', 'Email campaigns', 'Your own one-off emails to customers who said yes, or to an imported contact list.']);
})();
