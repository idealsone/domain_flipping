/* Owner console: Support Inbox — feedback, bug reports and screenshots sent from the "Feedback" button on the site. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, parse, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const F = { status: 'open', area: 'all', kind: 'all', q: '', page: 1, mine: false, priority: 'all', needs: false };
  const SORT = { customer: ['Customer', 'mid'], sales: ['Sales pitch', 'ok'], automated: ['Automatic mail', 'ok'], spam: ['Spam', 'ok'] };
  const PRI = { urgent: ['Urgent', 'bad'], high: ['High', 'bad'], normal: ['Normal', 'mid'], low: ['Low', 'ok'] };
  K.openSupport = q => { Object.assign(F, { q, status: 'all', page: 1 }); K.open('feedback'); }; // from Ctrl+K search
  const STATUS = { new: 'New', seen: 'Seen', working: 'In progress', resolved: 'Resolved', closed: 'Closed' };
  const tone = s => s === 'new' ? 'bad' : s === 'resolved' || s === 'closed' ? 'ok' : 'mid';
  const when = s => { const d = parse(s); return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }); };
  let META = null;

  async function feedback() {
    const h = $('#admin-feedback'); if (!h) return;
    if (!META) h.innerHTML = '<div class="ops-skel"></div>';
    let sum;
    try { sum = await api('/api/admin/support/summary?days=30'); META = { areas: sum.areas, kinds: sum.kinds }; } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const b = sum.byStatus || {}, open = (b.new || 0) + (b.seen || 0) + (b.working || 0), top = Object.entries(sum.recent.byArea || {}).sort((x, y) => y[1] - x[1])[0];
    const nav = document.querySelector('[data-admin-view="feedback"] .nav-count'); if (nav) nav.textContent = b.new ? String(b.new) : '';
    const opt = (v, l, cur) => `<option value="${E(v)}" ${String(cur) === String(v) ? 'selected' : ''}>${E(l)}</option>`;
    h.innerHTML = `<div class="pg-head"><h2>Support Inbox</h2><p>Everything customers and visitors send from the Feedback button and the contact page. Each ticket has a reference, a conversation with the customer (they read replies under Feedback → My requests and get an email), internal notes, a priority and an owner on the team.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><button class="button primary small" id="fbNew">Open a ticket for a customer</button><button class="button secondary small" id="fbSess">Customer view log</button></div></div>
      <div class="ops-kpis"><div class="kpi"><span>New</span><b>${b.new || 0}</b></div><div class="kpi"><span>Open (new, seen, in progress)</span><b>${open}</b></div><div class="kpi"><span>Last 30 days</span><b>${sum.recent.total}</b></div><div class="kpi"><span>Average rating</span><b>${sum.recent.avgRating ?? '—'}</b></div><div class="kpi"><span>Most reported area</span><b style="font-size:15px">${top ? E(META.areas[top[0]] || top[0]) + ' · ' + top[1] : '—'}</b></div></div>
      <section class="panel panel-pad" id="fbInbox" data-nokit><p class="muted" style="margin:0">Checking the support mailbox…</p></section>
      <section class="panel panel-pad" data-nokit><div class="ops-filterbox">
        <label class="ops-f grow">Search<input id="fbq" type="search" value="${E(F.q)}" placeholder="Message, name, email or page…"></label>
        <label class="ops-f">Status<select id="fbs">${opt('open', 'Open (not finished)', F.status)}${opt('all', 'Everything', F.status)}${Object.entries(STATUS).map(([k, l]) => opt(k, l, F.status)).join('')}</select></label>
        <label class="ops-f">About<select id="fba">${opt('all', 'Everything', F.area)}${Object.entries(META.areas).map(([k, l]) => opt(k, l, F.area)).join('')}</select></label>
        <label class="ops-f">Priority<select id="fbpr">${opt('all', 'Any', F.priority)}${Object.entries(PRI).map(([k, l]) => opt(k, l[0], F.priority)).join('')}</select></label>
        <label class="ops-f"><span>Mine only</span><input id="fbm" type="checkbox" ${F.mine ? 'checked' : ''}></label>
        <label class="ops-f"><span>Needs a reply</span><input id="fbneed" type="checkbox" ${F.needs ? 'checked' : ''}></label>
        <label class="ops-f">Type<select id="fbk">${opt('all', 'Everything', F.kind)}${Object.entries(META.kinds).map(([k, l]) => opt(k, l, F.kind)).join('')}</select></label>
        ${K.rangeHtml(F.from, F.to)}<button type="button" class="button secondary" id="fbr">Refresh</button> ${K.dlHtml()}</div></section>
      <section class="panel" data-nokit style="overflow:auto"><table class="ad-table" id="fbt"><tbody><tr><td>Loading…</td></tr></tbody></table></section><div class="ops-pager" id="fbp"></div>`;
    const reload = () => { F.page = 1; list(h); };
    let t; $('#fbq', h).oninput = e => { F.q = e.target.value; clearTimeout(t); t = setTimeout(reload, 300); };
    $('#fbs', h).onchange = e => { F.status = e.target.value; reload(); };
    const bar = $('.ops-filterbox', h);
    bar.addEventListener('change', e => { const rg = K.rangeChange(e, bar); if (rg) { [F.from, F.to] = rg; reload(); } });
    K.wireDownload(bar, fmt => K.exportUrl('/api/admin/support', { q: F.q, status: F.status, area: F.area, kind: F.kind, from: F.from, to: F.to }, fmt));
    $('#fba', h).onchange = e => { F.area = e.target.value; reload(); };
    $('#fbk', h).onchange = e => { F.kind = e.target.value; reload(); };
    $('#fbr', h).onclick = () => feedback();
    $('#fbpr', h).onchange = e => { F.priority = e.target.value; reload(); };
    $('#fbm', h).onchange = e => { F.mine = e.target.checked; reload(); };
    $('#fbneed', h).onchange = e => { F.needs = e.target.checked; reload(); };
    inbox($('#fbInbox', h), h);
    $('#fbNew', h).onclick = () => newTicket();
    $('#fbSess', h).onclick = () => sessions();
    list(h);
  }

  async function list(h) {
    const tbl = $('#fbt', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(F.page), per: String(F.per || 20) }); if (F.q) qs.set('q', F.q); if (F.status !== 'all') qs.set('status', F.status); if (F.area !== 'all') qs.set('area', F.area); if (F.kind !== 'all') qs.set('kind', F.kind); if (F.priority !== 'all') qs.set('priority', F.priority); if (F.mine) qs.set('mine', '1'); if (F.needs) qs.set('needs', 'reply'); if (F.from) qs.set('from', F.from); if (F.to) qs.set('to', F.to);
      const d = await api('/api/admin/support?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Ticket</th><th>Received</th><th>From</th><th>About</th><th>Message</th><th>Status</th></tr></thead><tbody>${d.rows.map(r => `<tr data-id="${E(r.id)}" class="fb-row" tabindex="0" style="cursor:pointer">
        <td><code>${E(r.reference || '')}</code>${r.priority && r.priority !== 'normal' ? `<br><span class="ad-pill ${PRI[r.priority]?.[1] || ''}">${E(PRI[r.priority]?.[0] || r.priority)}</span>` : ''}</td><td>${E(when(r.created_at))}</td><td><b>${E(r.name || 'Visitor')}</b><br><small>${E(r.email || 'no email')} · ${E(r.plan || '')}</small></td>
        <td>${E(META.areas[r.area] || r.area)}<br><small>${E(META.kinds[r.kind] || r.kind)}${r.rating ? ' · ' + '★'.repeat(r.rating) : ''}</small>${sortPill(r)}</td>
        <td style="max-width:420px">${E(r.message)}${r.images ? `<br><small>📎 ${r.images} screenshot${r.images > 1 ? 's' : ''}</small>` : ''}</td>
        <td><span class="ad-pill ${tone(r.status)}">${E(STATUS[r.status] || r.status)}</span></td></tr>`).join('')}</tbody>` : '<tbody><tr><td>No reports match these filters.</td></tr></tbody>';
      K.pager($('#fbp', h), d, (p, per) => { F.page = p; F.per = per; list(h); });
      tbl.onclick = e => { const tr = e.target.closest('.fb-row'); if (tr) detail(tr.dataset.id, h); };
      tbl.onkeydown = e => { if (e.key === 'Enter') { const tr = e.target.closest('.fb-row'); if (tr) detail(tr.dataset.id, h); } };
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  async function detail(id, h) {
    let d;
    try { d = await api('/api/admin/support/' + id); } catch (e) { toast(err(e), true); return; }
    const r = d.report, areas = META.areas, kinds = META.kinds;
    const m = modal(`${areas[r.area] || r.area} — ${kinds[r.kind] || r.kind}`, `
      <dl class="mail-dl" style="display:grid;grid-template-columns:120px 1fr;gap:6px 12px;margin:0 0 12px">
        <dt>From</dt><dd>${E(r.name || 'Visitor')}${r.email ? ` · <a href="mailto:${E(r.email)}?subject=${encodeURIComponent('Your CVTara feedback')}&body=${encodeURIComponent('Hello' + (r.name ? ' ' + r.name : '') + ',\n\nThank you for your feedback:\n\n"' + r.message.slice(0, 300) + '"\n\n')}">${E(r.email)}</a>` : ' (no email left)'}</dd>
        <dt>Plan</dt><dd>${E(r.plan || '—')}${d.earlierFromSamePerson ? ` · ${d.earlierFromSamePerson} earlier report${d.earlierFromSamePerson > 1 ? 's' : ''}` : ''}</dd>
        <dt>Page</dt><dd>${E(r.page || '—')}</dd><dt>Screen</dt><dd>${E(r.viewport || '—')}</dd><dt>Browser</dt><dd style="word-break:break-word">${E(r.user_agent || '—')}</dd>
        <dt>Rating</dt><dd>${r.rating ? '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating) : '—'}</dd><dt>Received</dt><dd>${E(when(r.created_at))}</dd></dl>
      <h4 style="margin:0 0 6px">Message</h4><p style="white-space:pre-wrap;margin:0 0 14px;line-height:1.55">${E(r.message)}</p>
      ${d.images.length ? `<h4 style="margin:0 0 6px">Screenshots (${d.images.length})</h4><div class="fb-shots" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin-bottom:14px">${d.images.map(i => `<a href="/api/admin/support/file/${E(i.id)}" target="_blank" rel="noopener"><img src="/api/admin/support/file/${E(i.id)}" alt="Screenshot ${i.width}×${i.height}" loading="lazy" style="width:100%;height:110px;object-fit:cover;border:1px solid var(--line);border-radius:8px"></a>`).join('')}</div>` : ''}
      ${d.errors && d.errors.length ? `<h4 style="margin:0 0 6px">Errors for this person around that time</h4><ul style="margin:0 0 14px;padding-left:18px">${d.errors.map(x => `<li><small>${E(when(x.last_seen))} · ${E(x.level)} · ×${x.occurrences}</small><br>${E(x.message)}</li>`).join('')}</ul>` : ''}
      ${r.triage ? `<div style="padding:10px 12px;border-radius:10px;border:1px solid var(--line);margin:0 0 14px;background:${r.reply_needed ? 'rgba(37,99,235,.07)' : 'rgba(16,185,129,.08)'}"><b>${r.reply_needed ? 'Reply needed' : 'No reply needed'}</b> · ${E((SORT[r.triage] || [r.triage])[0])}${r.triage_reason ? `<br><small>${E(r.triage_reason)}</small>` : ''}
        <div style="margin-top:6px"><button type="button" class="button secondary small" data-sort="${r.reply_needed ? '0' : '1'}">${r.reply_needed ? 'Not needed: close it' : 'It needs a reply: reopen'}</button></div></div>` : ''}
      <h4 style="margin:0 0 6px">Conversation</h4>
      <div id="fbConv" style="display:grid;gap:8px;margin-bottom:12px">${conv(d.messages)}</div>
      ${d.canReply ? `<form id="fbReply" class="ops-form" style="display:grid;gap:8px;margin-bottom:14px"><label>Reply to ${E(r.email || 'the customer')} (emailed at once from the support address, signed CVTara team) or add an internal note<textarea name="body" rows="${r.draft ? 9 : 4}" maxlength="5000" required>${E(r.draft || '')}</textarea></label>
        ${r.draft ? '<small class="muted" id="fbDraftNote">A suggested reply is filled in. Read it, change anything, then send.</small>' : ''}
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" type="submit" data-act="reply">Send reply</button>${d.assist ? `<button class="button secondary" type="button" data-suggest>${r.draft ? 'Suggest another reply' : 'Suggest a reply'}</button>` : ''}<button class="button secondary" type="button" data-act="note">Save as internal note</button></div></form>` : '<p class="muted">This message has no email address, so it cannot be answered.</p>'}
      <form id="fbForm" class="ops-form" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;align-items:end">
        <label>Status<select name="status">${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${k === r.status ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label>Priority<select name="priority">${Object.entries(PRI).map(([k, l]) => `<option value="${k}" ${k === (r.priority || 'normal') ? 'selected' : ''}>${l[0]}</option>`).join('')}</select></label>
        <label>Handled by<select name="assignedTo"><option value="">Nobody yet</option></select></label>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" type="submit">Save</button>${d.canDelete ? '<button class="button secondary" type="button" data-del>Delete</button>' : ''}</div></form>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">${r.owner_id ? '<button class="button secondary small" type="button" data-prof>Support profile</button>' : ''}${d.canViewAs ? '<button class="button secondary small" type="button" data-viewas>View their account (read-only, 30 min)</button>' : ''}</div>
      <p role="status" id="fbMsg"></p>`, { wide: true });
    const f = $('#fbForm', m.el), msg = t => { $('#fbMsg', m.el).textContent = t; };
    api('/api/admin/support/team').then(t => { const sel = f.assignedTo; sel.innerHTML = '<option value="">Nobody yet</option>' + t.people.map(p => `<option value="${p.id}" ${p.id === r.assigned_to ? 'selected' : ''}>${E(p.name)}</option>`).join(''); }).catch(() => { f.assignedTo.closest('label').hidden = true; });
    f.onsubmit = async e => { e.preventDefault(); try { const body = { action: 'update', status: f.status.value, priority: f.priority.value }; if (!f.assignedTo.closest('label').hidden) body.assignedTo = f.assignedTo.value ? +f.assignedTo.value : null; await api('/api/admin/support/' + id, { method: 'POST', body }); toast('Saved'); m.close(); feedback(); } catch (x) { msg(err(x)); } };
    const del = f.querySelector('[data-del]'); if (del) del.onclick = async () => { if (!confirm('Delete this ticket, its conversation and screenshots? This cannot be undone.')) return; try { await api('/api/admin/support/' + id, { method: 'POST', body: { action: 'delete' } }); toast('Deleted'); m.close(); feedback(); } catch (x) { msg(err(x)); } };
    const rf = $('#fbReply', m.el);
    if (rf) {
      const send = async act => { const body = rf.body.value.trim(); if (body.length < 2) { msg('Write a message first.'); return; } const btns = rf.querySelectorAll('button'); btns.forEach(b => { b.disabled = true; }); try { const x = await api('/api/admin/support/' + id, { method: 'POST', body: { action: act, body } }); $('#fbConv', m.el).innerHTML = conv(x.messages); rf.body.value = ''; const dn = $('#fbDraftNote', m.el); if (dn) dn.remove(); if (act === 'reply') { const ok = x.mail && x.mail.mail === 'sent'; toast(x.mail ? x.mail.why : 'Reply saved.', x.mail && !ok && x.mail.mail !== 'queued'); msg(x.mail ? x.mail.why : ''); } else toast('Note saved.'); } catch (x) { msg(err(x)); } finally { btns.forEach(b => { b.disabled = false; }); } };
      const sg = rf.querySelector('[data-suggest]');
      if (sg) sg.onclick = async () => { if (rf.body.value.trim() && !confirm('Replace what is in the reply box with a new suggestion?')) return; sg.disabled = true; const label = sg.textContent; sg.textContent = 'Writing a suggestion…'; try { const x = await api('/api/admin/support/' + id, { method: 'POST', body: { action: 'draft' } }); rf.body.value = x.draft; rf.body.rows = 9; rf.body.focus(); msg('Suggested reply filled in. Read it, change anything, then send.'); } catch (x) { msg(err(x)); } finally { sg.disabled = false; sg.textContent = label; } };
      rf.onsubmit = e => { e.preventDefault(); send('reply'); };
      rf.querySelector('[data-act="note"]').onclick = () => send('note');
    }
    const sb = m.el.querySelector('[data-sort]'); if (sb) sb.onclick = async () => { try { await api('/api/admin/support/' + id, { method: 'POST', body: { action: 'sort', replyNeeded: sb.dataset.sort === '1' } }); toast(sb.dataset.sort === '1' ? 'Marked as needing a reply.' : 'Closed: no reply needed.'); m.close(); feedback(); } catch (x) { msg(err(x)); } };
    const pb = m.el.querySelector('[data-prof]'); if (pb) pb.onclick = () => profile(r.owner_id);
    const vb = m.el.querySelector('[data-viewas]'); if (vb) vb.onclick = () => viewAs(r.owner_id, id, m);
    feedback.badge = true;
  }


  function mailState(x) {
    if (!x.mail) return 'shown to the customer';
    const st = { sent: '✓ emailed to ' + x.mail.to, failed: '⚠ email not delivered yet, retrying', queued: 'email queued', sending: 'email sending', cancelled: 'email cancelled' }[x.mail.status] || x.mail.status;
    return E(st);
  }

  function sortPill(r) {
    if (!r.triage) return r.channel === 'email' ? '<br><span class="ad-pill mid">Email</span>' : '';
    return `<br><span class="ad-pill ${r.reply_needed ? 'bad' : 'ok'}" title="${E(r.triage_reason || '')}">${r.reply_needed ? 'Reply needed' : 'No reply: ' + E((SORT[r.triage] || [r.triage])[0])}</span>`;
  }

  async function inbox(box, h) {
    let d; try { d = await api('/api/admin/support/inbox'); } catch (e) { box.innerHTML = `<p role="alert" style="margin:0">${E(err(e))}</p>`; return; }
    const i = d.inbox, last = i.lastRun ? when(i.lastRun) : 'not yet';
    const state = !i.enabled ? '<span class="ad-pill mid">Off</span>' : !i.ready ? '<span class="ad-pill bad">Needs the mailbox password</span>' : i.lastError ? '<span class="ad-pill bad">Problem</span>' : '<span class="ad-pill ok">Connected</span>';
    box.innerHTML = `<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between"><div><b>Support mailbox</b> ${state}<br><small class="muted">${E(i.user)} is read every 2 minutes (nothing in the mailbox is changed). Last check ${E(last)}. Last 24 hours: ${i.day.tickets} new ticket${i.day.tickets === 1 ? '' : 's'}, ${i.day.replies} customer repl${i.day.replies === 1 ? 'y' : 'ies'}, ${i.day.skipped} automatic mail skipped. ${d.assist ? (i.autoDraft ? 'New mail is sorted and gets a suggested reply.' : 'New mail is sorted; suggested replies on request.') : 'Add the AI key in Integrations → AI for sorting and suggested replies.'}</small>${i.lastError ? `<br><small role="alert" style="color:var(--bad,#b91c1c)">${E(i.lastError)}</small>` : ''}</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button secondary small" type="button" data-chk>Check now</button>${d.canEdit ? '<button class="button secondary small" type="button" data-set>Mailbox settings</button>' : ''}</div></div>
      ${d.canEdit ? `<form class="ops-form" data-form hidden style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;align-items:end;margin-top:12px">
        <label>Mailbox<input name="user" type="email" value="${E(i.user)}" required></label>
        <label>Password<input name="password" type="password" autocomplete="new-password" placeholder="${i.hasPassword ? 'Saved (leave empty to keep)' : i.usesSendingPassword ? 'Using the sending password' : 'Mailbox password'}"></label>
        <label>Server<input name="host" value="${E(i.host)}"></label><label>Port<input name="port" type="number" min="1" max="65535" value="${i.port}"></label>
        <label><span><input type="checkbox" name="enabled" ${i.enabled ? 'checked' : ''}> Read the mailbox</span></label><label><span><input type="checkbox" name="autoDraft" ${i.autoDraft ? 'checked' : ''}> Write suggested replies automatically</span></label>
        <div><button class="button primary small" type="submit">Save</button></div></form>` : ''}<p role="status" data-st style="margin:6px 0 0"></p>`;
    const st = t => { box.querySelector('[data-st]').textContent = t; };
    box.querySelector('[data-chk]').onclick = async e => { e.target.disabled = true; st('Reading the mailbox…'); try { const x = await api('/api/admin/support/inbox/check', { method: 'POST', body: {} }); const r = x.result || {}; if (r.error) st(r.error); else { st(`Done: ${r.tickets || 0} new ticket(s), ${r.replies || 0} customer repl(ies), ${r.skipped || 0} skipped.`); if ((r.tickets || 0) + (r.replies || 0)) list(h); } setTimeout(() => inbox(box, h), 2500); } catch (x) { st(err(x)); } finally { e.target.disabled = false; } };
    const f = box.querySelector('[data-form]');
    if (f) {
      box.querySelector('[data-set]').onclick = () => { f.hidden = !f.hidden; };
      f.onsubmit = async e => { e.preventDefault(); const body = { user: f.user.value.trim(), host: f.host.value.trim(), port: +f.port.value, enabled: f.enabled.checked, autoDraft: f.autoDraft.checked }; if (f.password.value) body.password = f.password.value; try { await api('/api/admin/support/inbox', { method: 'POST', body }); toast('Saved'); inbox(box, h); } catch (x) { st(err(x)); } };
    }
  }

  function conv(list) {
    if (!list || !list.length) return '<p class="muted" style="margin:0">No replies yet.</p>';
    return list.map(x => `<div style="padding:8px 12px;border-radius:10px;border:1px solid var(--line);${x.internal ? 'background:rgba(245,158,11,.10);border-style:dashed' : x.from === 'customer' ? '' : 'background:rgba(37,99,235,.07)'}"><small class="muted">${E(x.author)} · ${E(when(x.at))}${x.internal ? ' · internal note, the customer never sees this' : x.from === 'staff' ? ' · ' + mailState(x) : ''}</small><div style="white-space:pre-wrap;margin-top:4px">${E(x.body)}</div></div>`).join('');
  }
  async function profile(uid) {
    const m = modal('Support profile', '<div class="ops-skel"></div>', { wide: true });
    let p; try { p = (await api('/api/admin/support/users/' + uid)).profile; } catch (e) { m.el.querySelector('.ops-skel').outerHTML = `<p role="alert">${E(err(e))}</p>`; return; }
    const u = p.user, rows = (list, cols) => list.length ? `<table class="ad-table"><tbody>${list.map(x => `<tr>${cols.map(c => `<td>${E(c(x))}</td>`).join('')}</tr>`).join('')}</tbody></table>` : '<p class="muted">None.</p>';
    m.el.querySelector('.ops-skel').outerHTML = `<dl style="display:grid;grid-template-columns:150px 1fr;gap:6px 12px;margin:0 0 12px"><dt>Name</dt><dd>${E(u.name)}</dd><dt>Email</dt><dd>${E(u.email)}${u.verified ? '' : ' (not verified)'}${u.blocked ? ' · <b>blocked</b>' : ''}</dd><dt>Joined</dt><dd>${E(when(u.joined))}${u.source ? ' · came from ' + E(u.source) : ''}</dd>
        <dt>Plan</dt><dd>${p.plan.paid ? E(p.plan.plan) + ' until ' + E(String(p.plan.until).slice(0, 10)) : 'Free'}</dd><dt>Documents</dt><dd>${p.documents.resumes} resumes · ${p.documents.letters} cover letters · ${p.documents.applications} applications · ${p.documents.atsScans} ATS checks</dd><dt>Emails</dt><dd>${u.marketingConsent ? 'Agreed to tips and offers' : 'Service emails only'}</dd></dl>
      <h4>Orders</h4>${rows(p.orders, [o => String(o.created_at).slice(0, 10), o => o.plan, o => K.inr(o.amount_paise), o => o.status + (o.refunded_paise ? ' · refunded ' + K.inr(o.refunded_paise) : ''), o => o.provider])}
      <h4>Recent activity</h4>${rows(p.activity, [a => when(a.created_at), a => a.action])}
      <h4>Errors</h4>${rows(p.errors, [e => when(e.last_seen), e => e.level + ' ×' + e.occurrences, e => e.message])}
      <h4>Tickets</h4>${rows(p.tickets, [x => x.reference || '', x => when(x.created_at), x => x.area + ' · ' + x.kind, x => STATUS[x.status] || x.status])}
      ${p.taraEvents.length ? `<h4>Tara chat events</h4>${rows(p.taraEvents, [x => when(x.created_at), x => x.kind + ' · ' + x.category, x => x.detail || ''])}` : ''}
      <p class="muted" style="font-size:12px">Document text, passwords, security codes and payout details are never shown here.</p>`;
  }
  async function viewAs(uid, ticketId, parent) {
    const reason = prompt('Why do you need to see this customer\'s account? (kept on the ticket and in the audit log)');
    if (reason === null) return;
    if (reason.trim().length < 10) { toast('Write a short reason (at least 10 characters).', true); return; }
    if (!(await ensureRecent())) return;
    try { await api('/api/admin/support/view/start', { method: 'POST', body: { customerId: uid, ticketId, reason: reason.trim() } }); parent.close(); window.open('/workspace.html', '_blank', 'noopener'); toast('Read-only view opened in a new tab for 30 minutes. Exit it from the banner at the top.'); } catch (x) { toast(err(x), true); }
  }
  async function newTicket() {
    const m = modal('Open a ticket for a customer', `<form id="ntForm" class="ops-form" style="display:grid;gap:10px"><label>Customer's email<input name="email" type="email" required maxlength="190"></label>
      <label>What they need<textarea name="message" rows="4" required minlength="5" maxlength="5000"></textarea></label>
      <label>Priority<select name="priority">${Object.entries(PRI).map(([k, l]) => `<option value="${k}" ${k === 'normal' ? 'selected' : ''}>${l[0]}</option>`).join('')}</select></label>
      <p class="muted" style="font-size:13px">For a customer who phoned or wrote by email. It is given to you; your replies reach them by email and under My requests.</p>
      <p role="alert" id="ntErr" style="color:var(--danger)"></p><button class="button primary">Open ticket</button></form>`);
    $('#ntForm', m.el).onsubmit = async e => { e.preventDefault(); const f = e.target; try { const x = await api('/api/admin/support/new', { method: 'POST', body: { email: f.email.value.trim(), message: f.message.value.trim(), priority: f.priority.value } }); m.close(); toast('Ticket ' + x.reference + ' opened.'); feedback(); detail(x.id, $('#admin-feedback')); } catch (x) { $('#ntErr', m.el).textContent = err(x); } };
  }
  async function sessions() {
    const m = modal('Customer view log', '<div class="ops-skel"></div>', { wide: true });
    let d; try { d = await api('/api/admin/support/view/sessions'); } catch (e) { m.el.querySelector('.ops-skel').outerHTML = `<p role="alert">${E(err(e))}</p>`; return; }
    const open = s => !s.ended_at && parse(s.expires_at) > new Date();
    m.el.querySelector('.ops-skel').outerHTML = d.sessions.length ? `<p class="muted">Every read-only view of a customer's account, who opened it and why. Changes are always refused in a view; "refused" counts what was tried.</p><div style="overflow:auto"><table class="ad-table"><thead><tr><th>Started</th><th>Staff</th><th>Customer</th><th>Reason</th><th>Pages read</th><th>Refused</th><th></th></tr></thead><tbody>${d.sessions.map(s => `<tr><td>${E(when(s.started_at))}</td><td>${E(s.staff || '')}</td><td>${E(s.customer || '')}</td><td>${E(s.reason)}</td><td>${s.requests - s.refused}</td><td>${s.refused}</td><td>${open(s) ? `<button class="button secondary small" data-end="${E(s.id)}">End now</button>` : 'ended'}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">No customer views yet.</p>';
    m.el.onclick = async e => { const b = e.target.closest('[data-end]'); if (!b) return; try { await api('/api/admin/support/view/sessions/' + b.dataset.end + '/end', { method: 'POST', body: {} }); b.outerHTML = 'ended'; } catch (x) { toast(err(x), true); } };
  }

  K.register('feedback', feedback, ['💬', 'Support Inbox', 'Feedback, bug reports and screenshots from customers and visitors.']);
  // Show the number of new reports in the menu without opening the page.
  setTimeout(() => { api('/api/admin/support/summary?days=7').then(s => { const n = document.querySelector('[data-admin-view="feedback"] .nav-count'); if (n && s.byStatus?.new) n.textContent = String(s.byStatus.new); }).catch(() => {}); }, 1500);
})();
