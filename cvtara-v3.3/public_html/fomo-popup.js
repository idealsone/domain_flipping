(() => {
  'use strict';
  const page = document.body?.dataset.page;
  if (!document.body || ['admin', 'setup'].includes(page) || /[?&]nofomo/.test(location.search)) return;
  const KEY = 'cvtara-fomo';
  let st = {};
  try { st = JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch {}
  const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(st)); } catch {} };
  if (st.off) return;
  if (!st.start) { st.start = Date.now(); save(); }
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  fetch('/api/public-activity', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(r => r.json()).then(d => {
    if (!d.ok || !d.settings.enabled || !d.messages.length) return;
    const el = document.createElement('aside');
    el.className = 'fomo';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.append(el);
    let i = (st.i || 0) % d.messages.length, hover = false, timer;
    el.addEventListener('mouseenter', () => hover = true);
    el.addEventListener('mouseleave', () => hover = false);
    const cycle = () => {
      const m = d.messages[i];
      el.innerHTML = `<i aria-hidden="true">${E(m.icon || '✨')}</i><div><b>${E(m.title)}</b><p>${E(m.text)}</p>${m.href ? `<a href="${E(m.href)}">View plan →</a>` : ''}</div><button type="button" aria-label="Dismiss notifications">×</button>`;
      el.querySelector('button').onclick = () => { st.off = true; save(); clearTimeout(timer); el.classList.remove('show'); setTimeout(() => el.remove(), 600); };
      el.classList.add('show');
      timer = setTimeout(function hide() {
        if (hover) { timer = setTimeout(hide, 1500); return; }
        el.classList.remove('show');
        i = (i + 1) % d.messages.length; st.i = i; save();
        timer = setTimeout(cycle, Math.max(3, d.settings.gapSeconds) * 1000);
      }, Math.max(3, d.settings.showSeconds) * 1000);
    };
    const wait = Math.max(0, d.settings.delaySeconds * 1000 - (Date.now() - st.start));
    timer = setTimeout(cycle, wait);
  }).catch(() => {});
})();
