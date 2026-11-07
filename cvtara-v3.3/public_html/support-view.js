/* CVTara read-only customer view banner (3.40.0). Shown only to a team member who opened a customer's account from a
   support ticket. The server enforces read-only; this banner just says so, counts down and offers Exit. */
(() => {
  'use strict';
  const E = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  async function token() {
    if (typeof window.CVTaraCSRF === 'function') return window.CVTaraCSRF();
    const r = await fetch('/api/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return (await r.json()).token || '';
  }
  async function exit() {
    try { await fetch('/api/support-view/end', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': await token() }, body: '{}' }); } catch { /* ignore */ }
    location.href = '/admin.html';
  }
  fetch('/api/support-view', { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null).then(j => {
    if (!j || !j.active) return;
    let left = Number(j.secondsLeft) || 0;
    const bar = document.createElement('div');
    bar.id = 'cvSupportView'; bar.setAttribute('role', 'status');
    bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:2147483000;background:#b45309;color:#fff;font:600 14px/1.4 system-ui,sans-serif;padding:10px 16px;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap;box-shadow:0 -2px 10px rgba(0,0,0,.25)';
    const who = E(j.customer && (j.customer.name || j.customer.email));
    const draw = () => { const m = Math.floor(left / 60), s = String(left % 60).padStart(2, '0'); bar.querySelector('[data-t]').textContent = `${m}:${s}`; };
    bar.innerHTML = `<span>Viewing ${who} (${E(j.customer && j.customer.email)}) · read-only · ends in <b data-t></b></span><button type="button" style="background:#fff;color:#7c2d12;border:0;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer">Exit view</button>`;
    bar.querySelector('button').addEventListener('click', exit);
    document.body.appendChild(bar); document.body.style.paddingBottom = '56px'; draw();
    const t = setInterval(() => { left -= 1; if (left <= 0) { clearInterval(t); location.href = '/admin.html'; return; } draw(); }, 1000);
  }).catch(() => {});
})();
