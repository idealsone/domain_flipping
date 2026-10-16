(() => {
  'use strict';
  // Recent-activity notices. They show real things only: buyers who chose to be named (first name and city), and live counts.
  const page = document.body?.dataset.page;
  if (!document.body || ['admin', 'setup'].includes(page) || /[?&]nofomo/.test(location.search)) return;
  const KEY = 'cvtara-fomo';
  let st = {};
  try { st = JSON.parse(sessionStorage.getItem(KEY) || '{}'); } catch { /* ignore */ }
  const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(st)); } catch { /* ignore */ } };
  if (st.off) return;
  if (!st.start) { st.start = Date.now(); save(); }
  const here = (document.currentScript && document.currentScript.src) || '';
  if (!document.querySelector('link[data-cvp-css]')) { const l = Object.assign(document.createElement('link'), { rel: 'stylesheet', href: here ? here.replace(/fomo-popup\.js(\?.*)?$/, 'promo.css') : '/promo.css' }); l.setAttribute('data-cvp-css', '1'); document.head.append(l); }
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const COLORS = ['#2258d6', '#0f766e', '#c2410c', '#7c3aed', '#be123c', '#0369a1', '#4d7c0f'];
  const ago = (iso, now) => { const s = Math.max(0, (new Date(now).getTime() - new Date(iso).getTime()) / 1000); if (s < 90) return 'just now'; const m = Math.round(s / 60); if (m < 60) return m + ' minutes ago'; const h = Math.round(m / 60); if (h < 24) return h + (h === 1 ? ' hour ago' : ' hours ago'); const d = Math.round(h / 24); return d + (d === 1 ? ' day ago' : ' days ago'); };
  const html = (m, now) => {
    if (m.kind === 'purchase') {
      const c = COLORS[(m.name.charCodeAt(0) || 0) % COLORS.length];
      return `<span class="fomo-av" style="background:${c}" aria-hidden="true">${E(m.name.charAt(0).toUpperCase())}</span><div><b>${E(m.name)}${m.city ? ' from ' + E(m.city) : ''}</b><p>started <strong>${E(m.plan)}</strong></p><small><span class="fomo-dot" aria-hidden="true"></span>${E(ago(m.at, now))} · ✓ Verified purchase</small></div><button type="button" aria-label="Dismiss notifications">×</button>`;
    }
    return `<span class="fomo-ic" aria-hidden="true">${E(m.icon || '✨')}</span><div><b>${E(m.title)}</b><p>${E(m.text)}</p>${m.href ? `<a href="${E(m.href)}">View plan →</a>` : ''}</div><button type="button" aria-label="Dismiss notifications">×</button>`;
  };
  fetch('/api/public-activity', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(r => r.json()).then(d => {
    if (!d.ok || !d.settings.enabled || !d.messages.length) return;
    // real purchases first, then counts and custom notes
    const msgs = d.messages.slice().sort((a, b) => (b.kind === 'purchase') - (a.kind === 'purchase'));
    const el = document.createElement('aside');
    el.className = 'fomo';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    document.body.append(el);
    let i = (st.i || 0) % msgs.length, hover = false, timer;
    el.addEventListener('mouseenter', () => { hover = true; });
    el.addEventListener('mouseleave', () => { hover = false; });
    const cycle = () => {
      if (document.querySelector('.cvp-overlay') || document.visibilityState === 'hidden') { timer = setTimeout(cycle, 4000); return; } // never on top of an offer pop-up
      const m = msgs[i];
      el.innerHTML = html(m, d.now || Date.now());
      el.querySelector('button').onclick = () => { st.off = true; save(); clearTimeout(timer); el.classList.remove('show'); setTimeout(() => el.remove(), 600); };
      el.classList.add('show');
      timer = setTimeout(function hide() {
        if (hover) { timer = setTimeout(hide, 1500); return; }
        el.classList.add('hide'); setTimeout(() => el.classList.remove('show', 'hide'), 420);
        i = (i + 1) % msgs.length; st.i = i; save();
        timer = setTimeout(cycle, Math.max(3, d.settings.gapSeconds) * 1000);
      }, Math.max(3, d.settings.showSeconds) * 1000);
    };
    const wait = Math.max(0, d.settings.delaySeconds * 1000 - (Date.now() - st.start));
    timer = setTimeout(cycle, wait);
  }).catch(() => {});
})();
