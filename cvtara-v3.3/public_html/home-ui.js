(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // job-link copy stays hidden until the owner switches job links on
  if (document.querySelector('[data-needs-link]')) fetch('/api/public-content', { credentials: 'same-origin' }).then(r => r.json()).then(j => { if ((j.content || {}).jobLinks) document.querySelectorAll('[data-needs-link]').forEach(n => { n.hidden = false; }); }).catch(() => {});
  fetch('/api/public-content', { credentials: 'same-origin' }).then(r => r.json()).then(j => { const e = ((j.content || {}).site || {}).employers, box = $('#employerNames'); if (!e || !e.enabled || !box || !(e.names || []).length) return; $('#employerHead').textContent = e.heading || 'Employers across India'; $('#employerList').innerHTML = e.names.map(n => `<li>${E(n)}</li>`).join(''); box.hidden = false; }).catch(() => {});
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
  let site = null;
  const announceHtml = from => E((site && site.announce) || 'Create your account, build and preview · Plans from {{from}} · Pay once, no auto-renewal').replace('{{from}}', from) + ' · <a href="#pricing">' + E((site && site.announceLink) || 'See plans') + '</a>';
  const rich = t => E(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  // stats from real library + plan data
  const D = window.CVTARA_DATA;
  if (D) { const counts = () => { const t = D.templates.filter(x => x.publicPublished !== false && !x.curatedHidden).length, l = D.coverLetters.filter(x => x.publicPublished !== false).length; if ($('#stTemplates')) $('#stTemplates').textContent = t; if ($('#stLetters')) $('#stLetters').textContent = l; }; counts(); addEventListener('load', () => { counts(); setTimeout(counts, 800); }); }
  const apply = d => {
    const prices = Object.values(d.plans || {}).filter(p => p.type === 'plan' && p.price > 0).map(p => p.price);
    if (prices.length) {
      const min = Math.min(...prices), inr = n => '₹' + n.toLocaleString('en-IN');
      if ($('#stFrom')) $('#stFrom').textContent = inr(min); if ($('#getFrom')) $('#getFrom').textContent = inr(min);
      const a = $('#lpAnnounce');
      if (a) a.innerHTML = announceHtml(inr(min));
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
  // owner-editable homepage copy (Owner console → Templates & site design)
  fetch('/api/public-content', { headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => {
    const s = d.content && d.content.site; if (!s) return; site = s;
    if (Array.isArray(s.heroRoles) && s.heroRoles.length) { roles.length = 0; roles.push(...s.heroRoles); }
    const h1 = $('.lp-hero h1'); if (h1 && h1.firstChild && s.heroTitle) h1.firstChild.nodeValue = s.heroTitle + ' ';
    const pill = $('.lp-pill'); if (pill && pill.lastChild && s.heroPill) pill.lastChild.nodeValue = s.heroPill;
    if ($('.lp-lead') && s.heroLead) $('.lp-lead').innerHTML = rich(s.heroLead);
    if ($('.lp-cta .primary') && s.ctaPrimary) $('.lp-cta .primary').textContent = s.ctaPrimary;
    if ($('.lp-cta .secondary') && s.ctaSecondary) $('.lp-cta .secondary').textContent = s.ctaSecondary;
    if ($('.lp-final h2') && s.ctaFinalTitle) $('.lp-final h2').textContent = s.ctaFinalTitle;
    if ($('.lp-final p') && s.ctaFinalText) $('.lp-final p').textContent = s.ctaFinalText;
    if ($('.lp-final .button') && s.ctaFinalButton) $('.lp-final .button').textContent = s.ctaFinalButton;
    const a = $('#lpAnnounce'), from = $('#stFrom') ? $('#stFrom').textContent : '₹299'; if (a) a.innerHTML = announceHtml(from);
  }).catch(() => {});
})();
