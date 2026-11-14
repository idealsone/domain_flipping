/* Owner console: executive numbers on the Overview, owner alerts and payment reconciliation (3.43.0). Every number comes
   from GET /api/admin/metrics, with its written definition; a number that is not tracked says so instead of showing 0. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const fmt = m => !m.tracked ? '<span class="muted" style="font-size:14px">not tracked</span>' : m.unit === 'money' ? '₹' + Math.round(m.value / 100).toLocaleString('en-IN') : m.unit === 'percent' ? m.value + '%' : Number(m.value).toLocaleString('en-IN');
  const time = s => new Date(s).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Kolkata' });
  let days = 30, busy = false;
  try { days = +localStorage.getItem('cvtara-kpi-days') || 30; } catch { /* private window */ }

  async function panel() {
    const host = $('#admin-dashboard'); if (!host || busy) return;
    let box = $('#kpiPanel');
    if (!box) { box = document.createElement('section'); box.id = 'kpiPanel'; box.className = 'panel panel-pad'; box.style.marginBottom = '18px'; const banner = host.querySelector(':scope > .ops-banner'); banner ? banner.after(box) : host.prepend(box); }
    busy = true;
    let j; try { j = await api('/api/admin/metrics?days=' + days); } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; busy = false; return; } finally { busy = false; }
    const who = await (window.CVTaraWho || Promise.resolve(null)).catch(() => null);
    const canRecon = !who || who.role === 'owner' || (who.can && who.can('orders.view'));
    box.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0;font-size:18px">Business numbers</h2>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><span class="muted" style="font-size:12.5px">Worked out at ${E(time(j.at))} · India time</span>${[[1, 'Today'], [7, '7 days'], [30, '30 days'], [90, '90 days']].map(([d, l]) => `<button type="button" class="button ${d === j.days ? 'primary' : 'secondary'} small" data-kpi-days="${d}">${l}</button>`).join('')}</div></div>
      ${j.alerts.length ? `<ul style="list-style:none;margin:12px 0 0;padding:0;display:grid;gap:6px">${j.alerts.map(a => `<li role="status" style="padding:8px 12px;border-radius:8px;border:1px solid ${a.level === 'high' ? '#fca5a5' : '#fcd34d'};background:${a.level === 'high' ? 'rgba(239,68,68,.08)' : 'rgba(245,158,11,.08)'}">${a.level === 'high' ? '⚠️' : '•'} ${E(a.text)}</li>`).join('')}</ul>` : ''}
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-top:12px">${j.metrics.map(m => `<div style="border:1px solid var(--line);border-radius:10px;padding:10px 12px" title="${E(m.definition)}"><small class="muted">${E(m.label)}</small><div style="font-size:20px;font-weight:800;margin-top:2px">${fmt(m)}</div><details style="margin-top:4px"><summary class="muted" style="font-size:12px;cursor:pointer">How it is counted</summary><small>${E(m.definition)}</small></details></div>`).join('')}</div>
      ${canRecon ? '<p style="margin:12px 0 0"><button type="button" class="button secondary small" data-recon>Payment reconciliation</button></p>' : ''}`;
    box.querySelectorAll('[data-kpi-days]').forEach(b => b.onclick = () => { days = +b.dataset.kpiDays; try { localStorage.setItem('cvtara-kpi-days', String(days)); } catch { /* ignore */ } panel(); });
    const rb = box.querySelector('[data-recon]'); if (rb) rb.onclick = () => recon(who);
  }

  async function recon(who) {
    const owner = !who || who.role === 'owner';
    const m = modal('Payment reconciliation', '<div class="ops-skel"></div>', { wide: true });
    const draw = async () => {
      let j; try { j = await api('/api/admin/reconciliation'); } catch (e) { m.body.innerHTML = `<p role="alert">${E(err(e))}</p>`; return; }
      const row = x => `<tr><td>${E(x.day)}</td><td>${E(x.provider)}</td><td>${E({ paid_not_recorded: 'Paid at gateway, not in CVTara', recorded_not_paid: 'In CVTara, not at gateway', amount_differs: 'Amounts differ' }[x.kind] || x.kind)}</td><td><code>${E(x.payment_reference)}</code></td><td>${x.gateway_paise != null ? '₹' + (x.gateway_paise / 100) : '—'} / ${x.recorded_paise != null ? '₹' + (x.recorded_paise / 100) : '—'}</td><td>${E(x.note)}</td>${owner && !x.resolved_at ? `<td><button type="button" class="button secondary small" data-res="${x.id}">Mark checked</button></td>` : '<td></td>'}</tr>`;
      m.body.innerHTML = `<p class="muted" style="margin:0 0 10px">Every morning after 6 AM, yesterday's captured Razorpay payments are compared with CVTara's orders, and PhonePe orders still open are checked. Nothing is charged or refunded here.${j.last ? ` Last run: ${E(j.last.day)}, ${E(j.last.mismatches)} new mismatch(es).` : ' Not run yet.'}</p>
        ${owner ? `<form data-run style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0 0 12px"><label>Check a day <input type="date" name="day" required></label><button class="button secondary small" type="submit">Run now</button><span data-msg class="muted"></span></form>` : ''}
        <h4 style="margin:0 0 6px">Open (${j.open.length})</h4>${j.open.length ? `<div style="overflow:auto"><table class="ad-table"><thead><tr><th>Day</th><th>Gateway</th><th>What</th><th>Payment</th><th>Gateway / CVTara</th><th>Note</th><th></th></tr></thead><tbody>${j.open.map(row).join('')}</tbody></table></div>` : '<p class="muted">No open mismatches.</p>'}
        ${j.resolved.length ? `<details><summary>Checked (${j.resolved.length})</summary><div style="overflow:auto"><table class="ad-table"><tbody>${j.resolved.map(row).join('')}</tbody></table></div></details>` : ''}`;
      m.body.querySelectorAll('[data-res]').forEach(b => b.onclick = async () => {
        const note = prompt('What did you find? (for example: webhook was late, order paid by hand)'); if (!note || note.trim().length < 5) return;
        if (!(await ensureRecent())) return;
        try { await api(`/api/admin/reconciliation/${b.dataset.res}/resolve`, { method: 'POST', body: { note } }); toast('Marked checked'); draw(); } catch (e) { toast(err(e), true); }
      });
      const f = m.body.querySelector('[data-run]');
      if (f) f.onsubmit = async e => { e.preventDefault(); const msg = f.querySelector('[data-msg]'); msg.textContent = 'Checking…'; if (!(await ensureRecent())) { msg.textContent = ''; return; } try { const r = await api('/api/admin/reconciliation/run', { method: 'POST', body: { day: f.day.value } }); msg.textContent = `${r.result.mismatches} new mismatch(es).`; draw(); } catch (x) { msg.textContent = err(x); } };
    };
    draw();
  }

  // The Overview is drawn by ops.js; add the panel whenever it is (re)drawn.
  const watch = () => { const h = $('#admin-dashboard'); if (!h) return; new MutationObserver(() => { const box = $('#kpiPanel'), bn = h.querySelector(':scope > .ops-banner'); if (!box && h.querySelector(':scope > :not(.ops-skel)')) panel(); else if (box && bn && bn.nextElementSibling !== box) bn.after(box); }).observe(h, { childList: true }); if (!$('#kpiPanel')) panel(); };
  addEventListener('load', () => setTimeout(watch, 1500));
})();
