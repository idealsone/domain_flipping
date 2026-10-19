(() => {
  'use strict';
  // Owner console: 7-day refund requests and the reviewer queue for Tara Expert Review and Profile Boost.
  const host = document.getElementById('admin-commerce');
  if (!host || !window.CVTaraAPI) return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, body) => window.CVTaraAPI.request('/api/admin/' + p, body === undefined ? undefined : { method: 'POST', body });
  const inr = p => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2 });
  const day = v => v ? new Date(String(v).replace(' ', 'T') + (String(v).includes('T') ? '' : 'Z')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  const RS = { requested: 'Waiting for you', approved: 'Sent to gateway', processed: 'Refunded', rejected: 'Declined' };
  const SV = { not_started: 'Not started', in_review: 'In review', delivered: 'Delivered', cancelled: 'Cancelled' };
  const box = document.createElement('section');
  box.id = 'adminRefunds'; box.className = 'panel panel-pad purchase-panel';
  host.prepend(box);
  async function draw() {
    box.innerHTML = '<p>Loading refunds and service orders…</p>';
    try {
      const [rf, sv] = await Promise.all([api('refunds'), api('services')]);
      box.innerHTML = `<div class="admin-heading"><div><span class="eyebrow">REFUNDS &amp; SERVICES</span><h2>7-day refunds</h2><p>Customers can ask for a full refund within ${rf.days} days if nothing paid for was used, once per customer. Approve sends the money back through the gateway; access ends when the gateway confirms.</p></div></div>
        <label class="ref-check"><input type="checkbox" data-auto ${rf.settings.autoApprove ? 'checked' : ''}> Approve eligible requests automatically</label><p data-st role="status"></p>
        <div class="purchase-scroll"><table class="purchase-table"><thead><tr><th>Customer</th><th>Order</th><th>Amount</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>${rf.requests.length ? rf.requests.map(r => `<tr><td>${E(r.name)}<small>${E(r.email)}</small></td><td>${E(r.plan)}<small>${E(r.provider)} · bought ${day(r.purchased_at)}</small></td><td>${inr(r.amount_paise)}</td><td>${E(r.reason || '—')}</td><td>${E(RS[r.status] || r.status)}${r.note ? `<small>${E(r.note)}</small>` : ''}</td><td>${r.status === 'requested' ? `<button class="button primary small" data-ok="${E(r.id)}">Approve</button> <button class="button secondary small" data-no="${E(r.id)}">Decline</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="6">No refund requests yet.</td></tr>'}</tbody></table></div>
        <h2 style="margin-top:26px">Reviewer queue</h2><p>Tara Expert Review and Profile Boost orders. Aim to deliver within 48 working hours. Once an order is in review it can no longer be refunded.</p>
        <div class="purchase-scroll"><table class="purchase-table"><thead><tr><th>Customer</th><th>Service</th><th>Ordered</th><th>Status</th><th></th></tr></thead><tbody>${sv.orders.length ? sv.orders.map(o => `<tr><td>${E(o.name)}<small>${E(o.email)}</small></td><td>${E(o.service)}</td><td>${day(o.created_at)}</td><td>${E(SV[o.status] || o.status)}${o.note ? `<small>${E(o.note)}</small>` : ''}</td><td>${o.status === 'not_started' ? `<button class="button primary small" data-sv="${E(o.id)}" data-to="in_review">Start review</button>` : o.status === 'in_review' ? `<button class="button primary small" data-sv="${E(o.id)}" data-to="delivered">Mark delivered</button>` : ''}</td></tr>`).join('') : '<tr><td colspan="5">No service orders yet. Put Tara Expert Review or Profile Boost on sale in Pricing to start taking them.</td></tr>'}</tbody></table></div>`;
      const st = box.querySelector('[data-st]');
      const run = async (fn, done) => { st.textContent = 'Saving…'; try { await fn(); st.textContent = done; await draw(); } catch (e) { st.textContent = e.message; } };
      box.querySelector('[data-auto]').onchange = e => run(() => api('refunds/settings', { autoApprove: e.target.checked }), 'Saved.');
      box.querySelectorAll('[data-ok]').forEach(b => b.onclick = () => confirm('Send the full amount back to the customer now?') && run(() => api(`refunds/${b.dataset.ok}/approve`, {}), 'Refund sent to the gateway.'));
      box.querySelectorAll('[data-no]').forEach(b => b.onclick = () => { const note = prompt('Why is this refund declined? The customer sees this.'); if (note) run(() => api(`refunds/${b.dataset.no}/reject`, { note }), 'Declined.'); });
      box.querySelectorAll('[data-sv]').forEach(b => b.onclick = () => { const to = b.dataset.to; const note = to === 'delivered' ? prompt('What was delivered? (for example "Review sent by email on 20 Oct")') : ''; if (to === 'delivered' && !note) return; run(() => api('services/' + b.dataset.sv, { status: to, note }), 'Updated.'); });
    } catch (e) { box.innerHTML = `<p role="alert">${E(e.message)}</p>`; }
  }
  document.addEventListener('click', e => { if (e.target.closest('[data-admin-view="commerce"]')) draw(); });
  if (host.classList.contains('active')) draw(); else box.innerHTML = '<p>Open this page to load refunds and service orders.</p>';
})();
