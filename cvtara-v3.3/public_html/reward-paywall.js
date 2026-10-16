(() => {
  'use strict';
  const request = (path, body) => window.CVTaraAPI.request('/api/' + path, body === undefined ? undefined : {method: 'POST', body});
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const rule = 'Only distinct referred friends who verify their account and successfully pay for any plan count. Signups alone, test payments and refunded purchases do not count. Each friend counts once.';
  async function render(host) {
    if (!window.CVTaraAPI || host.dataset.loading === 'true') return;
    host.dataset.loading = 'true';
    try {
      const [rewards, referrals] = await Promise.all([request('referral-rewards'), request('referrals')]);
      if (!host.isConnected) return;
      const ready = rewards.identity?.status === 'verified';
      const claimed = new Set(rewards.claimed.map(Number));const g={downloadThreshold:5,premiumThreshold:10,premiumDays:30,...rewards.rules};
      host.innerHTML = `<span class="eyebrow">REFER & UNLOCK</span><h2>Your friends join. Your rewards grow.</h2><p>${rule}</p><div class="parameter-grid">${[[5,g.downloadThreshold,'One free resume download'],[10,g.premiumThreshold,g.premiumDays+' days of Premium']].map(([id,n,title]) => `<article><h3>${title}</h3><label>${Math.min(rewards.count,n)} / ${n} paying friends<progress value="${Math.min(rewards.count,n)}" max="${n}" style="display:block;width:100%"></progress></label><button class="button secondary" data-claim="${id}" ${!rewards.enabled || !ready || rewards.count < n || claimed.has(id) ? 'disabled' : ''}>${claimed.has(id) ? 'Already claimed' : 'Claim reward'}</button></article>`).join('')}</div><p>Milestones are cumulative: the same first ${g.downloadThreshold} paying friends count toward ${g.premiumThreshold}. Each reward can be claimed once. Claim the free month after any current Premium access expires.</p><p>${rewards.exports} free resume download${Number(rewards.exports) === 1 ? '' : 's'} remaining.</p>${!rewards.enabled ? '<p role="status">Referral rewards are paused until phone verification is configured.</p>' : `<p>Verify your own email and mobile number before claiming. Your saved draft stays in your profile.</p>${ready ? '<p>Mobile verified.</p>' : rewards.identity ? `<p>Mobile verification status: ${escape(rewards.identity.status)}. ${escape(rewards.identity.review_note)}</p>` : '<form data-phone><label>Indian mobile number<input name="phone" type="tel" required pattern="[6-9][0-9]{9}" maxlength="10" autocomplete="tel-national"></label><button class="button secondary">Send SMS code</button></form><form data-code hidden><label>SMS verification code<input name="code" required pattern="[0-9]{4,10}" inputmode="numeric" autocomplete="one-time-code"></label><button class="button secondary">Verify mobile</button></form>'}<a class="button primary" data-share target="_blank" rel="noopener noreferrer">Share on WhatsApp</a>`}<button class="button secondary" data-refresh>Refresh progress</button><p data-result role="status" aria-live="polite"></p><a href="/customer-guide.html#referrals">Read the referral guide</a>`;
      const result = host.querySelector('[data-result]');
      const share = host.querySelector('[data-share]');
      if (share) share.href = 'https://wa.me/?text=' + encodeURIComponent('Create your resume with CVTara. If you sign up through my link and buy any plan, I can earn commission and referral rewards. Building and saving a draft is free; clean downloads require a plan or an earned reward. ' + referrals.url);
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
    const section = document.createElement('section'); section.className = 'launch-panel';
    section.innerHTML = '<h3>Choose how to unlock a clean download</h3><p>Your saved work stays in your profile. Pay for immediate access, or earn a referral reward.</p><a class="button primary" href="/workspace.html?view=billing">View plans &amp; upgrade</a><details><summary>Invite paying friends & unlock rewards</summary><div data-rewards></div></details>';
    dialog.querySelector('[data-close]').before(section);
    const details = section.querySelector('details');
    details.addEventListener('toggle', () => { if (details.open) render(section.querySelector('[data-rewards]')); });
  };
  document.addEventListener('cvtara-profile-rendered', dashboard);
  document.addEventListener('click', event => { if (event.target.closest('[data-workspace-view="account"]')) dashboard(); });
  if (document.getElementById('profileForm')) dashboard();
})();
