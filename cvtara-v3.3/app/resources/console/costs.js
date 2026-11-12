/* Owner console: Costs & margin (3.56.0, V7 §24-§26). AI spending this month by feature against its cap, today's
   highest-spending customers, caps and alert levels, the provider's monthly invoice against our own cost log, and the
   contribution margin per plan and add-on (net collected revenue minus GST share, gateway fees, AI, fulfilment and
   support cost). Paid features are never stopped by a cap; only the free helpers pause for one customer for the day. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const rs = p => p === null || p === undefined ? '—' : '₹' + (Number(p) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 });
  const iso = d => d.toISOString().slice(0, 10);
  let range = { from: iso(new Date(Date.now() - 29 * 864e5)), to: iso(new Date()) };

  async function load() {
    const h = $('#admin-costs'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    let d; try { d = await api('/api/admin/costs'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const s = d.settings, b = d.budget || {};
    const caps = Object.entries(s.featureMonthlyInr || {});
    h.innerHTML = `<div class="pg-head"><h2>Costs &amp; margin</h2><p>What AI and payments cost, and what each plan and add-on leaves after costs. Paid features are never stopped by these caps; Tara chat and writing help pause for a customer who reaches the daily cap.</p></div>
      <div class="ops-kpis"><div class="kpi"><span>AI this month</span><b>${rs(b.spentPaise)}</b></div><div class="kpi"><span>Monthly growth cap</span><b>${b.capPaise ? rs(b.capPaise) : 'Not set'}</b></div><div class="kpi"><span>Daily cap per customer</span><b>₹${E(s.userDailyInr)}</b></div><div class="kpi"><span>Order alert</span><b>${E(s.orderPct)}% of price</b></div></div>
      <section class="panel panel-pad"><h3>This month by feature (${E(d.month)})</h3>${d.features.length ? `<table class="admin-table"><thead><tr><th>Feature</th><th>Requests</th><th>Cost</th><th>Monthly cap</th></tr></thead><tbody>${d.features.map(f => `<tr><td>${E(f.purpose)}</td><td>${f.calls}</td><td>${rs(f.paise)}</td><td>${f.capPaise ? rs(f.capPaise) + (f.paise >= f.capPaise ? ' <span class="pill warn">reached</span>' : '') : '—'}</td></tr>`).join('')}</tbody></table>` : '<p>No AI requests this month yet.</p>'}</section>
      <section class="panel panel-pad"><h3>Highest AI use today</h3>${d.topToday.length ? `<table class="admin-table"><thead><tr><th>Customer</th><th>Requests</th><th>Cost</th></tr></thead><tbody>${d.topToday.map(t => `<tr><td>${E(t.email || '#' + t.ownerId)}</td><td>${t.calls}</td><td>${rs(t.paise)}${t.paise >= s.userDailyInr * 100 ? ' <span class="pill warn">cap reached</span>' : ''}</td></tr>`).join('')}</tbody></table>` : '<p>No customer AI use today.</p>'}</section>
      <section class="panel panel-pad"><h3>Caps and costs per order</h3><form id="cxSet" class="ops-form">
        <label>Daily AI cap per customer (₹)<input name="userDailyInr" type="number" min="0" step="1" value="${E(s.userDailyInr)}"></label>
        <label>Alert when an order's AI cost passes (% of its price)<input name="orderPct" type="number" min="0" max="100" value="${E(s.orderPct)}"></label>
        <label>Gateway fee (% of each payment, with GST)<input name="gatewayPct" type="number" min="0" max="20" step="0.01" value="${E(s.gatewayPct)}"></label>
        <label>GST share in prices (%; 0 if not registered)<input name="gstPct" type="number" min="0" max="28" step="0.01" value="${E(s.gstPct)}"></label>
        <label class="wide">Monthly cap per feature, one per line (feature = ₹), e.g. customer:expert_review = 2000<textarea name="feature" rows="3">${E(caps.map(([k, v]) => k + ' = ' + v).join('\n'))}</textarea></label>
        <label class="wide">Fulfilment cost per order, one per line (plan key = ₹), e.g. pro = 20<textarea name="fulfil" rows="2">${E(Object.entries(s.fulfilmentInr || {}).map(([k, v]) => k + ' = ' + v).join('\n'))}</textarea></label>
        <label class="wide">Support cost per order, one per line (plan key = ₹)<textarea name="support" rows="2">${E(Object.entries(s.supportInr || {}).map(([k, v]) => k + ' = ' + v).join('\n'))}</textarea></label>
        <div class="wide"><button class="button primary">Save</button> <span role="status"></span></div></form></section>
      <section class="panel panel-pad"><h3>Invoice check</h3><p class="muted">Enter the provider's invoice total for a month (in rupees, as charged to your card). The difference shows how far our own cost log is from the bill.</p>
        <table class="admin-table"><thead><tr><th>Month</th><th>Our cost log</th><th>Invoice</th><th>Difference</th></tr></thead><tbody>${d.invoices.map(i => `<tr><td>${E(i.month)}</td><td>${rs(i.oursPaise)}</td><td>${rs(i.invoicePaise)}</td><td>${i.diffPaise === null ? '—' : rs(i.diffPaise) + (i.diffPct === null ? '' : ` (${i.diffPct > 0 ? '+' : ''}${i.diffPct}%)`) + (i.diffPct !== null && Math.abs(i.diffPct) > 10 ? ' <span class="pill warn">check</span>' : '')}</td></tr>`).join('')}</tbody></table>
        <form id="cxInv" class="ops-form"><label>Month<input name="month" type="month" required value="${E(d.month)}"></label><label>Invoice total (₹)<input name="amountInr" type="number" min="0" step="0.01" required></label><label>Note<input name="note" maxlength="300" placeholder="Invoice number"></label><div class="wide"><button class="button secondary">Save invoice</button> <span role="status"></span></div></form></section>
      <section class="panel panel-pad"><h3>Contribution margin</h3><div class="ops-filterbox"><label class="ops-f">From<input type="date" id="cxFrom" value="${range.from}"></label><label class="ops-f">To<input type="date" id="cxTo" value="${range.to}"></label><a class="button small secondary" id="cxCsv" href="#">Download CSV</a> <a class="button small secondary" id="cxXls" href="#">Excel</a></div><div id="cxMargin"><p>Loading…</p></div>
        <p class="muted">Net is what was collected minus refunds. AI cost is each buyer's AI use while that order was active. Storage, bandwidth and payroll are not included yet.</p></section>
      <section class="panel panel-pad" id="cxTest"><h3>Test payments up to 9 November</h3><p class="muted">Every order made up to 9 November 2026 (India time), with a suggestion worked out by fixed rules: test mode, demo checkout, paid from the owner or a staff account, ₹10 or less, a test-looking email, or a full refund within 2 days. Mark each one <b>Test</b> or <b>Real</b>. Nothing is deleted or changed on the order; orders you mark Test are left out of revenue figures.</p>
        <div class="ops-kpis" id="cxTK"></div>
        <div class="ops-filterbox"><label class="ops-f">Suggested<select id="cxTS"><option value="">All</option><option value="test">Looks like a test</option><option value="real">Looks real</option><option value="not_paid">No money taken</option><option value="grant">Free grant or reward</option></select></label>
          <label class="ops-f">Your mark<select id="cxTM"><option value="">All</option><option value="test">Test</option><option value="real">Real</option></select></label>
          <label class="ops-f">Search<input id="cxTQ" placeholder="Email, order or reference"></label>
          <a class="button secondary small" id="cxTCsv">CSV</a> <a class="button secondary small" id="cxTXls">Excel</a></div>
        <div id="cxTT"></div><div id="cxTP"></div>
        <p class="ops-row"><button class="button secondary small" data-cxt-bulk="test">Mark ticked as Test</button> <button class="button secondary small" data-cxt-bulk="real">Mark ticked as Real</button> <button class="button secondary small" data-cxt-accept>Accept the suggestion for ticked rows</button></p></section>`;
    const lines = t => Object.fromEntries(String(t || '').split('\n').map(l => l.split('=').map(x => x.trim())).filter(p => p.length === 2 && p[0] && p[1] !== '' && !isNaN(+p[1])).map(([k, v]) => [k, +v]));
    $('#cxSet').onsubmit = async e => { e.preventDefault(); const f = e.target, st = f.querySelector('[role=status]'); st.textContent = 'Saving…';
      try { await api('/api/admin/costs/settings', { method: 'POST', body: { userDailyInr: +f.elements.userDailyInr.value, orderPct: +f.elements.orderPct.value, gatewayPct: +f.elements.gatewayPct.value, gstPct: +f.elements.gstPct.value, featureMonthlyInr: lines(f.elements.feature.value), fulfilmentInr: lines(f.elements.fulfil.value), supportInr: lines(f.elements.support.value) } }); toast('Saved.'); load(); } catch (x) { st.textContent = err(x); } };
    $('#cxInv').onsubmit = async e => { e.preventDefault(); const f = e.target, st = f.querySelector('[role=status]'); st.textContent = 'Saving…';
      try { await api('/api/admin/costs/invoice', { method: 'POST', body: { month: f.elements.month.value, amountInr: +f.elements.amountInr.value, note: f.elements.note.value } }); toast('Invoice saved.'); load(); } catch (x) { st.textContent = err(x); } };
    const dl = fmt => `/api/admin/report?dataset=margin&format=${fmt}&columns=ALL&from=${range.from}&to=${range.to}`;
    const sync = () => { $('#cxCsv').href = dl('csv'); $('#cxXls').href = dl('xlsx'); };
    ['#cxFrom', '#cxTo'].forEach(id => $(id).onchange = () => { range = { from: $('#cxFrom').value, to: $('#cxTo').value }; sync(); margin(); });
    sync(); margin(); tests();
  }

  // 3.61.0 Test payment review (V4.8 §6).
  const TQ = { page: 1, per: 20 };
  const tParams = () => new URLSearchParams({ page: TQ.page, per: TQ.per, suggested: $('#cxTS')?.value || '', mark: $('#cxTM')?.value || '', q: $('#cxTQ')?.value || '' });
  let tRows = [];
  async function tests() {
    const box = $('#cxTT'); if (!box) return;
    const q = tParams();
    $('#cxTCsv').href = '/api/admin/costs/test-payments?' + q + '&export=csv'; $('#cxTXls').href = '/api/admin/costs/test-payments?' + q + '&export=xlsx';
    try {
      const d = await api('/api/admin/costs/test-payments?' + q); tRows = d.rows;
      const m = d.summary;
      $('#cxTK').innerHTML = `<div class="kpi"><span>Orders up to 9 Nov</span><b>${m.orders}</b></div><div class="kpi"><span>Look like tests</span><b>${m.bySuggested.test || 0}</b></div><div class="kpi"><span>Look real</span><b>${m.bySuggested.real || 0}</b></div><div class="kpi"><span>Marked by you</span><b>${m.marked.test + m.marked.real}</b></div><div class="kpi"><span>Left out of revenue</span><b>${rs(m.testPaise)}</b></div>`;
      box.innerHTML = d.rows.length ? `<table class="admin-table"><thead><tr><th><input type="checkbox" id="cxTAll" aria-label="Tick all"></th><th>Created</th><th>Customer</th><th>Plan</th><th>Amount</th><th>Status</th><th>Gateway</th><th>Suggested</th><th>Your mark</th></tr></thead><tbody>${d.rows.map(r => `<tr>
        <td><input type="checkbox" data-cxt="${E(r.id)}"></td><td>${E(r.createdAt)}</td><td>${E(r.email)}<br><small class="muted">${E(r.id)}</small></td><td>${E(r.plan)}</td><td>${rs(r.amountPaise)}${r.refundedPaise ? '<br><small class="muted">refunded ' + rs(r.refundedPaise) + '</small>' : ''}</td><td>${E(r.status)}</td><td>${E(r.provider)}${r.live ? '' : ' <span class="pill">test mode</span>'}</td>
        <td>${E(d.classes[r.suggested] || r.suggested)}<br><small class="muted">${E(r.reasons)}</small></td>
        <td>${r.mark ? `<span class="pill ${r.mark === 'test' ? 'warn' : 'ok'}">${r.mark === 'test' ? 'Test' : 'Real'}</span> ` : ''}<button class="button small secondary" data-cxt-one="test" data-id="${E(r.id)}">Test</button> <button class="button small secondary" data-cxt-one="real" data-id="${E(r.id)}">Real</button>${r.mark ? ` <button class="button small secondary" data-cxt-one="clear" data-id="${E(r.id)}">Clear</button>` : ''}</td></tr>`).join('')}</tbody></table>` : '<p>No orders match.</p>';
      K.pager($('#cxTP'), d, (p, per) => { TQ.page = p; TQ.per = per; tests(); });
      const all = $('#cxTAll'); if (all) all.onchange = () => document.querySelectorAll('[data-cxt]').forEach(c => { c.checked = all.checked; });
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }
  const tMark = async (ids, mark) => { if (!ids.length) { toast('Tick at least one order.'); return; } try { const r = await api('/api/admin/costs/test-payments/mark', { method: 'POST', body: { ids, mark } }); toast(`${r.marked} order${r.marked === 1 ? '' : 's'} updated.`); tests(); } catch (e) { toast(err(e)); } };
  document.addEventListener('change', e => { if (e.target.matches?.('#cxTS, #cxTM')) { TQ.page = 1; tests(); } });
  let tTimer; document.addEventListener('input', e => { if (e.target.matches?.('#cxTQ')) { clearTimeout(tTimer); tTimer = setTimeout(() => { TQ.page = 1; tests(); }, 300); } });
  document.addEventListener('click', e => {
    const b = e.target.closest?.('[data-cxt-one],[data-cxt-bulk],[data-cxt-accept]'); if (!b || !$('#cxTest')?.contains(b)) return;
    const ticked = [...document.querySelectorAll('[data-cxt]:checked')].map(c => c.dataset.cxt);
    if (b.dataset.cxtOne) tMark([b.dataset.id], b.dataset.cxtOne);
    else if (b.dataset.cxtBulk) tMark(ticked, b.dataset.cxtBulk);
    else { const pick = tRows.filter(r => ticked.includes(r.id) && (r.suggested === 'test' || r.suggested === 'real')); const t = pick.filter(r => r.suggested === 'test').map(r => r.id), rl = pick.filter(r => r.suggested === 'real').map(r => r.id);
      (async () => { if (t.length) await tMark(t, 'test'); if (rl.length) await tMark(rl, 'real'); if (!t.length && !rl.length) toast('None of the ticked orders has a test or real suggestion.'); })(); }
  });

  async function margin() {
    const box = $('#cxMargin'); if (!box) return;
    try {
      const { rows } = await api(`/api/admin/costs/margin?from=${range.from}&to=${range.to}`);
      const t = k => rows.reduce((a, r) => a + r[k], 0);
      box.innerHTML = rows.length ? `<table class="admin-table"><thead><tr><th>Plan or add-on</th><th>Orders</th><th>Net</th><th>GST</th><th>Gateway</th><th>AI</th><th>Fulfilment + support</th><th>Margin</th><th>Margin %</th></tr></thead><tbody>${rows.map(r => `<tr><td>${E(r.name)}</td><td>${r.orders}</td><td>${rs(r.netPaise)}${r.refundsPaise ? `<br><small>${rs(r.refundsPaise)} refunded</small>` : ''}</td><td>${rs(r.gstPaise)}</td><td>${rs(r.feesPaise)}</td><td>${rs(r.aiPaise)}</td><td>${rs(r.fulfilmentPaise + r.supportPaise)}</td><td><b>${rs(r.marginPaise)}</b></td><td>${r.marginPct === null ? '—' : r.marginPct + '%'}</td></tr>`).join('')}<tr><td><b>Total</b></td><td>${t('orders')}</td><td>${rs(t('netPaise'))}</td><td>${rs(t('gstPaise'))}</td><td>${rs(t('feesPaise'))}</td><td>${rs(t('aiPaise'))}</td><td>${rs(t('fulfilmentPaise') + t('supportPaise'))}</td><td><b>${rs(t('marginPaise'))}</b></td><td>${t('netPaise') > 0 ? Math.round(t('marginPaise') / t('netPaise') * 1000) / 10 + '%' : '—'}</td></tr></tbody></table>` : '<p>No paid orders in these dates.</p>';
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  K.register('costs', load, ['💹', 'Costs & margin', 'AI spending, caps and alerts, the invoice check and the margin left by each plan and add-on.']);
})();
