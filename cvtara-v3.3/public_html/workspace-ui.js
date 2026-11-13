(() => {
  'use strict';
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  const $ = (s, r = document) => r.querySelector(s);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const fmt = d => d ? new Date(d.replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
  let al = null, profile = null;

  async function load() {
    [al, profile] = await Promise.all([api('/api/allowance'), api('/api/profile').catch(() => ({ profile: {} }))]);
    window.CVTaraAllowance = al;
    document.dispatchEvent(new CustomEvent('cvtara-allowance', { detail: al }));
  }

  function sidebar() {
    const box = $('.side-plan');
    if (!box || !al) return;
    box.innerHTML = `<span>YOUR PLAN</span><b>${E(al.planName)}</b><small>${al.paid ? `${al.daysLeft} days left · until ${fmt(al.accessUntil)}` : 'Preview — upgrade for tailored resumes, clean downloads and ATS scans.'}</small><button class="button mint full small" style="margin-top:10px" data-workspace-view="billing">${al.paid ? 'Renew / add-ons' : 'See plans'}</button>`;
  }

  const use = (label, used, cap, note) => {
    const unlimited = cap === null || cap === undefined;
    const pct = unlimited ? 12 : Math.min(100, Math.round(used / Math.max(1, cap) * 100));
    return `<div class="ws-use"><span>${label}</span><b>${used}${unlimited ? '' : ' / ' + cap}${unlimited ? ' <small>· unlimited</small>' : ''}</b><small>${note}</small><i style="--v:${pct}%"></i></div>`;
  };

  function dashboard() {
    const host = $('#view-dashboard');
    if (!host || !al) return;
    let top = $('#wsDash');
    if (!top) { top = document.createElement('div'); top.id = 'wsDash'; host.prepend(top); }
    const name = (profile.profile?.name || profile.user?.email || '').split(' ')[0] || 'there';
    const c = al.caps, u = al.used, free = !al.paid;
    // 3.70.0 One next step: the welcome card holds the single "what to do now" block (#wsNext). Tara's nudge fills it in place
    // when it arrives, so nothing below moves. The old roadmap and tips blocks are hidden (testers saw three "next step" boxes).
    top.innerHTML = `<div class="ws-hero ws-hero4"><div class="ws-hero-main"><small class="ws-hi">Hi ${E(name)}, good to see you</small>
      <div id="wsNext" aria-live="polite"><h2>${free ? 'Build your resume free, then download when it’s ready' : 'Tailor your resume for the next job'}</h2><p>${free ? 'Make it, check it against a job and preview it at no cost. Pick a plan only when you want a clean download.' : `${E(al.planName)} · ${al.daysLeft} days left. Paste a job and make a matching copy and cover letter.`}</p>
      <p class="ws-acts"><button class="button mint" data-workspace-view="resumes">My resumes →</button><button class="button secondary" data-workspace-view="match">Check against a job</button></p></div></div>
      ${free ? '<div class="ws-plan-chip"><small>Your plan</small><b>Free</b><span>Build and preview free</span><button class="button mint" data-workspace-view="billing">See plans</button></div>' : `<div class="ws-ring" style="--p:${Math.min(100, Math.round(al.daysLeft / 90 * 100))}%"><b data-count="${al.daysLeft}">${al.daysLeft}</b><small>days left</small></div>`}</div>
      <div class="ws-usage">${use('Resume downloads', al.credits.resumes.used, al.credits.resumes.total, free ? 'previews are free, with a watermark' : 'clean PDF and Word · first primary resume free')}${use('Cover-letter downloads', al.credits.letters.used, al.credits.letters.total, free ? 'previews are free, with a watermark' : 'clean downloads' + (al.credits.addOns.letters ? ` · +${al.credits.addOns.letters} from add-ons` : ''))}${use('ATS checks', free ? u.dailyMatches : u.atsScans, free ? al.limits.dailyMatches : c.atsScans, free ? 'used today' : 'used on this plan')}</div>`;
    progress(host);
  }

  // 3.70.0 "Your progress": match scores over time and where your applications stand. Hover, tap or Tab onto a point to read it.
  let prog = null;
  async function progress(host) {
    let box = $('#wsProgress');
    if (!box) { box = document.createElement('section'); box.id = 'wsProgress'; box.className = 'ws-progress'; host.append(box); }
    else if (box.parentNode !== host || box !== host.lastElementChild) host.append(box);
    if (!prog) {
      box.innerHTML = '<div class="ws-prog-skel"></div><div class="ws-prog-skel"></div>';
      try {
        const [sc, ap] = await Promise.all([api('/api/ats/scans').catch(() => ({ scans: [] })), api('/api/applications').catch(() => ({ applications: [] }))]);
        prog = { scans: (sc.scans || []).slice(0, 12).reverse(), apps: ap.applications || [] };
      } catch { prog = { scans: [], apps: [] }; }
    }
    const day = d => new Date(String(d).replace(' ', 'T') + 'Z').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    const scans = prog.scans, stages = [['saved', 'Saved'], ['applied', 'Applied'], ['interview', 'Interview'], ['offer', 'Offer']];
    const count = Object.fromEntries(stages.map(([k]) => [k, 0])); prog.apps.forEach(a => { if (count[a.stage] !== undefined) count[a.stage]++; });
    const total = prog.apps.length, best = scans.reduce((m, x) => Math.max(m, x.score || 0), 0);
    const chart = window.CVChart && scans.length > 1 ? window.CVChart.line({ label: 'Your ATS match scores', min: 0, points: scans.map(x => ({ label: day(x.createdAt), short: day(x.createdAt), value: x.score, tip: `${x.title || 'Job'}${x.company ? ' · ' + x.company : ''}: ${x.score}% on ${day(x.createdAt)}`, wsView: 'match' })) }) : '';
    box.innerHTML = `<article class="panel panel-pad ws-prog-card" data-reveal><header><div><small>ATS match</small><h3>${scans.length ? `Best score <b data-count="${best}" data-suffix="%">${best}%</b>` : 'No checks yet'}</h3></div><button class="button secondary small" data-workspace-view="match">New check</button></header>
      ${chart || (scans.length === 1 ? `<p class="ws-prog-one"><b>${scans[0].score}%</b> for ${E(scans[0].title || 'your last job')}. Check another job to see your trend.</p>` : '<p class="ws-prog-empty">Paste a job you like and see how well your resume matches it. Your scores show up here as a chart.</p>')}</article>
      <article class="panel panel-pad ws-prog-card" data-reveal="2"><header><div><small>Applications</small><h3>${total ? `<b data-count="${total}">${total}</b> tracked` : 'Nothing tracked yet'}</h3></div><button class="button secondary small" data-workspace-view="tracker">Open tracker</button></header>
      <ol class="ws-funnel">${stages.map(([k, l], i) => `<li style="--w:${count[k] ? Math.max(6, Math.round(count[k] / total * 100)) : 0}%;--i:${i}"><button type="button" data-workspace-view="tracker" aria-label="${l}: ${count[k]}"><span>${l}</span><i></i><b>${count[k]}</b></button></li>`).join('')}</ol></article>`;
  }

  function billing() {
    const host = $('#view-billing');
    if (!host || !al) return;
    host.innerHTML = `<div class="admin-heading"><div><h2>Billing &amp; Plans</h2><p>Manage your plan, renew, or add extra resume and cover-letter packs.</p></div></div>
      <div class="bl-current"><div><h3>${E(al.planName)}</h3><p>${al.paid ? `Active · expires ${fmt(al.accessUntil)} · ${al.daysLeft} days left` : 'You’re on the free plan. Previews are free with a watermark; choose a plan for clean, watermark-free downloads.'}</p></div><span class="bl-status">${al.paid ? 'Active' : 'Free'}</span></div>
      <div class="pr-panel"><h2 style="font-size:20px">Your allowance</h2><div class="ws-usage" style="margin:12px 0 0">${use('Resume downloads', al.credits.resumes.used, al.credits.resumes.total, 'each new clean version uses 1 · 1 primary resume free once' + (al.credits.addOns.resumes ? ` · add-on +${al.credits.addOns.resumes} left` : ''))}${use('Cover-letter downloads', al.credits.letters.used, al.credits.letters.total, 'each new clean version uses 1' + (al.credits.addOns.letters ? ` · add-on +${al.credits.addOns.letters} left` : ''))}<p style="grid-column:1/-1;margin:6px 0 0;color:var(--muted);font-size:13px">Saving drafts is free and does not use credits. Previews always show a watermark. ${al.credits.addOns.validUntil ? 'Add-on credits are valid until ' + fmt(al.credits.addOns.validUntil) + '.' : 'Add-on packs stay valid until your plan ends.'}</p>${use('ATS scans', al.paid ? al.used.atsScans : al.used.dailyMatches, al.paid ? al.caps.atsScans : al.limits.dailyMatches, al.paid ? 'this plan' : 'today')}</div><details id="creditHistory" style="margin-top:14px"><summary style="cursor:pointer;font-weight:700">Credit history</summary><div class="ch-body" style="margin-top:10px;font-size:14px">Loading…</div></details></div>
      <h3 style="margin:22px 0 14px">${al.paid ? 'Upgrade, renew or add a pack' : 'Choose a plan'}</h3><div id="billingPlans"></div>
      <p style="margin-top:18px;color:var(--muted)">Purchase history and invoices are in <a href="#" data-workspace-view="account" style="color:var(--blue);font-weight:700">Account &amp; purchases</a>.</p>`;
    window.CVTaraPlans?.mount($('#billingPlans'), { mode: 'billing', current: al.paid ? al.plan : null });
    const ch = $('#creditHistory'); if (ch) ch.addEventListener('toggle', async () => { if (!ch.open || ch.dataset.done) return; ch.dataset.done = '1'; const b = ch.querySelector('.ch-body'); try { const r = await window.CVTaraAPI.request('/api/credits/history'); b.innerHTML = r.history.length ? `<table style="width:100%;border-collapse:collapse"><thead><tr style="text-align:left;color:var(--muted)"><th style="padding:6px 8px 6px 0">When</th><th style="padding:6px 8px">What</th><th style="padding:6px 8px">For</th><th style="padding:6px 0;text-align:right">Credits</th></tr></thead><tbody>${r.history.map(h => `<tr style="border-top:1px solid var(--line)"><td style="padding:6px 8px 6px 0;white-space:nowrap">${fmt(h.at)}</td><td style="padding:6px 8px">${E(h.what)}</td><td style="padding:6px 8px;overflow-wrap:anywhere">${E(h.item || '—')}</td><td style="padding:6px 0;text-align:right">${h.credits ? '−' + h.credits : 'free'}</td></tr>`).join('')}</tbody></table><p style="margin:8px 0 0;color:var(--muted);font-size:13px">Only finished work uses a credit: a failed scan or download is never charged, and downloading the same version again is free.</p>` : 'No credits used yet. Saving drafts and previews never use credits.'; } catch { b.textContent = 'Could not load your history. Please try again.'; } });
  }

  function privacy() {
    const acct = $('#view-account');
    if (!acct || $('#privacyTools')) return;
    const s = document.createElement('section');
    s.id = 'privacyTools'; s.className = 'panel panel-pad'; s.style.marginTop = '18px';
    s.innerHTML = `<span class="eyebrow">YOUR DATA</span><h3>Download or delete your data</h3><p style="color:var(--muted)">You can download everything CVTara holds about you, or permanently erase your account. Purchase records are kept for legal and tax reasons but are detached from your identity.</p>
      <div data-originals></div>
      <p style="display:flex;gap:10px;flex-wrap:wrap"><button class="button secondary" data-export-data>Download my data (JSON)</button><button class="button secondary" data-open-delete style="color:#b91c1c">Delete my account…</button></p><p role="status" data-privacy-status></p>
      <form data-delete-form hidden class="pr-form open"><b>This cannot be undone.</b><label>Current password<input type="password" name="password" autocomplete="current-password" required></label><label>Type DELETE to confirm<input name="confirm" autocomplete="off" required></label><div class="pr-actions"><button class="button primary" style="background:#b91c1c">Permanently delete</button><button class="button secondary" type="button" data-cancel-delete>Cancel</button></div></form>`;
    acct.append(s);
    const st = $('[data-privacy-status]', s), form = $('[data-delete-form]', s);
    // 3.61.0 Original files uploaded to build a resume: kept 90 days, the customer can download or delete each one.
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const originals = async () => {
      const box = $('[data-originals]', s); if (!box) return;
      try {
        const d = await api('/api/resumes/originals');
        box.innerHTML = d.originals.length ? `<h4 style="margin:12px 0 6px">Your uploaded files</h4><p style="color:var(--muted);margin:0 0 8px">The original files you uploaded to build a resume. Each is kept for ${d.days} days and then erased automatically.</p>
          <ul style="list-style:none;padding:0;margin:0 0 12px">${d.originals.map(o => `<li style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:6px 0;border-top:1px solid var(--line,#e2e8f0)"><span><b>${esc(o.fileName)}</b> · ${Math.max(1, Math.round(o.size / 1024))} KB${o.resumeTitle ? ' · for ' + esc(o.resumeTitle) : ''} · erased on ${esc(o.deleteOn)}</span><a class="button secondary small" href="/api/resumes/originals/${esc(o.id)}" download>Download</a><button class="button secondary small" data-original-delete="${esc(o.id)}">Delete now</button></li>`).join('')}</ul>` : '';
      } catch { box.innerHTML = ''; }
    };
    originals();
    s.addEventListener('click', async e => {
      const b = e.target.closest('[data-original-delete]'); if (!b) return;
      if (!confirm('Delete this file now? Your resume stays as it is.')) return;
      try { await api(`/api/resumes/originals/${b.dataset.originalDelete}/delete`, { method: 'POST', body: {} }); st.textContent = 'The file was deleted.'; originals(); } catch (err) { st.textContent = err.message; }
    });
    $('[data-export-data]', s).onclick = async () => {
      st.textContent = 'Preparing your data…';
      try {
        const d = await api('/api/account/export');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([JSON.stringify(d.export, null, 2)], { type: 'application/json' }));
        a.download = 'cvtara-my-data.json'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 20000);
        st.textContent = 'Your data was downloaded.';
      } catch (e) { st.textContent = e.message; }
    };
    $('[data-open-delete]', s).onclick = () => { form.hidden = false; };
    $('[data-cancel-delete]', s).onclick = () => { form.hidden = true; };
    form.onsubmit = async e => {
      e.preventDefault();
      const v = Object.fromEntries(new FormData(form));
      st.textContent = 'Deleting…';
      try { const r = await api('/api/account/delete', { method: 'POST', body: v }); location.href = r.redirect || '/'; } catch (err) { st.textContent = err.message; }
    };
  }

  async function referralNotice() {
    const host = $('#view-referrals');
    if (!host || $('#proOnlyNotice')) return;
    try {
      const r = await api('/api/referrals');
      if (r.mode === 'commission' && r.commissionEligible === false) {
        const n = document.createElement('div');
        n.id = 'proOnlyNotice'; n.className = 'ws-notice';
        n.innerHTML = '<b>Cash commission is a Pro-plan benefit.</b> You can still share your link and unlock reward milestones. <a href="#" data-workspace-view="billing" style="color:inherit;font-weight:800">See Pro plans →</a>';
        host.prepend(n);
      }
    } catch {}
  }

  async function boot() {
    try { await load(); } catch {
      await new Promise(r => setTimeout(r, 1500));
      try { await load(); } catch {
        const h = $('#view-dashboard');
        if (h && !$('#wsDash')) { const d = document.createElement('div'); d.id = 'wsDash'; d.innerHTML = '<div class="ws-hero"><div><h2>Welcome back</h2><p>We could not load your plan details just now. <a href="" style="color:#fff;text-decoration:underline">Refresh the page</a> to try again.</p></div></div>'; h.prepend(d); }
        return;
      }
    }
    sidebar(); dashboard(); billing(); privacy(); referralNotice();
    // other scripts re-render some views; put our blocks back if they were replaced
    new MutationObserver(() => { if ($('#view-dashboard') && !$('#wsDash')) dashboard(); if ($('#view-account') && !$('#privacyTools')) privacy(); }).observe($('.workspace-content') || document.body, { childList: true, subtree: true });
    // 3.70.0 a11y: after choosing a section in the sidebar, focus moves to that section's heading so keyboard and screen-reader users land on it.
    document.addEventListener('cvtara-workspace-view', e => {
      const v = document.getElementById('view-' + e.detail), a = document.activeElement;
      if (v && a && a.closest && a.closest('.workspace-sidebar, .ws-side, nav, aside')) setTimeout(() => { const h = v.querySelector('h1, h2'); if (h) { h.tabIndex = -1; h.focus({ preventScroll: true }); } }, 60);
    });
    document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'billing') { load().then(() => { sidebar(); billing(); }); } if (e.detail === 'dashboard') { prog = null; load().then(() => { sidebar(); dashboard(); }); } if (e.detail === 'referrals') referralNotice(); });
  }
  addEventListener('load', () => setTimeout(boot, 400));
})();

/* 3.64.0: one "My account" menu in the top bar (Account, Billing, Refer & Earn, Wallet, Referral history), so these pages
   are no longer also listed in the left menu. Opens on click, closes on a choice, Escape or a click elsewhere. */
(() => {
  'use strict';
  const wrap = document.querySelector('.acct-menu'); if (!wrap) return;
  const btn = wrap.querySelector('[data-acct-toggle]'), pop = wrap.querySelector('.acct-pop');
  const set = open => { pop.hidden = !open; btn.setAttribute('aria-expanded', String(open)); if (open) pop.querySelector('button')?.focus(); };
  btn.addEventListener('click', e => { e.stopPropagation(); set(pop.hidden); });
  pop.addEventListener('click', e => { if (e.target.closest('[data-workspace-view]')) set(false); });
  document.addEventListener('click', e => { if (!pop.hidden && !wrap.contains(e.target)) set(false); });
  wrap.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !pop.hidden) { set(false); btn.focus(); }
    if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !pop.hidden) { e.preventDefault(); const items = [...pop.querySelectorAll('button')], i = items.indexOf(document.activeElement); items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus(); }
  });
  // The button shows which account page is open.
  document.addEventListener('cvtara-workspace-view', e => btn.classList.toggle('active', !!pop.querySelector(`[data-workspace-view="${e.detail}"]`)));
})();
