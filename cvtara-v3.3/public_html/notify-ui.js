(() => {
  'use strict';
  // Status reminders for jobs in the Applications tracker: shown as cards after login, and also sent by email. Three per job, then they stop.
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  if ((document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest') return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  let box;
  function host() {
    if (!box) { box = document.createElement('aside'); box.id = 'cvNotes'; box.setAttribute('aria-label', 'Reminders'); document.body.append(box); }
    return box;
  }
  async function dismiss(ids) { try { await api('/api/notifications', { method: 'POST', body: { ids } }); } catch { /* shown again next time */ } }
  function draw(list) {
    const b = host();
    b.innerHTML = list.slice(0, 3).map(n => `<article class="cv-note" role="status" data-id="${E(n.id)}"><button type="button" class="cv-note-x" data-x aria-label="Dismiss reminder">×</button><b>${E(n.title)}</b><p>${E(n.body)}</p><div><button type="button" class="button primary small" data-go>Update status</button><button type="button" class="button secondary small" data-x>Dismiss</button></div></article>`).join('');
    const count = document.querySelector('[data-workspace-view="tracker"]');
    if (count) { let k = count.querySelector('.cv-note-badge'); if (!k && list.length) { k = document.createElement('em'); k.className = 'cv-note-badge'; count.append(k); } if (k) { k.textContent = String(list.length); k.hidden = !list.length; } }
  }
  async function check() {
    try {
      const { notifications } = await api('/api/notifications');
      draw(notifications || []);
    } catch { /* reminders are optional */ }
  }
  document.addEventListener('click', e => {
    const card = e.target.closest('.cv-note'); if (!card) return;
    if (e.target.closest('[data-go]')) { const nav = document.querySelector('[data-workspace-view="tracker"]'); if (nav) nav.click(); else location.href = '/workspace.html?view=tracker'; dismiss([card.dataset.id]); card.remove(); }
    else if (e.target.closest('[data-x]')) { dismiss([card.dataset.id]); card.remove(); }
  });
  addEventListener('load', () => setTimeout(check, 1200));
  setInterval(check, 10 * 60 * 1000);
})();
