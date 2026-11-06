/* Owner console: Business assistant — Tara in owner mode. Ask about revenue, customers, credits, AI costs, the SEO and growth
   agents, problems and approvals; answers come from CVTara's own records with the period and source. Any change Tara
   prepares shows as a card: nothing runs until the owner presses Confirm and passes the fresh sign-in check. Also: a
   data-only daily briefing (no AI cost), the record of every action with download, and the assistant's settings. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const n = v => (v === null || v === undefined || v === '') ? '—' : (typeof v === 'number' ? v.toLocaleString('en-IN') : String(v));
  const A = { page: 1, per: 20, status: 'all', from: '', to: '' };
  let S = null, conv = null, busy = false, period = 'today';

  const css = `.tb-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:14px}
  .tb-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:14px;align-items:start}
  @media(max-width:980px){.tb-grid{grid-template-columns:1fr}}
  .tb-chat{display:flex;flex-direction:column;min-height:560px;padding:0;overflow:hidden}
  .tb-top{display:flex;gap:8px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--line,#e2e8f0);flex-wrap:wrap}
  .tb-top select{flex:1;min-width:0;max-width:100%}
  .tb-log{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:10px;max-height:66vh;min-height:300px}
  .tb-msg{max-width:92%;padding:10px 12px;border-radius:12px;line-height:1.5;overflow-wrap:anywhere}
  .tb-msg.me{align-self:flex-end;background:var(--brand,#4f46e5);color:#fff;border-bottom-right-radius:4px;white-space:pre-wrap}
  .tb-msg.ta{align-self:flex-start;background:var(--soft,#f1f5f9);border:1px solid var(--line,#e2e8f0);border-bottom-left-radius:4px}
  .tb-msg.ta p{margin:.35em 0}.tb-msg.ta ul{margin:.35em 0;padding-left:1.2em}.tb-msg.ta h4{margin:.6em 0 .3em}
  .tb-msg table{border-collapse:collapse;margin:.5em 0;font-size:.92em;display:block;overflow:auto;max-width:100%}
  .tb-msg th,.tb-msg td{border:1px solid var(--line,#cbd5e1);padding:4px 8px;text-align:left;white-space:nowrap}
  .tb-msg code{background:rgba(0,0,0,.06);padding:0 4px;border-radius:4px}
  .tb-links{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
  .tb-card{border:1px solid #f59e0b;background:#fffbeb;color:#1f2937;border-radius:10px;padding:10px 12px;margin-top:8px}
  .tb-card.done{border-color:#16a34a;background:#f0fdf4}.tb-card.failed{border-color:#dc2626;background:#fef2f2}.tb-card.cancelled,.tb-card.expired{opacity:.75;border-color:#94a3b8;background:#f8fafc}
  .tb-card b{display:block;margin-bottom:4px}.tb-card .tb-kv{font-size:.88em;color:#475569;margin-top:6px}
  .tb-card .tb-do{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
  .tb-chips{display:flex;gap:6px;flex-wrap:wrap;padding:0 12px 8px}
  .tb-chips button{border:1px solid var(--line,#cbd5e1);background:var(--paper,#fff);color:inherit;border-radius:999px;padding:5px 10px;font-size:.86em;cursor:pointer}
  .tb-in{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--line,#e2e8f0)}
  .tb-in textarea{flex:1;min-height:44px;max-height:180px;resize:vertical;font:inherit;padding:8px 10px;border-radius:10px;border:1px solid var(--line,#cbd5e1);background:var(--paper,#fff);color:inherit}
  .tb-wait{color:var(--muted,#64748b);font-style:italic}
  .tb-brief h4{margin:14px 0 6px}.tb-brief ul{margin:0;padding-left:1.1em}.tb-brief li{margin:3px 0}
  .tb-kpis{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .tb-kpis div{border:1px solid var(--line,#e2e8f0);border-radius:10px;padding:8px 10px}.tb-kpis span{display:block;font-size:.8em;color:var(--muted,#64748b)}.tb-kpis b{font-size:1.15em}.tb-kpis small{display:block;color:var(--muted,#64748b)}
  .tb-sev{display:inline-block;font-size:.75em;font-weight:700;text-transform:uppercase;padding:1px 6px;border-radius:6px;margin-right:4px;background:#fee2e2;color:#991b1b}.tb-sev.high{background:#ffedd5;color:#9a3412}
  @media(prefers-color-scheme:dark){.tb-card{background:#3a2a05;color:#fde68a}.tb-card.done{background:#052e16;color:#bbf7d0}.tb-card.failed{background:#450a0a;color:#fecaca}.tb-card .tb-kv{color:inherit;opacity:.85}.tb-msg code{background:rgba(255,255,255,.1)}}`;

  /* Safe, small Markdown: everything is escaped first; then lists, tables, bold, code and console or https links. */
  function md(src) {
    const inline = s => E(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\[([^\]]+)\]\(((?:\/(?!\/)|https:\/\/)[^\s)]+)\)/g, (m, t, u) => `<a href="${u.replace(/"/g, '%22')}"${u.startsWith('https://') ? ' target="_blank" rel="noopener"' : ''}>${t}</a>`);
    const lines = String(src || '').split(/\r?\n/); let h = '', i = 0;
    while (i < lines.length) {
      const l = lines[i];
      if (/^\s*\|.*\|\s*$/.test(l)) {
        const rows = []; while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) rows.push(lines[i++]);
        const cells = r => r.trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());
        const body = rows.filter(r => !/^\s*\|(\s*:?-+:?\s*\|)+\s*$/.test(r));
        h += '<table>' + body.map((r, k) => '<tr>' + cells(r).map(c => k === 0 ? `<th>${inline(c)}</th>` : `<td>${inline(c)}</td>`).join('') + '</tr>').join('') + '</table>';
        continue;
      }
      if (/^\s*[-*•]\s+/.test(l)) { h += '<ul>'; while (i < lines.length && /^\s*[-*•]\s+/.test(lines[i])) h += `<li>${inline(lines[i++].replace(/^\s*[-*•]\s+/, ''))}</li>`; h += '</ul>'; continue; }
      if (/^\s*\d+[.)]\s+/.test(l)) { h += '<ol>'; while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) h += `<li>${inline(lines[i++].replace(/^\s*\d+[.)]\s+/, ''))}</li>`; h += '</ol>'; continue; }
      if (/^#{1,6}\s+/.test(l)) { h += `<h4>${inline(l.replace(/^#{1,6}\s+/, ''))}</h4>`; i++; continue; }
      if (l.trim()) h += `<p>${inline(l)}</p>`;
      i++;
    }
    return h;
  }

  const kv = o => o && typeof o === 'object' ? Object.entries(o).map(([k, v]) => `${E(k.replace(/([A-Z])/g, ' $1').toLowerCase())}: <b style="display:inline">${E(v === null || v === undefined || v === '' ? '—' : (typeof v === 'boolean' ? (v ? 'yes' : 'no') : v))}</b>`).join(' · ') : '';
  const STATUS = { pending: 'Waiting for your Confirm', running: 'Running…', done: 'Done', failed: 'Not done', cancelled: 'Cancelled', expired: 'Expired: ask again so the values are checked afresh' };
  function card(c) {
    return `<div class="tb-card ${E(c.status)}" data-card="${E(c.id)}"><b>${E(c.label)}</b><div>${E(c.summary)}</div>
      ${c.before ? `<div class="tb-kv">Now: ${kv(c.before)}</div>` : ''}${c.after && c.status === 'done' ? `<div class="tb-kv">After: ${kv(c.after)}</div>` : ''}
      <div class="tb-kv">${E(STATUS[c.status] || c.status)}${c.result && c.status !== 'done' ? ' · ' + E(c.result) : ''}</div>
      ${c.status === 'pending' ? '<div class="tb-do"><button class="button primary small" data-ok>Confirm</button><button class="button secondary small" data-no>Cancel</button></div>' : ''}</div>`;
  }
  function wireCards(root) {
    root.querySelectorAll('[data-card]').forEach(el => {
      const id = el.dataset.card, ok = $('[data-ok]', el), no = $('[data-no]', el);
      if (ok) ok.onclick = async () => {
        ok.disabled = true; if (no) no.disabled = true;
        try {
          if (!(await K.ensureRecent())) { ok.disabled = false; if (no) no.disabled = false; return; }
          const d = await api('/api/admin/tara/owner/actions/confirm', { method: 'POST', body: { id } });
          el.outerHTML = card(d.card); toast('Done'); actions(); strip();
        } catch (e) { toast(err(e)); ok.disabled = false; if (no) no.disabled = false; if (conv) openConv(conv, true); }
      };
      if (no) no.onclick = async () => { try { const d = await api('/api/admin/tara/owner/actions/cancel', { method: 'POST', body: { id } }); el.outerHTML = card(d.card); actions(); } catch (e) { toast(err(e)); } };
    });
  }

  function bubble(m) {
    if (m.role === 'user') return `<div class="tb-msg me">${E(m.content)}</div>`;
    return `<div class="tb-msg ta">${md(m.content)}${(m.links || []).length ? `<div class="tb-links">${m.links.map(l => `<a class="button small secondary" href="${E(l.url)}"${/^https?:/.test(l.url) ? ' target="_blank" rel="noopener"' : ''}>${E(l.label)}</a>`).join('')}</div>` : ''}${(m.cards || []).map(card).join('')}</div>`;
  }

  async function strip() {
    const box = $('#tbStrip'); if (!box) return;
    try {
      const d = await api('/api/admin/tara/owner'); const s = d.strip;
      box.innerHTML = [['Net revenue today', s.revenueToday], ['Paid orders today', s.ordersToday], ['Sign-ups today', s.signupsToday], ['AI spending today', s.aiToday], ['Errors (24 h)', s.errors24h], ['Waiting for you', s.waiting]]
        .map(([l, v]) => `<div class="kpi"><span>${E(l)}</span><b>${E(n(v))}</b><small>as of ${E(s.at)}</small></div>`).join('');
    } catch (_) { /* the strip is a convenience */ }
  }

  async function assistant(h) {
    h = h || $('#admin-assistant');
    if (!document.getElementById('tbCss')) { const st = document.createElement('style'); st.id = 'tbCss'; st.textContent = css; document.head.append(st); }
    if (!S) h.innerHTML = '<div class="ops-skel"></div>';
    try { S = await api('/api/admin/tara/owner'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const st = S.settings;
    h.innerHTML = `<div class="pg-head"><p>Ask one question and get the answer from CVTara's own records: revenue, customers, credits, AI spending, the SEO and growth agents, problems and approvals. Important numbers come with the period, the source and the time they were worked out. When you ask for a change, Tara prepares it as a card: nothing changes until you press <b>Confirm</b> and pass the sign-in check.</p></div>
      ${S.enabled ? '' : '<section class="panel panel-pad" style="border-left:4px solid #f59e0b"><b>Chat needs the AI key.</b> Add the Claude API key and switch AI on in Settings → Integrations → AI. The briefing on the right works without it.</section>'}
      <div class="tb-strip ops-kpis" id="tbStrip"></div>
      <div class="tb-grid">
        <section class="panel tb-chat" aria-label="Business assistant chat">
          <div class="tb-top"><select id="tbConv" aria-label="Earlier chats"><option value="">New chat</option></select><button class="button small secondary" id="tbNew">New chat</button><button class="button small secondary" id="tbDel" hidden>Delete chat</button></div>
          <div class="tb-log" id="tbLog" aria-live="polite"></div>
          <div class="tb-chips" id="tbChips">${S.suggestions.map(q => `<button type="button" data-q="${E(q)}">${E(q)}</button>`).join('')}</div>
          <form class="tb-in" id="tbForm"><textarea id="tbText" rows="2" maxlength="8000" placeholder="Ask about revenue, customers, AI costs, agents, problems… or ask for a change" ${S.enabled ? '' : 'disabled'}></textarea><button class="button primary" ${S.enabled ? '' : 'disabled'}>Ask</button></form>
        </section>
        <aside class="panel panel-pad tb-brief" aria-label="Daily briefing">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><h3 style="margin:0;flex:1">Business briefing</h3>
            <select id="tbPer" aria-label="Period">${[['today', 'Today'], ['yesterday', 'Yesterday'], ['last_7_days', 'Last 7 days'], ['this_month', 'This month'], ['last_30_days', 'Last 30 days'], ['last_month', 'Last month']].map(([k, l]) => `<option value="${k}" ${k === period ? 'selected' : ''}>${l}</option>`).join('')}</select>
            <button class="button small secondary" id="tbRef" title="Work it out again now">Refresh</button></div>
          <div id="tbBrief"><div class="ops-skel"></div></div>
          ${S.enabled ? '<button class="button small primary" id="tbExplain" style="margin-top:12px">Ask Tara to explain this briefing</button>' : ''}
        </aside>
      </div>
      <section class="panel panel-pad"><h3 style="margin-top:0">Actions record</h3><p class="ops-note" style="margin-top:0">Every change Tara prepared, who confirmed it, the values before and after, and the result. Changes also appear in the audit trail.</p>
        <div class="ops-bar" id="tbABar"><label class="ops-f">Result<select id="tbAS"><option value="all">All</option><option value="done">Done</option><option value="pending">Waiting</option><option value="failed">Not done</option><option value="cancelled">Cancelled</option><option value="expired">Expired</option></select></label>${K.rangeHtml(A.from, A.to, true, 'Asked')} ${K.dlHtml()}</div>
        <div style="overflow:auto"><table class="ad-table" id="tbAT" data-nokit></table></div><div id="tbAP"></div></section>
      <details class="panel panel-pad"><summary><b>Assistant settings</b> · spent this month ₹${E(n(Math.round((S.spendMonthPaise || 0) / 100)))}${st.monthlySpendInr ? ' of ₹' + E(n(st.monthlySpendInr)) : ''}</summary>
        <form id="tbSet" class="ops-form" style="margin-top:10px">
          <label>Model (blank = the AI model in Integrations)<input name="model" maxlength="80" value="${E(st.model)}"></label>
          <label>Spending limit a month (₹, 0 = none)<input name="monthlySpendInr" type="number" min="0" value="${E(st.monthlySpendInr)}"></label>
          <label>Longest answer (tokens)<input name="maxTokens" type="number" min="500" max="4000" value="${E(st.maxTokens)}"></label>
          <label>Recent messages Tara reads<input name="historyMessages" type="number" min="4" max="60" value="${E(st.historyMessages)}"></label>
          <label>Lookups per answer (most)<input name="maxSteps" type="number" min="2" max="12" value="${E(st.maxSteps)}"></label>
          <label class="wide">Standing instructions (for example: "Always compare with the same day last week")<textarea name="instructions" rows="3" maxlength="4000">${E(st.instructions)}</textarea></label>
          <p class="wide ops-note" style="margin:0">Actions Tara can prepare (each needs your Confirm): ${Object.values(S.actions).map(E).join('; ')}. Payment keys, passwords, security, deleting records, blocking people and plan prices stay on their own pages.</p>
          <div class="wide"><button class="button primary">Save</button> <span role="status" id="tbSetSt"></span></div></form></details>`;
    strip(); brief(); listConvs(); actions();
    if (conv) openConv(conv); else hello();
    const f = $('#tbForm', h), ta = $('#tbText', h);
    f.onsubmit = e => { e.preventDefault(); send(ta.value); };
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(ta.value); } });
    $('#tbChips', h).onclick = e => { const b = e.target.closest('[data-q]'); if (b) send(b.dataset.q); };
    $('#tbNew', h).onclick = () => { conv = null; $('#tbConv').value = ''; hello(); };
    $('#tbConv', h).onchange = e => { if (e.target.value) openConv(e.target.value); else { conv = null; hello(); } };
    $('#tbDel', h).onclick = async () => { if (!conv || !confirm('Delete this chat? The actions record is kept.')) return; try { await api('/api/admin/tara/owner/conversation/delete', { method: 'POST', body: { id: conv } }); conv = null; hello(); listConvs(); } catch (e) { toast(err(e)); } };
    $('#tbPer', h).onchange = e => { period = e.target.value; brief(); };
    $('#tbRef', h).onclick = () => brief(true);
    const ex = $('#tbExplain', h); if (ex) ex.onclick = () => send(`Explain the ${$('#tbPer option:checked').textContent.toLowerCase()} business briefing: what is good, what needs attention, and the two most useful next actions.`);
    const ab = $('#tbABar', h);
    ab.addEventListener('change', e => { if (e.target.id === 'tbAS') { A.status = e.target.value; A.page = 1; actions(); return; } const rg = K.rangeChange(e, ab); if (rg) { [A.from, A.to] = rg; A.page = 1; actions(); } });
    K.wireDownload(ab, fmt => K.exportUrl('/api/admin/tara/owner/actions', { status: A.status === 'all' ? '' : A.status, from: A.from, to: A.to }, fmt));
    const sf = $('#tbSet', h);
    sf.onsubmit = async e => {
      e.preventDefault(); const o = $('#tbSetSt', h);
      const body = { model: sf.model.value.trim(), instructions: sf.instructions.value, monthlySpendInr: Number(sf.monthlySpendInr.value || 0), maxTokens: Number(sf.maxTokens.value || 2500), historyMessages: Number(sf.historyMessages.value || 20), maxSteps: Number(sf.maxSteps.value || 8) };
      o.textContent = 'Saving…'; try { await api('/api/admin/tara/owner/settings', { method: 'POST', body }); o.textContent = 'Saved.'; toast('Assistant settings saved'); } catch (x) { o.textContent = err(x); }
    };
  }

  function hello() {
    const log = $('#tbLog'); if (!log) return; $('#tbDel').hidden = true; $('#tbChips').hidden = false;
    log.innerHTML = bubble({ role: 'assistant', content: 'Hi! Ask me anything about the business, for example **today\'s revenue**, **AI spending this month by feature**, **is the SEO agent working**, or **which plans end this week**. I look everything up in CVTara\'s records and say where each number comes from. If you ask for a change, I prepare it for your Confirm.' });
  }

  async function listConvs() {
    const sel = $('#tbConv'); if (!sel) return;
    try {
      const d = await api('/api/admin/tara/owner/conversations');
      sel.innerHTML = '<option value="">New chat</option>' + d.conversations.map(c => `<option value="${E(c.id)}" ${c.id === conv ? 'selected' : ''}>${E(c.title)} · ${E(String(c.last_at || '').slice(0, 10))}</option>`).join('');
    } catch (_) { /* the list is a convenience */ }
  }

  async function openConv(id, quiet) {
    const log = $('#tbLog'); if (!log) return;
    try {
      const d = await api('/api/admin/tara/owner/conversation?id=' + encodeURIComponent(id));
      conv = id; $('#tbDel').hidden = false; $('#tbChips').hidden = true;
      log.innerHTML = d.messages.map(bubble).join(''); wireCards(log); log.scrollTop = log.scrollHeight;
    } catch (e) { if (!quiet) toast(err(e)); }
  }

  async function send(text) {
    text = String(text || '').trim(); if (!text || busy) return;
    const log = $('#tbLog'), ta = $('#tbText'); busy = true; ta.value = ''; $('#tbChips').hidden = true;
    if (!conv) log.innerHTML = '';
    log.insertAdjacentHTML('beforeend', bubble({ role: 'user', content: text }) + '<div class="tb-msg ta tb-wait" id="tbWait">Looking it up in CVTara\'s records…</div>'); log.scrollTop = log.scrollHeight;
    try {
      const d = await api('/api/admin/tara/owner/message', { method: 'POST', body: { text, conversationId: conv } });
      $('#tbWait')?.remove(); const fresh = !conv; conv = d.conversationId;
      log.insertAdjacentHTML('beforeend', bubble(d.message)); wireCards(log); log.scrollTop = log.scrollHeight; $('#tbDel').hidden = false;
      if (fresh) listConvs();
      if ((d.message.cards || []).length) actions();
    } catch (e) { $('#tbWait')?.remove(); log.insertAdjacentHTML('beforeend', `<div class="tb-msg ta" role="alert">${E(err(e))}</div>`); ta.value = text; }
    busy = false;
  }

  async function brief(fresh) {
    const box = $('#tbBrief'); if (!box) return; box.innerHTML = '<div class="ops-skel"></div>';
    try {
      const b = (await api('/api/admin/tara/owner/briefing?period=' + period + (fresh ? '&fresh=1' : ''))).briefing;
      const li = a => a.length ? `<ul>${a.join('')}</ul>` : '<p class="ops-note">None.</p>';
      const p = b.pending;
      box.innerHTML = `<p class="ops-note" style="margin:6px 0 10px">${E(b.range)}. Compared with ${E(b.comparedWith)}.</p>
        <div class="tb-kpis">${b.kpis.map(k => `<div><span>${E(k.label)}</span><b>${E(n(k.value))}</b>${k.change ? `<small>${E(k.change)} vs before</small>` : ''}</div>`).join('')}</div>
        <h4>Sales</h4>${li([`<li>${E(n(b.newPaidCustomers))} new paying customers · ${E(n(b.activePaidCustomers))} with an active package</li>`, ...b.byPackage.map(x => `<li>${E(x.package)}: ${E(n(x.orders))} (${E(x.revenue)})</li>`), `<li>Refunds: ${E(b.refunds)}</li>`, `<li>Unpaid checkouts: ${E(n(b.unpaidCheckouts))} · failed payment notices: ${E(n(b.failedPayments))}</li>`])}
        <h4>Agents</h4>${li([`<li>SEO agent: ${E(b.seo.status)} · last run ${E(b.seo.lastRun)} · ${E(n(b.seo.waiting))} changes to approve${b.seo.clicks !== null ? ` · Google ${E(n(b.seo.clicks))} clicks, ${E(n(b.seo.impressions))} impressions (${E(b.seo.dates)})` : ''}</li>`, `<li>Growth reports: ${E(b.growth.reports)} · ${E(b.growth.month.month)}: ${E(n(b.growth.month.purchases))} purchases${b.growth.month.target ? ' of ' + E(n(b.growth.month.target)) + ' target (' + E(b.growth.month.attainment || '—') + ' of pace)' : ''}</li>`])}
        <h4>Funnel</h4><p class="ops-note" style="margin:0">${E(b.funnel)}</p>
        ${b.customerTara ? `<h4>Tara with customers</h4>${li([`<li>${b.customerTara.enabled ? 'On' : 'Off'} · ${E(n(b.customerTara.conversations))} chats · ${E(n(b.customerTara.people))} people · 👍 ${E(n(b.customerTara.thumbsUp))} 👎 ${E(n(b.customerTara.thumbsDown))} · ${E(n(b.customerTara.supportTicketsFromTara))} tickets</li>`, `<li>Offers shown ${E(n(b.customerTara.offersShown))} · clicked ${E(n(b.customerTara.offersClicked))} · bought within 7 days ${E(n(b.customerTara.boughtWithin7DaysOfAnOffer))} (${E(b.customerTara.revenueWithin7DaysOfAnOffer)})</li>`, `<li>Security: ${E(n(b.security.events))} unsafe requests noticed, ${E(n(b.security.serious))} refused${b.security.customersWithRepeatedAttempts ? ` · <b>${E(n(b.security.customersWithRepeatedAttempts))} customer(s) with repeated attempts</b> <a href="/admin.html?tab=errors">Error Log</a>` : ''}</li>`])}` : ''}
        <h4>AI spending</h4>${li([`<li>Total ${E(b.ai.total.inr)} (${E(b.ai.total.usd)}) · ${E(b.ai.exchangeRate)}</li>`, ...b.ai.byGroup.map(g => `<li>${E(g.group)}: ${E(g.inr)} · ${E(n(g.requests))} requests</li>`)])}
        <h4>Problems to fix</h4>${li(b.problems.map(x => `<li><span class="tb-sev ${E(x.severity)}">${E(x.severity)}</span>${E(x.issue)} <a href="${E(x.where)}">Open</a></li>`))}
        <h4>Waiting for you</h4>${li([['Refund requests', p.refundRequests, p.links.refunds], ['Expert Review / profile work', p.expertReviewAndProfileWork, p.links.refunds], ['Expert Reviews that failed', (p.expertReviewsFailed || []).length, p.links.refunds], ['SEO changes to approve', p.seoChangesToApprove, p.links.seo], ['Reviews to moderate', p.reviewsToModerate, p.links.reviews], ['Support not answered', p.supportNotAnswered, p.links.support]].filter(x => x[1] > 0).map(([l, v, u]) => `<li><a href="${E(u)}">${E(l)}</a>: ${E(n(v))}</li>`))}
        <h4>Recommended next</h4>${li(b.nextActions.map(x => `<li>${E(x)}</li>`))}
        <h4>Not connected or not tracked</h4>${li(b.notConnected.map(x => `<li>${E(x)}</li>`))}
        <p class="ops-note" style="margin-bottom:0">Worked out ${E(b.workedOutAt)} from CVTara's orders, users, AI usage, agents and error log. Kept for 5 minutes; Refresh works it out again.</p>`;
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  async function actions() {
    const tbl = $('#tbAT'); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(A.page), per: String(A.per) }); if (A.status !== 'all') qs.set('status', A.status); if (A.from) qs.set('from', A.from); if (A.to) qs.set('to', A.to);
      const d = await api('/api/admin/tara/owner/actions?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Asked</th><th>Action</th><th>What</th><th>Result</th><th>Confirmed by</th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${E(String(r.created_at).slice(0, 16))}</td><td>${E((S && S.actions[r.action]) || r.action)}</td><td style="min-width:260px">${E(r.summary)}${r.after_json && r.status === 'done' ? `<br><small>Before: ${kv(JSON.parse(r.before_json || 'null'))}<br>After: ${kv(JSON.parse(r.after_json || 'null'))}</small>` : ''}</td><td>${E(STATUS[r.status] || r.status)}${r.result && r.status !== 'done' ? '<br><small>' + E(r.result) + '</small>' : ''}</td><td>${E(r.decided_by || '')}<br><small>${E(String(r.decided_at || '').slice(0, 16))}</small></td></tr>`).join('')}</tbody>` : '<tbody><tr><td>No actions yet. Ask Tara for a change (for example "give priya@example.com Job Hunt for 30 days, complimentary, as a thank-you") and it appears here.</td></tr></tbody>';
      K.pager($('#tbAP'), d, (p, per) => { A.page = p; A.per = per; actions(); });
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  /* A small Business assistant chat in the bottom-right corner of every console page (where Live stats used to be).
     Same answers, same cards: nothing changes until Confirm. Hidden on the Business assistant page itself. */
  let dconv = null, dbusy = false;
  function dock() {
    if (document.getElementById('tbDock')) return;
    if (!document.getElementById('tbCss')) { const c = document.createElement('style'); c.id = 'tbCss'; c.textContent = css; document.head.append(c); }
    const st = document.createElement('style');
    st.textContent = `#tbDock{position:fixed;right:20px;bottom:20px;z-index:3900;font-size:14px}
    #tbDock .tbd-fab{display:flex;align-items:center;gap:8px;padding:10px 16px;border:0;border-radius:999px;background:#0f172a;color:#fff;font:600 14px/1 inherit;cursor:pointer;box-shadow:0 8px 28px rgba(0,0,0,.3)}
    #tbDock .tbd-panel{width:min(400px,calc(100vw - 24px));height:min(560px,calc(100vh - 40px));display:flex;flex-direction:column;background:var(--paper,#fff);color:var(--ink,#0f172a);border:1px solid var(--line,#e2e8f0);border-radius:16px;box-shadow:0 12px 40px rgba(15,23,42,.3);overflow:hidden}
    #tbDock .tbd-panel[hidden]{display:none}#tbDock.open .tbd-fab{display:none}
    #tbDock header{display:flex;align-items:center;gap:8px;padding:10px 12px;background:#0f172a;color:#fff}#tbDock header b{flex:1}
    #tbDock header button{background:none;border:0;color:#cbd5e1;font-size:16px;cursor:pointer;padding:2px 6px}
    #tbDock .tb-log{max-height:none;min-height:0}
    body.tb-on-assistant #tbDock{display:none}
    @media(max-width:600px){#tbDock{right:12px;bottom:12px}#tbDock .tbd-fab b{display:none}}`;
    document.head.append(st);
    const w = document.createElement('div'); w.id = 'tbDock';
    w.innerHTML = `<button type="button" class="tbd-fab" aria-controls="tbdPanel"><span aria-hidden="true">🧭</span><b>Ask Tara</b></button>
      <section class="tbd-panel" id="tbdPanel" role="dialog" aria-label="Business assistant" hidden>
        <header><span aria-hidden="true">🧭</span><b>Business assistant</b><button type="button" data-d="new" title="New chat" aria-label="New chat">＋</button><button type="button" data-d="page" title="Open the full page" aria-label="Open the full page">⤢</button><button type="button" data-d="close" title="Close" aria-label="Close">✕</button></header>
        <div class="tb-log" id="tbdLog"></div>
        <div class="tb-chips" id="tbdChips"></div>
        <form class="tb-in"><textarea id="tbdText" rows="2" maxlength="8000" placeholder="Ask about revenue, customers, agents…"></textarea><button class="button primary small">Ask</button></form>
      </section>`;
    document.body.append(w);
    const panel = $('#tbdPanel'), log = $('#tbdLog'), ta = $('#tbdText');
    const hi = () => { log.innerHTML = bubble({ role: 'assistant', content: 'Hi! Ask me about today\'s revenue, customers, AI spending, the SEO and growth agents, or what to do first today. Changes come as cards for your Confirm.' });
      $('#tbdChips').innerHTML = ['What should I prioritise today?', 'Give me today\'s business briefing', 'Why is revenue not growing?', 'How is Tara doing with customers this week?'].map(x => `<button type="button" data-q="${E(x)}">${E(x)}</button>`).join(''); $('#tbdChips').hidden = false; };
    const show = on => { panel.hidden = !on; w.classList.toggle('open', on); if (on) { if (!log.children.length) hi(); ta.focus(); } };
    w.addEventListener('click', e => {
      if (e.target.closest('.tbd-fab')) return show(true);
      const d = e.target.closest('[data-d]'); const q = e.target.closest('[data-q]');
      if (q) return dsend(q.dataset.q);
      if (!d) return;
      if (d.dataset.d === 'close') show(false);
      else if (d.dataset.d === 'new') { dconv = null; hi(); }
      else if (d.dataset.d === 'page') { show(false); const b = document.querySelector('[data-admin-view="assistant"]'); if (b) b.click(); else location.href = '/admin.html?tab=assistant'; }
    });
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); dsend(ta.value); } });
    $('form', w).onsubmit = e => { e.preventDefault(); dsend(ta.value); };
    async function dsend(text) {
      text = String(text || '').trim(); if (!text || dbusy) return;
      dbusy = true; ta.value = ''; $('#tbdChips').hidden = true;
      if (!dconv) log.innerHTML = '';
      log.insertAdjacentHTML('beforeend', bubble({ role: 'user', content: text }) + '<div class="tb-msg ta tb-wait" id="tbdWait">Looking it up in CVTara\'s records…</div>'); log.scrollTop = log.scrollHeight;
      try {
        const d = await api('/api/admin/tara/owner/message', { method: 'POST', body: { text, conversationId: dconv } });
        $('#tbdWait')?.remove(); dconv = d.conversationId;
        log.insertAdjacentHTML('beforeend', bubble(d.message)); wireCards(log); log.scrollTop = log.scrollHeight;
      } catch (e) { $('#tbdWait')?.remove(); log.insertAdjacentHTML('beforeend', `<div class="tb-msg ta" role="alert">${E(err(e))}</div>`); ta.value = text; }
      dbusy = false;
    }
    // Hidden while the full Business assistant page is open
    const watch = () => document.body.classList.toggle('tb-on-assistant', !!document.querySelector('#admin-assistant.active'));
    new MutationObserver(watch).observe(document.getElementById('admin-assistant') || document.body, { attributes: true, attributeFilter: ['class'] }); watch();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', dock); else dock();

  K.register('assistant', assistant, ['🧭', 'Business assistant', 'Ask about revenue, customers, AI costs, agents and problems; changes wait for your Confirm.']);
})();
