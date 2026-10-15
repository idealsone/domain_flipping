(() => {
  'use strict';
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN');
  const short = p => String(p.name).replace(/^(Fresher|Professional)\s+/, '');
  const AUD = { fresher: 'Fresher', experienced: 'Experienced' };

  async function load() {
    if (window.CVTaraBusiness?.plans) return window.CVTaraBusiness;
    const r = await fetch('/api/business-public', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const d = await r.json();
    window.CVTaraBusiness = d;
    return d;
  }

  async function audienceFor(plans) {
    const q = new URLSearchParams(location.search);
    const fromUrl = q.get('aud');
    if (AUD[fromUrl]) return fromUrl;
    const pk = q.get('plan');
    if (pk && plans[pk] && AUD[plans[pk].audience]) return plans[pk].audience;
    try { const s = localStorage.getItem('cvtara-audience'); if (AUD[s]) return s; } catch {}
    const user = document.querySelector('meta[name=cvtara-user]')?.content;
    if (user && user !== 'guest') {
      try {
        const r = await fetch('/api/profile', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await r.json();
        if (d.profile?.experience_level === 'fresher') return 'fresher';
        if (d.profile?.experience_level === 'experienced') return 'experienced';
      } catch {}
    }
    return 'fresher';
  }

  function cardHTML(key, p, o) {
    const stats = [[(p.savedResumes || 0) + (p.savedLetters || 0), 'Document credits'], [1, 'Primary resume'], [p.atsScans == null ? '∞' : p.atsScans, 'ATS Scans']];
    const save = p.mrp > p.price ? p.mrp - p.price : 0;
    const current = o.current === key;
    const href = '/checkout.html?plan=' + encodeURIComponent(key);
    const cta = current && o.mode !== 'billing' ? '<button class="button secondary full" disabled>Current plan</button>'
      : current ? `<a class="button secondary full" href="${href}" data-pl-go="${E(key)}">Renew +${p.accessDays} days</a>`
      : o.mode === 'checkout' ? `<button class="button ${o.selected === key ? 'primary' : 'secondary'} full" type="button" data-pl-select="${E(key)}">${o.selected === key ? '✓ Selected' : 'Select ' + E(short(p))}</button>`
      : `<a class="button primary full" href="${href}" data-pl-go="${E(key)}">Get ${E(short(p))} →</a>`;
    return `<article class="pl-card${p.popular ? ' pl-popular' : ''}${o.selected === key ? ' pl-selected' : ''}" data-plan-card="${E(key)}">
      ${current ? '<div class="pl-badge" style="background:#16a34a">✓ Your plan</div>' : p.popular ? '<div class="pl-badge">⭐ Most Popular</div>' : ''}
      <h3>${E(p.name)}</h3><p class="pl-tag">${E(p.tagline)}</p>
      <div class="pl-price"><b>${inr(p.price)}</b><span>${p.accessDays} days${p.mrp > p.price ? ` · <s>${inr(p.mrp)}</s>` : ''}</span></div>
      ${save ? `<span class="pl-save">Save ${inr(save)} · ${Math.round(save / p.mrp * 100)}% off</span>` : ''}
      <div class="pl-sub">What you get</div>
      <div class="pl-primary"><i>👑</i><div><b>1 Premium Primary Resume</b><span>ATS-optimised, built for your target role — your master copy</span></div></div>
      <div class="pl-plus">+ JD-Tailored Variants &amp; Cover Letters</div>
      <div class="pl-stats">${stats.map(([n, l]) => `<div><b>${E(n)}</b><span>${l}</span></div>`).join('')}</div>
      <ul class="pl-perks">${(p.perks || []).map(x => `<li>${E(x)}</li>`).join('')}</ul>
      ${cta}</article>`;
  }

  function addonHTML(key, p, o) {
    return `<article class="pl-addon" data-addon-card="${E(key)}"><div><b>+${(p.savedResumes || 0) + (p.savedLetters || 0)} document credits</b><span>+${p.savedResumes} resumes &amp; +${p.savedLetters} cover letters, in any mix · valid ${p.accessDays || 45} days from purchase</span></div><strong>${inr(p.price)}</strong>${o.mode === 'checkout' ? `<button class="button ${o.selected === key ? 'primary' : 'secondary'} small" type="button" data-pl-select="${E(key)}">${o.selected === key ? '✓ Selected' : 'Choose'}</button>` : `<a class="button secondary small" href="/checkout.html?plan=${E(key)}" data-pl-go="${E(key)}">Add on</a>`}</article>`;
  }

  async function mount(el, opts = {}) {
    if (!el) return;
    el.classList.add('pl-wrap');
    el.innerHTML = '<p class="pl-loading">Loading plans…</p>';
    let data;
    try { data = await load(); } catch { el.innerHTML = '<p class="pl-loading" role="alert">Plans could not be loaded. Please refresh.</p>'; return; }
    const plans = data.plans || {};
    const state = { aud: opts.audience || await audienceFor(plans), selected: opts.selected || null };
    const draw = () => {
      const o = { mode: opts.mode || 'landing', current: opts.current || null, selected: state.selected };
      const keys = Object.keys(plans).filter(k => plans[k].type === 'plan' && plans[k].audience === state.aud).sort((a, b) => plans[a].price - plans[b].price);
      const addons = Object.keys(plans).filter(k => plans[k].type === 'addon').sort((a, b) => plans[a].price - plans[b].price);
      el.innerHTML = `<div class="pl-tabs" role="tablist" aria-label="Choose your career stage">${Object.entries(AUD).map(([k, l]) => `<button type="button" role="tab" aria-selected="${state.aud === k}" class="${state.aud === k ? 'active' : ''}" data-pl-aud="${k}">${k === 'fresher' ? '🎓' : '💼'} ${l} plans</button>`).join('')}</div>
        <div class="pl-grid">${keys.map(k => cardHTML(k, plans[k], o)).join('')}</div>
        ${opts.addons === false || !addons.length ? '' : `<div class="pl-addons"><div class="pl-addons-head"><h3>Need more resumes? Add a pack</h3><p>Add-ons stack on top of an active plan and last as long as it does.</p></div><div class="pl-addon-grid">${addons.map(k => addonHTML(k, plans[k], o)).join('')}</div></div>`}`;
      el.querySelectorAll('[data-pl-aud]').forEach(b => b.onclick = () => { state.aud = b.dataset.plAud; try { localStorage.setItem('cvtara-audience', state.aud); } catch {} draw(); el.dispatchEvent(new CustomEvent('cvtara-audience', { detail: state.aud, bubbles: true })); });
      el.querySelectorAll('[data-pl-select]').forEach(b => b.onclick = () => { state.selected = b.dataset.plSelect; draw(); el.dispatchEvent(new CustomEvent('cvtara-plan-select', { detail: state.selected, bubbles: true })); });
    };
    draw();
    el.setAudience = a => { if (AUD[a]) { state.aud = a; draw(); } };
    el.select = k => { state.selected = k; if (plans[k]?.audience && AUD[plans[k].audience]) state.aud = plans[k].audience; draw(); };
    return el;
  }

  window.CVTaraPlans = { mount, load, inr, short };
  document.querySelectorAll('[data-plans]').forEach(el => mount(el, { mode: el.dataset.plans || 'landing', current: el.dataset.current || null }));
})();
