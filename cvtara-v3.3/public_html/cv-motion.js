/* 3.70.0 Shared motion and charts, no libraries (about 4 KB).
   - [data-reveal]: fades and lifts in once when scrolled into view.
   - [data-count="89"]: counts up from 0 when it comes into view (keeps any text after the number in data-suffix).
   - CVChart.bars / CVChart.line: small SVG charts. Every bar or point shows its value on hover, on keyboard focus and on tap.
   Everything is skipped when the visitor has asked for reduced motion, and nothing here moves the page layout. */
(() => {
  'use strict';
  if (window.CVChart) return;
  const calm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = v => Number(v) || 0;

  /* ---------- reveal + count-up ---------- */
  const countUp = el => {
    if (el.dataset.counted) return; el.dataset.counted = '1';
    const to = num(el.dataset.count), suf = el.dataset.suffix || '', pre = el.dataset.prefix || '';
    if (calm || !to) { el.textContent = pre + to.toLocaleString('en-IN') + suf; return; }
    const t0 = performance.now(), dur = 900;
    const step = t => { const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3); el.textContent = pre + Math.round(to * e).toLocaleString('en-IN') + suf; if (k < 1) requestAnimationFrame(step); };
    requestAnimationFrame(step);
  };
  let io = null;
  const watch = root => {
    const els = (root || document).querySelectorAll('[data-reveal]:not(.cv-in),[data-count]:not([data-counted]),svg.cvc:not(.cv-in)');
    if (!els.length) return;
    if (calm || !('IntersectionObserver' in window)) { els.forEach(el => { el.classList.add('cv-in'); if (el.dataset.count !== undefined) countUp(el); }); return; }
    io = io || new IntersectionObserver(list => list.forEach(x => {
      if (!x.isIntersecting) return;
      x.target.classList.add('cv-in'); if (x.target.dataset.count !== undefined) countUp(x.target);
      x.target.querySelectorAll('[data-count]').forEach(countUp);
      io.unobserve(x.target);
    }), { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
    // anything already on screen is shown at once, so the first view never blinks
    els.forEach(el => { if (!document.documentElement.classList.contains('cv-motion') && el.getBoundingClientRect().top < innerHeight) { el.classList.add('cv-in'); if (el.dataset.count !== undefined) countUp(el); } else io.observe(el); });
  };

  /* ---------- charts ---------- */
  const W = 560;
  const shell = (o, h, inner) => `<svg class="cvc cvc-${o.type}" viewBox="0 0 ${W} ${h}" role="group" aria-label="${E(o.label || 'Chart')}" preserveAspectRatio="none">${inner}</svg>`;
  function bars(o) {
    const pts = o.points || [], H = o.height || 190, base = H - 26, top = 22, max = Math.max(1, ...pts.map(p => num(p.value)));
    const n = Math.max(1, pts.length), slot = (W - 20) / n, bw = Math.min(42, slot * 0.62);
    const every = Math.ceil(n / 10);
    return shell({ ...o, type: 'bars' }, H, `<line class="cvc-axis" x1="10" x2="${W - 10}" y1="${base}" y2="${base}"/>` + pts.map((p, i) => {
      const v = num(p.value), h = Math.max(v ? 3 : 1, (v / max) * (base - top)), x = 10 + i * slot + (slot - bw) / 2;
      const tip = p.tip || `${p.label}: ${o.format ? o.format(v) : v.toLocaleString('en-IN')}`;
      return `<g class="cvc-pt" tabindex="0" role="img" aria-label="${E(tip)}" data-tip="${E(tip)}"${p.href ? ` data-href="${E(p.href)}"` : ''}${p.view ? ` data-admin-view="${E(p.view)}"` : ''} style="--i:${i}"><rect class="cvc-hit" x="${10 + i * slot}" y="0" width="${slot}" height="${H}"/><rect class="cvc-bar" x="${x}" y="${base - h}" width="${bw}" height="${h}" rx="4"/>${i % every === 0 ? `<text class="cvc-x" x="${x + bw / 2}" y="${H - 7}" text-anchor="middle">${E(p.short ?? p.label)}</text>` : ''}</g>`;
    }).join(''));
  }
  function line(o) {
    const pts = o.points || [], H = o.height || 190, base = H - 26, top = 22, n = pts.length;
    const lo = o.min ?? 0, max = Math.max(lo + 1, ...pts.map(p => num(p.value)));
    const x = i => n < 2 ? W / 2 : 24 + i * ((W - 48) / (n - 1)), y = v => base - ((num(v) - lo) / (max - lo)) * (base - top);
    const d = pts.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' ');
    const slot = n < 2 ? W : (W - 48) / (n - 1), every = Math.ceil(n / 8);
    return shell({ ...o, type: 'line' }, H, `<line class="cvc-axis" x1="10" x2="${W - 10}" y1="${base}" y2="${base}"/>` +
      (n > 1 ? `<path class="cvc-area" d="${d} L${x(n - 1).toFixed(1)},${base} L${x(0).toFixed(1)},${base} Z"/><path class="cvc-line" d="${d}" pathLength="1"/>` : '') +
      pts.map((p, i) => {
        const v = num(p.value), tip = p.tip || `${p.label}: ${o.format ? o.format(v) : v.toLocaleString('en-IN')}`;
        return `<g class="cvc-pt" tabindex="0" role="img" aria-label="${E(tip)}" data-tip="${E(tip)}"${p.href ? ` data-href="${E(p.href)}"` : ''}${p.view ? ` data-workspace-view="${E(p.view)}"` : ''} style="--i:${i}"><rect class="cvc-hit" x="${x(i) - slot / 2}" y="0" width="${slot}" height="${H}"/><circle class="cvc-dot" cx="${x(i)}" cy="${y(v)}" r="5"/>${i % every === 0 || i === n - 1 ? `<text class="cvc-x" x="${x(i)}" y="${H - 7}" text-anchor="middle">${E(p.short ?? p.label)}</text>` : ''}</g>`;
      }).join(''));
  }

  /* one tooltip for the whole page */
  let tip = null, cur = null;
  const show = g => {
    if (!g) return;
    tip = tip || Object.assign(document.createElement('div'), { className: 'cvc-tip', role: 'status' });
    if (!tip.isConnected) document.body.append(tip);
    cur && cur.classList.remove('on'); cur = g; g.classList.add('on');
    tip.textContent = g.dataset.tip;
    const r = (g.querySelector('.cvc-bar,.cvc-dot') || g).getBoundingClientRect();
    tip.style.left = Math.min(innerWidth - 12, Math.max(12, r.left + r.width / 2)) + 'px';
    tip.style.top = (r.top + scrollY - 8) + 'px';
    tip.classList.add('on');
  };
  const hide = () => { tip && tip.classList.remove('on'); cur && cur.classList.remove('on'); cur = null; };
  document.addEventListener('pointerover', e => { const g = e.target.closest && e.target.closest('.cvc-pt'); if (g) show(g); });
  document.addEventListener('pointerout', e => { const g = e.target.closest && e.target.closest('.cvc-pt'); if (g && !g.contains(e.relatedTarget)) hide(); });
  document.addEventListener('focusin', e => { const g = e.target.closest && e.target.closest('.cvc-pt'); if (g) show(g); else hide(); });
  document.addEventListener('keydown', e => { const g = e.target.closest && e.target.closest('.cvc-pt'); if (!g) return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { const n = e.key === 'ArrowRight' ? g.nextElementSibling : g.previousElementSibling; if (n && n.classList.contains('cvc-pt')) { e.preventDefault(); n.focus(); } }
    else if ((e.key === 'Enter' || e.key === ' ') && (g.dataset.href || g.dataset.adminView || g.dataset.workspaceView)) { e.preventDefault(); g.dispatchEvent(new MouseEvent('click', { bubbles: true })); }
    else if (e.key === 'Escape') hide(); });
  document.addEventListener('click', e => { const g = e.target.closest && e.target.closest('.cvc-pt'); if (g && g.dataset.href) location.href = g.dataset.href; });
  addEventListener('scroll', hide, { passive: true });

  window.CVChart = { bars, line, watch };
  let queued = false;
  const later = () => { if (queued) return; queued = true; setTimeout(() => { queued = false; watch(); }, 120); };
  const start = () => { watch(); document.documentElement.classList.add(calm ? 'cv-calm' : 'cv-motion'); new MutationObserver(m => { if (m.some(r => r.addedNodes.length)) later(); }).observe(document.body, { childList: true, subtree: true }); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
