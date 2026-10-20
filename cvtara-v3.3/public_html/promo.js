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
  const dest = v => { const q = []; if (v.plan) q.push('plan=' + encodeURIComponent(v.plan)); if (v.code) q.push('coupon=' + encodeURIComponent(v.code)); return '/checkout.html' + (q.length ? '?' + q.join('&') : ''); };
  const send = (id, kind) => { if (admin) return; try { (window.CVTaraCSRF ? window.CVTaraCSRF() : Promise.resolve('')).then(t => fetch('/api/promo/event', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': t || '' }, body: JSON.stringify({ id, kind }) })).catch(() => {}); } catch { /* tracking is optional */ } };

  function copyCode(v, btn) {
    const lab = btn.querySelector('[data-copy-label]') || btn, was = lab.dataset.was || (lab.dataset.was = lab.textContent), sr = btn.closest('.cvp-card')?.querySelector('.cvp-sr');
    const done = () => { lab.textContent = 'Copied ✓'; btn.classList.add('is-copied'); if (sr) sr.textContent = 'Code ' + v.code + ' copied'; setTimeout(() => { lab.textContent = was; btn.classList.remove('is-copied'); }, 1800); send(v.id, 'copy'); };
    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(v.code).then(done, done); else { const t = Object.assign(document.createElement('textarea'), { value: v.code }); document.body.append(t); t.select(); try { document.execCommand('copy'); } catch { /* ignore */ } t.remove(); done(); }
  }
  function claim(v) {
    send(v.id, 'click'); store.set('cvtara-promo-intent', { code: v.code, plan: v.plan, id: v.id, t: Date.now() });
    const to = dest(v); location.href = guest ? '/register?next=' + encodeURIComponent(to) : to;
  }

  // The offer card, used by the pop-up and by the owner's preview. The artwork is drawn inline (one small drawing per festival family), so no image files or outside hosts are needed.
  const ART = { diwali: 'diya', pooja: 'diya', holi: 'splash', eid: 'moon', newyear: 'burst', republic: 'burst', christmas: 'snow', navratri: 'flower', ganesh: 'flower', pongal: 'flower', onam: 'flower', flash: 'bolt', graduate: 'cap' };
  const rot = (n, f, o = 0) => Array.from({ length: n }, (_, i) => f(o + i * 360 / n)).join('');
  const star = (x, y, r, c) => `<path class="${c}" d="M${x} ${y - r}C${x + r * .12} ${y - r * .12} ${x + r * .12} ${y - r * .12} ${x + r} ${y}C${x + r * .12} ${y + r * .12} ${x + r * .12} ${y + r * .12} ${x} ${y + r}C${x - r * .12} ${y + r * .12} ${x - r * .12} ${y + r * .12} ${x - r} ${y}C${x - r * .12} ${y - r * .12} ${x - r * .12} ${y - r * .12} ${x} ${y - r}z"/>`;
  const DRAW = {
    diya: '<circle cx="60" cy="46" r="34" class="g"/><path d="M60 12c12 14 16 25 16 33a16 16 0 0 1-32 0c0-8 4-19 16-33z" class="f"/><path d="M60 30c6 8 8 13 8 17a8 8 0 0 1-16 0c0-4 2-9 8-17z" class="w"/><path d="M12 64h96c-4 24-25 38-48 38S16 88 12 64z" class="a"/><path d="M27 76c12 8 54 8 66 0" class="sp"/><circle cx="40" cy="87" r="3.2" class="p"/><circle cx="60" cy="91" r="3.2" class="p"/><circle cx="80" cy="87" r="3.2" class="p"/>',
    splash: '<circle cx="46" cy="54" r="30" fill="#fde047"/><circle cx="78" cy="44" r="25" fill="#22d3ee" opacity=".92"/><circle cx="72" cy="80" r="23" fill="#a3e635" opacity=".92"/><circle cx="40" cy="86" r="15" fill="#fff" opacity=".9"/><circle cx="100" cy="78" r="7" fill="#fde047"/><circle cx="16" cy="36" r="6" fill="#fff"/><circle cx="96" cy="16" r="5" fill="#a3e635"/><circle cx="30" cy="16" r="4" fill="#22d3ee"/><circle cx="108" cy="50" r="3.5" fill="#fff"/>',
    moon: '<circle cx="58" cy="60" r="40" class="g"/><path d="M66 16a44 44 0 1 0 38 66A36 36 0 1 1 66 16z" class="a"/>' + star(88, 34, 12, 'w') + star(104, 62, 6, 'w') + star(30, 22, 5, 'w'),
    burst: `<g class="s">${rot(12, a => `<path transform="rotate(${a} 50 52)" d="M50 38V16"/>`)}</g><g class="w">${rot(12, a => `<circle transform="rotate(${a} 50 52)" cx="50" cy="10" r="3"/>`, 15)}</g><g class="sa">${rot(10, a => `<path transform="rotate(${a} 92 88)" d="M92 80V68"/>`)}</g>` + star(18, 96, 7, 'a') + star(104, 24, 6, 'w'),
    snow: `<g class="s s5">${rot(6, a => `<path transform="rotate(${a} 60 60)" d="M60 60V14M60 28l-11-11M60 28l11-11M60 44l-9-9M60 44l9-9"/>`)}</g><circle cx="60" cy="60" r="8" class="a"/>` + star(104, 20, 6, 'w') + star(16, 100, 5, 'w'),
    flower: `${rot(8, a => `<ellipse transform="rotate(${a} 60 60)" cx="60" cy="28" rx="12" ry="24" class="a"/>`)}${rot(8, a => `<ellipse transform="rotate(${a} 60 60)" cx="60" cy="38" rx="7" ry="16" class="w"/>`, 22.5)}<circle cx="60" cy="60" r="14" class="p"/><circle cx="60" cy="60" r="6" class="a"/>`,
    bolt: '<circle cx="60" cy="60" r="44" class="g"/><path d="M70 8 26 68h30l-8 44 46-64H64z" class="a so"/>' + star(102, 22, 7, 'w') + star(18, 98, 6, 'w'),
    cap: '<path d="M60 22 8 46l52 24 52-24z" class="w"/><path d="M30 58v22c0 10 60 10 60 0V58L60 72z" class="a"/><path d="M100 52v26" class="s"/><circle cx="100" cy="82" r="6" class="a"/>' + star(20, 96, 7, 'w') + star(104, 16, 5, 'w'),
    tag: '<g transform="rotate(-16 60 60)"><path d="M28 30h50l26 30-26 30H28a8 8 0 0 1-8-8V38a8 8 0 0 1 8-8z" class="w"/><circle cx="84" cy="60" r="6" class="p"/><circle cx="42" cy="48" r="7" class="ps"/><circle cx="62" cy="72" r="7" class="ps"/><path d="M64 44 40 76" class="ps"/></g>' + star(106, 98, 8, 'a') + star(14, 92, 6, 'w') + star(22, 20, 4, 'w')
  };
  const BG = '<svg class="cvp-bg" viewBox="0 0 460 200" preserveAspectRatio="xMidYMid slice" aria-hidden="true"><circle cx="420" cy="-10" r="120" fill="#fff" opacity=".1"/><circle cx="-20" cy="210" r="130" fill="#000" opacity=".1"/><circle cx="300" cy="190" r="60" fill="#fff" opacity=".06"/>' + [[34, 34, 6], [190, 22, 5], [250, 70, 4], [150, 170, 5], [430, 150, 7], [330, 30, 4], [96, 120, 3]].map(([x, y, r], i) => star(x, y, r, 'tw t' + (i % 3))).join('') + '</svg>';
  const WAVE = '<svg class="cvp-wave" viewBox="0 0 460 26" preserveAspectRatio="none" aria-hidden="true"><path d="M0 26V12c50 12 110 14 170 6S300 0 360 6s76 12 100 8v12z"/></svg>';
  const ICON = { x: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>', copy: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" fill="none" stroke="currentColor" stroke-width="2"/></svg>', cal: '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3.5 10h17M8 3v4M16 3v4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>', go: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>' };
  const until = v => { if (!v.endsAt) return ''; try { const d = new Date(v.endsAt); return isNaN(d) ? '' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Kolkata' }); } catch { return ''; } };
  let uid = 0;
  function cardHtml(v, variant) {
    const o = (variant && v.variants[variant]) || {}, head = o.heading || v.heading, sub = o.text || v.subheading, id = 'cvp' + (++uid);
    const m = /^(.*?)\s*OFF$/i.exec(v.discount || ''), big = m ? m[1] : v.discount, end = until(v), kind = ART[v.theme.key] || 'tag';
    const photo = v.image ? `<div class="cvp-photo" style="background-image:linear-gradient(150deg,color-mix(in srgb,var(--pf) 82%,transparent),color-mix(in srgb,var(--pt) 62%,transparent)),url('${E(v.image)}')"></div>` : '';
    return `<div class="cvp-card${v.image ? ' has-img' : ''}" style="${style(v)}" role="dialog" aria-modal="true" tabindex="-1" aria-labelledby="${id}"${sub ? ` aria-describedby="${id}d"` : ''}>
      <div class="cvp-top">${BG}${photo}<button type="button" class="cvp-x" data-close aria-label="Close offer">${ICON.x}</button>
        <div class="cvp-hero">${v.badge ? `<span class="cvp-badge">${E(v.badge)}</span>` : ''}${big ? `<p class="cvp-deal${big.length > 4 ? ' long' : ''}"><b>${E(big)}</b>${m ? `<span><em>OFF</em><small>on ${v.plan ? 'your plan' : 'any plan'}</small></span>` : ''}</p>` : `<h2 class="cvp-hl" id="${id}">${E(head)}</h2>`}</div>
        ${v.image ? '' : `<svg class="cvp-ill" viewBox="0 0 120 120" aria-hidden="true">${DRAW[kind]}</svg>`}${WAVE}</div>
      <div class="cvp-body">${big ? `<h2 id="${id}">${E(head)}</h2>` : ''}${sub ? `<p class="cvp-sub" id="${id}d">${E(sub)}</p>` : ''}${v.body ? `<p class="cvp-text">${E(v.body)}</p>` : ''}
        ${v.code ? `<button type="button" class="cvp-tk" data-copy aria-label="Copy coupon code ${E(v.code)}"><span class="cvp-tk-l"><small>Your coupon code</small><code>${E(v.code)}</code></span><span class="cvp-tk-r">${ICON.copy}<span data-copy-label>Tap to copy</span></span></button>` : ''}
        ${end ? `<p class="cvp-valid">${ICON.cal}<span>Offer valid till ${E(end)}</span></p>` : ''}
        <button type="button" class="cvp-cta" data-claim><span>${E(v.cta)}</span>${ICON.go}</button><button type="button" class="cvp-no" data-close>No thanks, maybe later</button>
        <small class="cvp-fine">${v.code ? 'Your code is applied for you at checkout. ' : ''}7-day refund if unused.</small><span class="cvp-sr" role="status" aria-live="polite"></span></div></div>`;
  }
  let openRoot = null;
  function modal(v, variant, how) {
    if (openRoot || document.querySelector('dialog[open]')) return false;
    const root = document.createElement('div'); root.className = 'cvp-overlay'; root.innerHTML = cardHtml(v, variant); document.body.append(root); openRoot = root;
    const prev = document.activeElement; const close = () => { root.classList.add('out'); setTimeout(() => { root.remove(); openRoot = null; try { prev && prev.focus && prev.focus(); } catch { /* ignore */ } }, 180); document.removeEventListener('keydown', key); };
    const key = e => {
      if (e.key === 'Escape') { send(v.id, 'close'); close(); return; }
      if (e.key !== 'Tab') return; // keep keyboard focus inside the pop-up while it is open
      const f = [...root.querySelectorAll('button,[href],input,select,textarea')].filter(x => !x.disabled && x.getClientRects().length), a = f[0], z = f[f.length - 1], at = document.activeElement;
      if (!a) return; if (!root.contains(at) || at.classList.contains('cvp-card')) { e.preventDefault(); (e.shiftKey ? z : a).focus(); } else if (e.shiftKey && at === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && at === z) { e.preventDefault(); a.focus(); }
    };
    document.addEventListener('keydown', key);
    root.addEventListener('click', e => {
      if (e.target === root || e.target.closest('[data-close]')) { send(v.id, 'close'); close(); return; }
      if (e.target.closest('[data-copy]')) copyCode(v, e.target.closest('[data-copy]'));
      if (e.target.closest('[data-claim]')) { if (how === 'preview') { close(); return; } claim(v); }
    });
    requestAnimationFrame(() => { root.classList.add('in'); root.querySelector('.cvp-card')?.focus({ preventScroll: true }); });
    if (how !== 'preview') send(v.id, 'shown');
    return true;
  }
  window.CVTaraPromo = { preview: (v, variant) => modal(v, variant, 'preview'), cardHtml, bar: v => barHtml(v) };

  function barHtml(v) {
    return `<div class="cvp-bar" style="${style(v)}" role="region" aria-label="Offer"><span class="cvp-bar-art" aria-hidden="true">${E(v.theme.art)}</span>
      <span class="cvp-bar-text">${v.badge ? `<em>${E(v.badge)}</em>` : ''}<b>${E(v.heading)}</b>${v.discount ? ` <strong>${E(v.discount)}</strong>` : ''}</span>
      ${v.code ? `<button type="button" class="cvp-bar-code" data-copy title="Copy the code"><code>${E(v.code)}</code><span>Copy</span></button>` : ''}
      <button type="button" class="cvp-bar-cta" data-claim>${E(v.cta)} →</button><button type="button" class="cvp-bar-x" data-close aria-label="Hide offer bar">×</button></div>`;
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
      document.body.prepend(wrap);
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
