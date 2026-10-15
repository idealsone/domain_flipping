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
    top.innerHTML = `<div class="ws-hero"><div><span class="eyebrow" style="color:#c7d2fe">WELCOME BACK</span><h2>Welcome back, ${E(name)} 👋</h2><p>${free ? 'You’re a normal user — subscribe to download. Build your primary resume, then upgrade for tailored versions and clean downloads.' : `You’re on ${E(al.planName)} — ${al.daysLeft} days left. Tailor a version of your primary resume for each job.`}</p><p style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap"><button class="button mint" data-workspace-view="resumes">My resumes →</button><button class="button secondary" data-workspace-view="match">Check ATS match</button></p></div><div class="ws-ring" style="--p:${free ? 0 : Math.min(100, Math.round(al.daysLeft / 90 * 100))}%"><b>${free ? 'Free' : al.daysLeft}</b><small>${free ? 'plan' : 'days left'}</small></div></div>
      <div class="ws-usage">${use('Resumes (primary + tailored)', u.savedResumes, c.resumes, free ? 'preview allowance' : 'across your plan')}${use('Cover letters', u.savedLetters, c.letters, 'saved drafts')}${use('ATS scans', free ? u.dailyMatches : u.atsScans, free ? al.limits.dailyMatches : c.atsScans, free ? 'today' : 'this plan')}${use('Clean PDF / Word downloads', u.exports, c.exports, free ? 'a plan is needed to download' : 'each watermark-free download uses 1')}</div>`;
  }

  function billing() {
    const host = $('#view-billing');
    if (!host || !al) return;
    host.innerHTML = `<div class="admin-heading"><div><h2>Billing &amp; plans</h2><p>Manage your plan, renew, or add extra resume and cover-letter packs.</p></div></div>
      <div class="bl-current"><div><h3>${E(al.planName)}</h3><p>${al.paid ? `Active · expires ${fmt(al.accessUntil)} · ${al.daysLeft} days left` : 'No active plan — you are a normal user. Choose a plan to download your resumes.'}</p></div><span class="bl-status">${al.paid ? 'Active' : 'Free'}</span></div>
      <div class="pr-panel"><h2 style="font-size:20px">Your allowance</h2><div class="ws-usage" style="margin:12px 0 0">${use('Resumes', al.used.savedResumes, al.caps.resumes, 'primary + tailored')}${use('Cover letters', al.used.savedLetters, al.caps.letters, 'saved')}${use('ATS scans', al.paid ? al.used.atsScans : al.used.dailyMatches, al.paid ? al.caps.atsScans : al.limits.dailyMatches, al.paid ? 'this plan' : 'today')}${use('Clean PDF / Word downloads', al.used.exports, al.caps.exports, 'each watermark-free download uses 1')}</div></div>
      <h3 style="margin:22px 0 14px">${al.paid ? 'Upgrade, renew or add a pack' : 'Choose a plan'}</h3><div id="billingPlans"></div>
      <p style="margin-top:18px;color:var(--muted)">Purchase history and invoices are in <a href="#" data-workspace-view="account" style="color:var(--blue);font-weight:700">Account &amp; purchases</a>.</p>`;
    window.CVTaraPlans?.mount($('#billingPlans'), { mode: 'billing', current: al.paid ? al.plan : null });
  }

  function privacy() {
    const acct = $('#view-account');
    if (!acct || $('#privacyTools')) return;
    const s = document.createElement('section');
    s.id = 'privacyTools'; s.className = 'panel panel-pad'; s.style.marginTop = '18px';
    s.innerHTML = `<span class="eyebrow">YOUR DATA</span><h3>Download or delete your data</h3><p style="color:var(--muted)">You can download everything CVTara holds about you, or permanently erase your account. Purchase records are kept for legal and tax reasons but are detached from your identity.</p>
      <p style="display:flex;gap:10px;flex-wrap:wrap"><button class="button secondary" data-export-data>Download my data (JSON)</button><button class="button secondary" data-open-delete style="color:#b91c1c">Delete my account…</button></p><p role="status" data-privacy-status></p>
      <form data-delete-form hidden class="pr-form open"><b>This cannot be undone.</b><label>Current password<input type="password" name="password" autocomplete="current-password" required></label><label>Type DELETE to confirm<input name="confirm" autocomplete="off" required></label><div class="pr-actions"><button class="button primary" style="background:#b91c1c">Permanently delete</button><button class="button secondary" type="button" data-cancel-delete>Cancel</button></div></form>`;
    acct.append(s);
    const st = $('[data-privacy-status]', s), form = $('[data-delete-form]', s);
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
      if (r.commissionEligible === false) {
        const n = document.createElement('div');
        n.id = 'proOnlyNotice'; n.className = 'ws-notice';
        n.innerHTML = '<b>Cash commission is a Pro-plan benefit.</b> You can still share your link and unlock reward milestones. <a href="#" data-workspace-view="billing" style="color:inherit;font-weight:800">See Pro plans →</a>';
        host.prepend(n);
      }
    } catch {}
  }

  async function boot() {
    try { await load(); } catch { return; }
    sidebar(); dashboard(); billing(); privacy(); referralNotice();
    // other scripts re-render some views; put our blocks back if they were replaced
    new MutationObserver(() => { if ($('#view-dashboard') && !$('#wsDash')) dashboard(); if ($('#view-account') && !$('#privacyTools')) privacy(); }).observe($('.workspace-content') || document.body, { childList: true, subtree: true });
    document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'billing') { load().then(() => { sidebar(); billing(); }); } if (e.detail === 'dashboard') load().then(() => { sidebar(); dashboard(); }); if (e.detail === 'referrals') referralNotice(); });
  }
  addEventListener('load', () => setTimeout(boot, 400));
})();
