/* Owner console: Tara chat — switch it on, model, extra instructions, actions Tara may use, limits and spending caps,
   numbers for today and the week, conversations (opening one is recorded in the audit trail) and the actions that failed.
   Tara's tickets land in Support with the area "Tara chat". */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const inr = p => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  const n = v => Number(v || 0).toLocaleString('en-IN');
  const C = { page: 1, per: 20, q: '', from: '', to: '' };
  const T = { page: 1, per: 20, status: 'failed', tool: 'all', from: '', to: '' };
  let D = null;

  async function tara(h) {
    h = h || $('#admin-tara');
    if (!D) h.innerHTML = '<div class="ops-skel"></div>';
    try { D = await api('/api/admin/tara'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const s = D.settings, st = D.stats;
    h.innerHTML = `<div class="pg-head"><p>Tara is the customers' career assistant inside the workspace. She uses the same tools as the normal pages (ATS check, job links, cover letters, credits, orders, support), as that customer, so every limit and credit rule still applies. Customers see "Tara, your CVTara career assistant", never AI.</p></div>
      ${D.aiReady ? '' : '<section class="panel panel-pad" style="border-left:4px solid #f59e0b"><b>Tara cannot be switched on yet.</b> Add the Claude API key and switch AI on in Settings → Integrations → AI.</section>'}
      <div class="ops-kpis">
        <div class="kpi"><span>Status</span><b>${s.enabled && D.aiReady ? 'On' : 'Off'}</b></div>
        <div class="kpi"><span>Messages today</span><b>${n(st.messagesToday)}</b><small>${n(st.peopleToday)} ${st.peopleToday === 1 ? 'person' : 'people'}</small></div>
        <div class="kpi"><span>Spent today</span><b>${inr(st.spendTodayPaise)}</b><small>limit ${s.dailySpendInr ? '₹' + n(s.dailySpendInr) : 'none'}</small></div>
        <div class="kpi"><span>Spent this month</span><b>${inr(st.spendMonthPaise)}</b><small>limit ${s.monthlySpendInr ? '₹' + n(s.monthlySpendInr) : 'none'}</small></div>
        <div class="kpi"><span>Last 7 days</span><b>${n(st.conversations7d)} ${st.conversations7d === 1 ? 'chat' : 'chats'}</b><small>👍 ${n(st.thumbsUp7d)} · 👎 ${n(st.thumbsDown7d)} · ${n(st.tickets7d)} tickets · ${n(st.failedTools7d)} failed actions</small></div>
      </div>
      <section class="panel panel-pad"><h3 style="margin-top:0">Settings</h3>
        <form id="taForm" class="ops-form">
          <label class="wide" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="enabled" ${s.enabled ? 'checked' : ''} ${D.aiReady ? '' : 'disabled'}> Tara is on for customers</label>
          <label>Model (blank = the Tara chat model in Integrations, claude-haiku-5-5 unless changed)<input name="model" maxlength="80" value="${E(s.model)}" placeholder="claude-haiku-5-5"></label>
          <label>Longest answer (tokens)<input name="maxTokens" type="number" min="300" max="4000" value="${E(s.maxTokens)}"></label>
          <label>Messages a day: free account<input name="freeDaily" type="number" min="0" max="500" value="${E(s.freeDaily)}"></label>
          <label>Messages a day: paid account<input name="paidDaily" type="number" min="0" max="2000" value="${E(s.paidDaily)}"></label>
          <label>Messages a day: whole site (0 = no limit)<input name="siteDaily" type="number" min="0" value="${E(s.siteDaily)}"></label>
          <label>Spending limit a day (₹, 0 = none)<input name="dailySpendInr" type="number" min="0" value="${E(s.dailySpendInr)}"></label>
          <label>Spending limit a month (₹, 0 = none)<input name="monthlySpendInr" type="number" min="0" value="${E(s.monthlySpendInr)}"></label>
          <label>Recent messages Tara reads (older ones are summarised)<input name="historyMessages" type="number" min="4" max="60" value="${E(s.historyMessages)}"></label>
          <label>Actions per answer (most)<input name="maxSteps" type="number" min="1" max="8" value="${E(s.maxSteps)}"></label>
          <label class="wide" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="greet" ${s.greet ? 'checked' : ''}> Open the chat once a day after sign-in with a hello by name and a question (a small bubble on phones)</label>
          <label class="wide" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="nudges" ${s.nudges ? 'checked' : ''}> Show the "next step" card on every customer's dashboard (works even while chat is off)</label>
          <label>Smallest group shown in "people from your college / company" (keeps people anonymous)<input name="peerMin" type="number" min="2" max="100" value="${E(s.peerMin)}"></label>
          <label class="wide" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="suggestions" ${s.suggestions ? 'checked' : ''}> Show suggested questions when a chat starts</label>
          <label class="wide">Extra instructions for Tara (added to her built-in rules; customers never see them)<textarea name="instructions" rows="4" maxlength="4000" placeholder="For example: mention the Diwali offer code DIWALI10 when someone asks about prices.">${E(s.instructions)}</textarea></label>
          <label class="wide">Message when Tara passes a problem to the team<input name="handoff" maxlength="400" value="${E(s.handoff)}"></label>
          <fieldset class="wide" style="border:0;padding:0;margin:0"><legend>Actions Tara may use</legend><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px">
            ${Object.entries(D.tools).map(([k, l]) => `<label style="display:flex;gap:8px;align-items:flex-start;font-weight:400"><input type="checkbox" data-tool="${E(k)}" ${s.tools.includes(k) ? 'checked' : ''}> ${E(l)}</label>`).join('')}</div></fieldset>
          <p class="wide ops-note" style="margin:0">Customers' first message from Tara: “${E(D.welcome)}”. Chatting is free for customers; an ATS check uses a scan and a clean download uses a download credit, as on the normal pages. Chats are kept 180 days, then their words are wiped. Spending shows in Reports & Export › AI spending as “Tara chat”.</p>
          <div class="wide"><button class="button primary">Save</button> <span role="status" id="taSt"></span></div></form></section>
      <section class="panel panel-pad"><h3 style="margin-top:0">Conversations</h3><p class="ops-note" style="margin-top:0">Open a chat only to help a customer or check quality. Each opening is recorded in the audit trail.</p>
        <div class="ops-bar" id="tcBar"><label class="ops-f">Search<input id="tcQ" type="search" placeholder="Email, name or first message"></label>${K.rangeHtml(C.from, C.to, true, 'Last message')} ${K.dlHtml()}</div>
        <div style="overflow:auto"><table class="ad-table" id="tcT" data-nokit></table></div><div id="tcP"></div></section>
      <section class="panel panel-pad"><h3 style="margin-top:0">Actions that failed</h3><p class="ops-note" style="margin-top:0">Most are ordinary (a scan limit reached, a job page that could not be read). Repeats of the same reason point to something to fix.</p>
        <div class="ops-bar" id="ttBar"><label class="ops-f">Result<select id="ttS"><option value="failed">Failed</option><option value="ok">Worked</option><option value="all">All</option></select></label>
          <label class="ops-f">Action<select id="ttA"><option value="all">All</option>${Object.entries(D.tools).map(([k, l]) => `<option value="${E(k)}">${E(l)}</option>`).join('')}</select></label>${K.rangeHtml(T.from, T.to, true, 'When')} ${K.dlHtml()}</div>
        <div style="overflow:auto"><table class="ad-table" id="ttT" data-nokit></table></div><div id="ttP"></div></section>`;
    const f = $('#taForm', h);
    f.onsubmit = async e => {
      e.preventDefault(); const stEl = $('#taSt', h);
      const num = k => Number(f[k].value || 0);
      const body = { enabled: f.enabled.checked, model: f.model.value.trim(), instructions: f.instructions.value, handoff: f.handoff.value.trim(), suggestions: f.suggestions.checked, nudges: f.nudges.checked, greet: f.greet.checked, peerMin: num('peerMin'),
        freeDaily: num('freeDaily'), paidDaily: num('paidDaily'), siteDaily: num('siteDaily'), dailySpendInr: num('dailySpendInr'), monthlySpendInr: num('monthlySpendInr'), maxTokens: num('maxTokens'), historyMessages: num('historyMessages'), maxSteps: num('maxSteps'),
        tools: [...f.querySelectorAll('[data-tool]')].filter(x => x.checked).map(x => x.dataset.tool) };
      try { await api('/api/admin/tara/settings', { method: 'POST', body }); stEl.textContent = 'Saved.'; toast('Tara settings saved'); D = null; tara(h); } catch (x) { stEl.textContent = err(x); }
    };
    let timer = null;
    $('#tcQ', h).addEventListener('input', e => { clearTimeout(timer); timer = setTimeout(() => { C.q = e.target.value.trim(); C.page = 1; convs(h); }, 300); });
    const cb = $('#tcBar', h); cb.addEventListener('change', e => { const rg = K.rangeChange(e, cb); if (rg) { [C.from, C.to] = rg; C.page = 1; convs(h); } });
    K.wireDownload(cb, fmt => K.exportUrl('/api/admin/tara/conversations', { q: C.q, from: C.from, to: C.to }, fmt));
    const tb = $('#ttBar', h);
    tb.addEventListener('change', e => { if (e.target.id === 'ttS') { T.status = e.target.value; T.page = 1; tools(h); return; } if (e.target.id === 'ttA') { T.tool = e.target.value; T.page = 1; tools(h); return; } const rg = K.rangeChange(e, tb); if (rg) { [T.from, T.to] = rg; T.page = 1; tools(h); } });
    K.wireDownload(tb, fmt => K.exportUrl('/api/admin/tara/tools', { status: T.status, tool: T.tool, from: T.from, to: T.to }, fmt));
    convs(h); tools(h);
  }

  async function convs(h) {
    const tbl = $('#tcT', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(C.page), per: String(C.per) }); if (C.q) qs.set('q', C.q); if (C.from) qs.set('from', C.from); if (C.to) qs.set('to', C.to);
      const d = await api('/api/admin/tara/conversations?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Customer</th><th>Started with</th><th class="r">Messages</th><th>Last message</th><th></th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${E(r.name)}<br><small>${E(r.email)}</small></td><td>${E(r.title)}</td><td class="r">${n(r.messages)}</td><td>${E(String(r.last_at || '').slice(0, 16))}</td><td><button class="button small secondary" data-cv="${E(r.id)}">Open</button></td></tr>`).join('')}</tbody>` : '<tbody><tr><td>No conversations yet.</td></tr></tbody>';
      tbl.querySelectorAll('[data-cv]').forEach(b => { b.onclick = () => openConv(b.dataset.cv); });
      K.pager($('#tcP', h), d, (p, per) => { C.page = p; C.per = per; convs(h); });
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  async function openConv(id) {
    try {
      const d = await api('/api/admin/tara/conversation?id=' + encodeURIComponent(id));
      modal(`Chat with ${d.conversation.name}`, `<p class="ops-note" style="margin-top:0">${E(d.conversation.email)} · started ${E(String(d.conversation.created_at).slice(0, 16))}</p>
        ${d.messages.map(m => `<div style="margin:8px 0;padding:8px 10px;border-radius:10px;background:${m.role === 'user' ? 'var(--soft,#eef2ff)' : 'var(--paper,#fff)'};border:1px solid var(--line,#e2e8f0)"><small><b>${m.role === 'user' ? 'Customer' : 'Tara'}</b> · ${E(String(m.created_at).slice(0, 16))}${m.rating ? (m.rating > 0 ? ' · 👍' : ' · 👎') : ''}</small><div style="white-space:pre-wrap">${E(m.content || '(removed)')}</div></div>`).join('')}
        ${d.tools.length ? `<h4>Actions Tara ran</h4><ul>${d.tools.map(t => `<li>${E((D && D.tools[t.tool]) || t.tool)}: <b>${t.status === 'ok' ? 'worked' : 'failed'}</b>${t.error ? ' (' + E(t.error) + ')' : ''} · ${n(t.ms)} ms</li>`).join('')}</ul>` : ''}`);
    } catch (e) { toast(err(e)); }
  }

  async function tools(h) {
    const tbl = $('#ttT', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(T.page), per: String(T.per) }); if (T.status !== 'all') qs.set('status', T.status); if (T.tool !== 'all') qs.set('tool', T.tool); if (T.from) qs.set('from', T.from); if (T.to) qs.set('to', T.to);
      const d = await api('/api/admin/tara/tools?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>When</th><th>Customer</th><th>Action</th><th>Result</th><th>Reason</th><th class="r">Time</th></tr></thead><tbody>${d.rows.map(r => `<tr><td>${E(String(r.created_at).slice(0, 16))}</td><td>${E(r.email || '')}</td><td>${E(D.tools[r.tool] || r.tool)}</td><td>${r.status === 'ok' ? 'Worked' : '<b>Failed</b>'}</td><td>${E(r.error)}</td><td class="r">${n(r.ms)} ms</td></tr>`).join('')}</tbody>` : '<tbody><tr><td>Nothing here.</td></tr></tbody>';
      K.pager($('#ttP', h), d, (p, per) => { T.page = p; T.per = per; tools(h); });
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  K.register('tara', tara, ['💬', 'Tara chat', 'The customers\' career assistant: switch, limits, spending, conversations and failed actions.']);
})();
