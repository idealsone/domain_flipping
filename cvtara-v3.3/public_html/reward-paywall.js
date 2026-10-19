(() => {
  'use strict';
  const request = (path, body) => window.CVTaraAPI.request('/api/' + path, body === undefined ? undefined : {method: 'POST', body});
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const rule = 'Your friend gets ₹50 off their first order of ₹299 or more (Job Hunt, Pro or bigger). You get ₹50 wallet credit once their purchase passes the 7-day refund window without a refund. Each friend counts once.';
  async function render(host) {
    if (!window.CVTaraAPI || host.dataset.loading === 'true') return;
    host.dataset.loading = 'true';
    try {
      const [rewards, referrals] = await Promise.all([request('referral-rewards'), request('referrals')]);
      if (!host.isConnected) return;
      
      const claimed = new Set(rewards.claimed.map(Number));const g={downloadThreshold:5,premiumThreshold:10,premiumDays:30,...rewards.rules};
      host.innerHTML = `<span class="eyebrow">GIVE ₹50, GET ₹50</span><h2>Share CVTara. ${g.downloadThreshold} friends also unlock a free Job Hunt month.</h2><p>${rule}</p><div class="parameter-grid"><article><h3>1 Job Hunt month free (plus one clean resume download)</h3><label>${Math.min(rewards.count,g.downloadThreshold)} / ${g.downloadThreshold} qualified friends<progress value="${Math.min(rewards.count,g.downloadThreshold)}" max="${g.downloadThreshold}" style="display:block;width:100%"></progress></label><button class="button secondary" data-claim="5" ${!rewards.enabled || rewards.count < g.downloadThreshold || claimed.has(5) ? 'disabled' : ''}>${claimed.has(5) ? 'Already claimed' : 'Claim reward'}</button></article></div><p>The reward can be claimed once. If you have a plan running, claim after it ends and your reward waits for you.</p><p>${rewards.exports} free resume download${Number(rewards.exports) === 1 ? '' : 's'} remaining.</p><a class="button primary" data-share target="_blank" rel="noopener noreferrer">Share on WhatsApp</a><button class="button secondary" data-refresh>Refresh progress</button><p data-result role="status" aria-live="polite"></p><a href="/workspace.html?view=referrals">Open my referral dashboard</a>`;
      const result = host.querySelector('[data-result]');
      const share = host.querySelector('[data-share]');
      if (share) share.href = 'https://wa.me/?text=' + encodeURIComponent('I build my resume on CVTara. Use my link and get ₹50 off Job Hunt or Pro. Building and previewing are free. ' + referrals.url);
      host.querySelector('[data-refresh]').onclick = () => render(host);
      host.querySelectorAll('[data-claim]').forEach(button => button.onclick = async () => {
        button.disabled = true;
        try { await request('referral-rewards/claim', {milestone: Number(button.dataset.claim)}); host.dataset.loading = 'false'; await render(host); }
        catch (error) { result.textContent = error.message; button.disabled = false; }
      });
      for (const [selector, path] of [['[data-phone]','send'],['[data-code]','check']]) {
        const form = host.querySelector(selector);
        if (!form) continue;
        form.onsubmit = async event => {
          event.preventDefault(); const button = form.querySelector('button'); button.disabled = true;
          try {
            await request('referral-rewards/' + path, Object.fromEntries(new FormData(form)));
            if (path === 'check') {
              host.dataset.loading = 'false';
              await render(host);
            } else {
              host.querySelector('[data-code]').hidden = false;
              result.textContent = 'Code sent. Enter it within 10 minutes.';
            }
          } catch (error) { result.textContent = error.message; }
          finally { button.disabled = false; }
        };
      }
    } catch (error) { host.textContent = error.message || 'Referral progress could not load. Refresh to try again.'; }
    finally { host.dataset.loading = 'false'; }
  }
  function dashboard() {
    const account = document.getElementById('view-account');
    if (!account) return;
    let host = document.getElementById('referralMilestones');
    if (!host) { host = document.createElement('section'); host.id = 'referralMilestones'; host.className = 'launch-panel'; account.append(host); }
    render(host);
  }
  const originalExport = window.CVTaraExport;
  if (originalExport) window.CVTaraExport = (type, id) => {
    originalExport(type, id);
    const dialog = document.getElementById('exportDialog');
    if (!dialog || type !== 'resume') return;
    // The "unlock a clean download" help is for accounts without a plan; paid customers do not need it.
    window.CVTaraAPI.request('/api/allowance').then(a => { if (!a.paid) addUnlock(dialog); }).catch(() => addUnlock(dialog));
  };
  function addUnlock(dialog) {
    if (dialog.querySelector('.launch-panel')) return;
    const section = document.createElement('section'); section.className = 'launch-panel';
    section.innerHTML = '<h3>Choose how to unlock a clean download</h3><p>Your saved work stays in your profile. Download this resume for ₹99, or tailor one for every job with Job Hunt.</p><a class="button primary" href="/checkout.html?plan=quick">Download for ₹99</a> <a class="button secondary" href="/checkout.html?plan=job-hunt">See Job Hunt</a><details><summary>Give ₹50, get ₹50 with friends</summary><div data-rewards></div></details>';
    dialog.querySelector('[data-close]').before(section);
    const details = section.querySelector('details');
    details.addEventListener('toggle', () => { if (details.open) render(section.querySelector('[data-rewards]')); });
  }
  document.addEventListener('cvtara-profile-rendered', dashboard);
  document.addEventListener('click', event => { if (event.target.closest('[data-workspace-view="account"]')) dashboard(); });
  if (document.getElementById('profileForm')) dashboard();
})();
