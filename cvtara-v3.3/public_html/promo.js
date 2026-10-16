(() => {
  'use strict';
  // Offers and festival campaigns. The owner picks one in Owner console > Offers & festivals; this script shows it as a top bar and as pop-ups
  // (after a delay, when the visitor is about to leave, or when the visitor has gone quiet). The coupon travels with the visitor into sign-up and checkout.
  const body = document.body;
  const page = body && body.dataset.page;
  const admin = page === 'admin';
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const guest = (document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest';
  const store = { get(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch { return null; } }, set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch { /* storage blocked */ } } };
  const sess = { get(k) { try { return sessionStorage.getItem(k); } catch { return null; } }, set(k, v) { try { sessionStorage.setItem(k, v); } catch { /* storage blocked */ } } };
  // The stylesheet sits next to this script, wherever the site is served from.
  const here = (document.currentScript && document.currentScript.src) || '';
  if (!document.querySelector('link[data-cvp-css]')) { const l = Object.assign(document.createElement('link'), { rel: 'stylesheet', href: here ? here.replace(/promo\.js(\?.*)?$/, 'promo.css') : '/promo.css' }); l.setAttribute('data-cvp-css', '1'); document.head.append(l); }

  const style = v => `--pf:${E(v.theme.from)};--pt:${E(v.theme.to)};--pi:${E(v.theme.ink)};--pa:${E(v.theme.accent)}`;
  const left = ms => { if (ms <= 0) return ''; const s = Math.floor(ms / 1000), d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60); return (d ? `<b>${d}</b>d ` : '') + `<b>${String(h).padStart(2, '0')}</b>h <b>${String(m).padStart(2, '0')}</b>m <b>${String(s % 60).padStart(2, '0')}</b>s`; };
  function tick(root, v) {
    const el = root.querySelector('[data-count]'); if (!el || !v.endsAt) return;
    const end = new Date(v.endsAt).getTime(), run = () => { const t = left(end - Date.now()); el.innerHTML = t ? 'Ends in ' + t : 'Offer ended'; };
    run(); const i = setInterval(() => { if (!root.isConnected) return clearInterval(i); run(); }, 1000);
  }
  const dest = v => { const q = []; if (v.plan) q.push('plan=' + encodeURIComponent(v.plan)); if (v.code) q.push('coupon=' + encodeURIComponent(v.code)); return '/checkout.html' + (q.length ? '?' + q.join('&') : ''); };
  const send = (id, kind) => { if (admin) return; try { (window.CVTaraCSRF ? window.CVTaraCSRF() : Promise.resolve('')).then(t => fetch('/api/promo/event', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': t || '' }, body: JSON.stringify({ id, kind }) })).catch(() => {}); } catch { /* tracking is optional */ } };

  function copyCode(v, btn) {
    const done = () => { btn.textContent = 'Copied ✓'; setTimeout(() => { btn.textContent = 'Copy'; }, 1800); send(v.id, 'copy'); };
    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(v.code).then(done, done); else { const t = Object.assign(document.createElement('textarea'), { value: v.code }); document.body.append(t); t.select(); try { document.execCommand('copy'); } catch { /* ignore */ } t.remove(); done(); }
  }
  function claim(v) {
    send(v.id, 'click'); store.set('cvtara-promo-intent', { code: v.code, plan: v.plan, id: v.id, t: Date.now() });
    const to = dest(v); location.href = guest ? '/register?next=' + encodeURIComponent(to) : to;
  }

  // The offer card, used by the pop-up and by the owner's preview.
  function cardHtml(v, variant) {
    const o = (variant && v.variants[variant]) || {}, head = o.heading || v.heading, sub = o.text || v.subheading;
    const art = v.image ? `<div class="cvp-art img" style="background-image:linear-gradient(160deg,color-mix(in srgb,var(--pf) 55%,transparent),color-mix(in srgb,var(--pt) 70%,transparent)),url('${E(v.image)}')"></div>`
      : `<div class="cvp-art"><span class="cvp-emoji" aria-hidden="true">${E(v.theme.art)}</span>${'<i></i>'.repeat(14)}</div>`;
    return `<div class="cvp-card" style="${style(v)}" role="dialog" aria-modal="true" aria-label="${E(head)}">${art}
      <div class="cvp-body"><button type="button" class="cvp-x" data-close aria-label="Close offer">×</button>
        ${v.badge ? `<span class="cvp-badge">${E(v.badge)}</span>` : ''}<h2>${E(head)}</h2>${sub ? `<p class="cvp-sub">${E(sub)}</p>` : ''}${v.body ? `<p class="cvp-text">${E(v.body)}</p>` : ''}
        ${v.discount ? `<div class="cvp-off"><b>${E(v.discount)}</b><span>on ${v.plan ? 'your plan' : 'any plan'}</span></div>` : ''}
        ${v.code ? `<div class="cvp-code"><span>Use code</span><code>${E(v.code)}</code><button type="button" data-copy>Copy</button></div>` : ''}
        ${v.countdown ? '<p class="cvp-count" data-count></p>' : ''}
        <button type="button" class="cvp-cta" data-claim>${E(v.cta)} →</button><button type="button" class="cvp-no" data-close>No thanks, maybe later</button>
        <small class="cvp-fine">${v.code ? 'Your code is applied for you at checkout. ' : ''}All payments are final and non-refundable.</small></div></div>`;
  }
  let openRoot = null;
  function modal(v, variant, how) {
    if (openRoot || document.querySelector('dialog[open]')) return false;
    const root = document.createElement('div'); root.className = 'cvp-overlay'; root.innerHTML = cardHtml(v, variant); document.body.append(root); openRoot = root;
    const prev = document.activeElement; const close = () => { root.classList.add('out'); setTimeout(() => { root.remove(); openRoot = null; try { prev && prev.focus && prev.focus(); } catch { /* ignore */ } }, 180); document.removeEventListener('keydown', key); };
    const key = e => { if (e.key === 'Escape') { send(v.id, 'close'); close(); } };
    document.addEventListener('keydown', key);
    root.addEventListener('click', e => {
      if (e.target === root || e.target.closest('[data-close]')) { send(v.id, 'close'); close(); return; }
      if (e.target.closest('[data-copy]')) copyCode(v, e.target.closest('[data-copy]'));
      if (e.target.closest('[data-claim]')) { if (how === 'preview') { close(); return; } claim(v); }
    });
    tick(root, v); requestAnimationFrame(() => { root.classList.add('in'); root.querySelector('.cvp-cta')?.focus({ preventScroll: true }); });
    if (how !== 'preview') send(v.id, 'shown');
    return true;
  }
  window.CVTaraPromo = { preview: (v, variant) => modal(v, variant, 'preview'), cardHtml, bar: v => barHtml(v) };

  function barHtml(v) {
    return `<div class="cvp-bar" style="${style(v)}" role="region" aria-label="Offer"><span class="cvp-bar-art" aria-hidden="true">${E(v.theme.art)}</span>
      <span class="cvp-bar-text">${v.badge ? `<em>${E(v.badge)}</em>` : ''}<b>${E(v.heading)}</b>${v.discount ? ` <strong>${E(v.discount)}</strong>` : ''}</span>
      ${v.code ? `<button type="button" class="cvp-bar-code" data-copy title="Copy the code"><code>${E(v.code)}</code><span>Copy</span></button>` : ''}
      ${v.countdown ? '<span class="cvp-bar-count" data-count></span>' : ''}<button type="button" class="cvp-bar-cta" data-claim>${E(v.cta)} →</button><button type="button" class="cvp-bar-x" data-close aria-label="Hide offer bar">×</button></div>`;
  }

  if (admin || !body || ['workspace', 'checkout', 'setup', 'auth'].includes(page) || /[?&]nopromo\b/.test(location.search)) return;
  const home = ['/', '/index.html'].includes(location.pathname), show = v => v.pages === 'all' || (v.pages === 'home' && home) || (v.pages === 'home-pricing' && (home || ['/templates.html', '/cover-letters.html'].includes(location.pathname)));
  const offer = new URLSearchParams(location.search).get('offer') || '';
  fetch('/api/promo' + (offer ? '?offer=' + encodeURIComponent(offer) : ''), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(r => r.json()).then(d => {
    const v = d && d.promo; if (!v || !show(v)) return;
    if (v.triggers.exit) window.CVTaraPromoExit = true; // the offer's own leaving pop-up replaces the older template invitation
    const seen = store.get('cvtara-promo-seen') || {}, fresh = () => !seen[v.id] || Date.now() - seen[v.id] > v.frequencyHours * 3600000 || !!offer;
    // top bar
    if (v.triggers.banner && sess.get('cvtara-promo-bar:' + v.id) !== 'off') {
      const wrap = document.createElement('div'); wrap.className = 'cvp-bar-wrap'; wrap.innerHTML = barHtml(v);
      const a = document.getElementById('lpAnnounce'); if (a) a.hidden = true;
      document.body.prepend(wrap); tick(wrap, v);
      wrap.addEventListener('click', e => {
        if (e.target.closest('[data-close]')) { sess.set('cvtara-promo-bar:' + v.id, 'off'); wrap.remove(); send(v.id, 'close'); if (a) a.hidden = false; }
        else if (e.target.closest('[data-copy]')) copyCode(v, e.target.closest('[data-copy]').querySelector('span'));
        else if (e.target.closest('[data-claim]')) claim(v);
      });
      send(v.id, 'shown');
    }
    // pop-ups: only one per visit-window, whichever trigger fires first
    let fired = false;
    const fire = variant => {
      if (fired || !fresh() || (document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && variant !== 'exit')) return false;
      if (!modal(v, variant, 'live')) return false;
      fired = true; seen[v.id] = Date.now(); store.set('cvtara-promo-seen', seen); return true;
    };
    if (offer) { setTimeout(() => fire(''), 1200); return; }
    if (v.triggers.popup.enabled) setTimeout(function again() { if (!fired && !fire('') && !fired && fresh()) setTimeout(again, 5000); }, Math.max(3, v.triggers.popup.delay) * 1000);
    if (v.triggers.exit) {
      const armed = Date.now() + 6000;
      document.addEventListener('mouseout', e => { if (!e.relatedTarget && e.clientY <= 0 && Date.now() > armed) fire('exit'); });
      let top = 0, maxY = 0, t0 = 0; addEventListener('scroll', () => { const y = scrollY; maxY = Math.max(maxY, y); if (y < top - 120 && maxY > 600 && y < 250 && Date.now() - t0 < 700 && Date.now() > armed) fire('exit'); if (y >= top) t0 = Date.now(); top = y; }, { passive: true });
      document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') sess.set('cvtara-promo-left', String(Date.now())); });
    }
    if (v.triggers.idle.enabled) {
      let idle; const reset = () => { clearTimeout(idle); idle = setTimeout(() => { if (document.visibilityState === 'visible') fire('idle'); }, Math.max(10, v.triggers.idle.seconds) * 1000); };
      ['mousemove', 'keydown', 'scroll', 'touchstart', 'click'].forEach(ev => addEventListener(ev, reset, { passive: true })); reset();
    }
  }).catch(() => {});
})();
