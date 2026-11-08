(() => {
  'use strict';
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN');
  const short = p => String(p.name);
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

  const perDay = p => p.accessDays >= 30 && p.price ? '≈ ' + inr(Math.round(p.price / p.accessDays)) + '/day' : '';

  function cardHTML(key, p, o) {
    const stats = p.oneResume ? [[1, 'Resume (PDF + Word)'], [p.accessDays, 'Days to edit and re-download']]
      : [[p.savedResumes, 'Tailored resumes'], [p.savedLetters, 'Cover letters'], [p.atsScans == null ? '∞' : p.atsScans, 'ATS Match scans']];
    const current = o.current === key;
    const href = '/checkout.html?plan=' + encodeURIComponent(key);
    const label = p.oneResume ? 'Download for ' + inr(p.price) : 'Get ' + E(p.name);
    const cta = current && o.mode !== 'billing' ? '<button class="button secondary full" disabled>Current plan</button>'
      : current ? `<a class="button secondary full" href="${href}" data-pl-go="${E(key)}">Renew +${p.accessDays} days</a>`
      : o.mode === 'checkout' ? `<button class="button ${o.selected === key ? 'primary' : 'secondary'} full" type="button" data-pl-select="${E(key)}">${o.selected === key ? '✓ Selected' : 'Select ' + E(p.name)}</button>`
      : `<a class="button primary full" href="${href}" data-pl-go="${E(key)}">${label}</a>`;
    const badge = current ? '<div class="pl-badge" style="background:#0F9D8A">✓ Your plan</div>' : p.popular ? '<div class="pl-badge">Most popular</div>' : p.bestValue ? '<div class="pl-badge" style="background:#2B3A8C">Best value</div>' : '';
    return `<article class="pl-card${p.popular ? ' pl-popular' : ''}${o.selected === key ? ' pl-selected' : ''}" data-plan-card="${E(key)}">
      ${badge}<h3>${E(p.name)}</h3><p class="pl-tag">${E(p.tagline)}</p>
      <div class="pl-price"><b>${inr(p.price)}</b><span>${p.accessDays} days${p.mrp > p.price ? ` · worth <s>${inr(p.mrp)}</s>` : ''}</span></div>
      ${perDay(p) ? `<span class="pl-save">${perDay(p)} · pay once, no auto-renewal</span>` : '<span class="pl-save">Pay once, no auto-renewal</span>'}
      <div class="pl-stats">${stats.map(([n, l]) => `<div><b>${E(n)}</b><span>${l}</span></div>`).join('')}</div>
      <ul class="pl-perks">${(p.perks || []).map(x => `<li>${E(x)}</li>`).join('')}</ul>
      ${cta}</article>`;
  }

  function addonHTML(key, p, o) {
    const service = p.type === 'service';
    const title = service ? E(p.name) : `+${p.savedResumes} resume downloads &amp; +${p.savedLetters} cover-letter downloads${p.atsScans ? ` &amp; +${p.atsScans} ATS scans` : ''}`;
    const note = service ? `${E(p.tagline)}${p.planPrice ? ` · ${inr(p.planPrice)} with Job Hunt or Pro` : ''}` : 'Kept separately · valid until your plan ends · follows a renewal';
    return `<article class="pl-addon" data-addon-card="${E(key)}"><div><b>${title}</b><span>${note}</span></div><strong>${inr(p.price)}</strong>${o.mode === 'checkout' ? `<button class="button ${o.selected === key ? 'primary' : 'secondary'} small" type="button" data-pl-select="${E(key)}">${o.selected === key ? '✓ Selected' : 'Choose'}</button>` : `<a class="button secondary small" href="/checkout.html?plan=${E(key)}" data-pl-go="${E(key)}">Add</a>`}</article>`;
  }

  async function mount(el, opts = {}) {
    if (!el) return;
    el.classList.add('pl-wrap');
    el.innerHTML = '<p class="pl-loading">Loading plans…</p>';
    let data;
    try { data = await load(); } catch { el.innerHTML = '<p class="pl-loading" role="alert">Plans could not be loaded. Please refresh.</p>'; return; }
    const plans = data.plans || {};
    const onSale = k => k !== 'free' && plans[k].sale !== false && !plans[k].limitReached;
    const auds = [...new Set(Object.keys(plans).filter(k => onSale(k) && plans[k].type === 'plan').map(k => plans[k].audience))].filter(a => AUD[a]);
    const state = { aud: auds.length > 1 ? (opts.audience || await audienceFor(plans)) : 'all', selected: opts.selected || null };
    const draw = () => {
      const o = { mode: opts.mode || 'landing', current: opts.current || null, selected: state.selected };
      const keys = Object.keys(plans).filter(k => onSale(k) && plans[k].type === 'plan' && (state.aud === 'all' || plans[k].audience === state.aud || plans[k].audience === 'all')).sort((a, b) => plans[a].price - plans[b].price);
      const addons = Object.keys(plans).filter(k => onSale(k) && (plans[k].type === 'addon' || plans[k].type === 'service')).sort((a, b) => plans[a].price - plans[b].price);
      const tabs = auds.length > 1 ? `<div class="pl-tabs" role="tablist" aria-label="Choose your career stage">${auds.map(k => `<button type="button" role="tab" aria-selected="${state.aud === k}" class="${state.aud === k ? 'active' : ''}" data-pl-aud="${k}">${AUD[k]} plans</button>`).join('')}</div>` : '';
      el.innerHTML = `${tabs}<div class="pl-grid">${keys.map(k => cardHTML(k, plans[k], o)).join('')}</div>
        <p class="pl-plus">Build, edit and preview free. 7-day refund if unused · Pay once, no auto-renewal · UPI, cards and net banking</p>
        ${opts.addons === false || !addons.length ? '' : `<div class="pl-addons"><div class="pl-addons-head"><h3>Add-ons</h3><p>Optional extras from the CVTara team, cheaper while Job Hunt or Pro is active.</p></div><div class="pl-addon-grid">${addons.map(k => addonHTML(k, plans[k], o)).join('')}</div></div>`}`;
      el.querySelectorAll('[data-pl-aud]').forEach(b => b.onclick = () => { state.aud = b.dataset.plAud; try { localStorage.setItem('cvtara-audience', state.aud); } catch {} draw(); el.dispatchEvent(new CustomEvent('cvtara-audience', { detail: state.aud, bubbles: true })); });
      el.querySelectorAll('[data-pl-select]').forEach(b => b.onclick = () => { state.selected = b.dataset.plSelect; draw(); el.dispatchEvent(new CustomEvent('cvtara-plan-select', { detail: state.selected, bubbles: true })); });
    };
    draw();
    el.setAudience = a => { if (AUD[a] && auds.length > 1) { state.aud = a; draw(); } };
    el.select = k => { state.selected = k; if (auds.length > 1 && plans[k]?.audience && AUD[plans[k].audience]) state.aud = plans[k].audience; draw(); };
    return el;
  }

  window.CVTaraPlans = { mount, load, inr, short };
  document.querySelectorAll('[data-plans]').forEach(el => mount(el, { mode: el.dataset.plans || 'landing', current: el.dataset.current || null }));
})();
