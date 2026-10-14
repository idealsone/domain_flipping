(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // rotating role in the headline
  const roles = ['Data Analyst', 'Software Engineer', 'HR Manager', 'Marketing Lead', 'Product Designer', 'Finance Analyst'];
  const out = $('#lpRotate');
  if (out) {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) out.textContent = roles[0];
    else {
      let r = 0, c = 0, del = false;
      (function tick() {
        const w = roles[r];
        c += del ? -1 : 1;
        out.textContent = w.slice(0, c);
        let t = del ? 40 : 85;
        if (!del && c === w.length) { del = true; t = 1500; }
        else if (del && c === 0) { del = false; r = (r + 1) % roles.length; t = 350; }
        setTimeout(tick, t);
      })();
    }
  }
  // stats from real library + plan data
  const D = window.CVTARA_DATA;
  if (D) { if ($('#stTemplates')) $('#stTemplates').textContent = D.templates.length; if ($('#stLetters')) $('#stLetters').textContent = D.coverLetters.length; }
  const apply = d => {
    const prices = Object.values(d.plans || {}).filter(p => p.type === 'plan' && p.price > 0).map(p => p.price);
    if (prices.length) {
      const min = Math.min(...prices), inr = n => '₹' + n.toLocaleString('en-IN');
      if ($('#stFrom')) $('#stFrom').textContent = inr(min);
      const a = $('#lpAnnounce');
      if (a) a.innerHTML = `Build and preview free · Plans from ${inr(min)} · Pay once, no auto-renewal · <a href="#pricing">See plans</a>`;
    }
  };
  window.CVTaraPlans?.load ? window.CVTaraPlans.load().then(apply).catch(() => {}) : fetch('/api/business-public').then(r => r.json()).then(apply).catch(() => {});
  addEventListener('load', () => window.CVTaraPlans?.load().then(apply).catch(() => {}));
  // persona selection
  document.querySelectorAll('#lpPersona [data-aud]').forEach(b => b.addEventListener('click', () => {
    try { localStorage.setItem('cvtara-audience', b.dataset.aud); } catch {}
    const host = $('#homePlans');
    host?.setAudience?.(b.dataset.aud);
    $('#pricing')?.scrollIntoView({ behavior: 'smooth' });
    history.replaceState(null, '', '#pricing');
  }));
  // approved reviews only
  const box = $('#lpReviews');
  if (box) fetch('/api/public-content', { headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => {
    const rev = (d.reviews || []).slice(0, 6);
    box.innerHTML = rev.length ? rev.map(r => `<article class="lp-review"><div class="stars" aria-label="${r.rating} out of 5">${'★'.repeat(r.rating)}${'☆'.repeat(5 - r.rating)}</div><p>“${E(r.content)}”</p><b>${E(r.display_name)}</b></article>`).join('')
      : '<div class="lp-empty">Customer reviews will appear here once approved. <a href="/register">Be one of the first to build your resume →</a></div>';
  }).catch(() => { box.innerHTML = ''; });
})();
