(() => {
  'use strict';
  const request = (path, body) => window.CVTaraAPI.request('/api/' + path, body === undefined ? undefined : {method: 'POST', body});
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => '₹' + ((Number(value) || 0) / 100).toLocaleString('en-IN', {minimumFractionDigits: 2});
  async function render(host) {
    if (!window.CVTaraAPI || host.dataset.loading === 'true') return;
    host.dataset.loading = 'true';
    try {
      const referrals = await request('referrals');
      if (!host.isConnected) return;
      const paid = Number(referrals.successfulBuyers || 0);
      const signups = Number(referrals.signups || 0);
      const earned = (referrals.commissions || []).reduce((sum, row) => sum + Number(row.amount_paise || 0) - Number(row.reversed_paise || 0), 0);
      const rate = Number(referrals.effectiveRateBps ?? 2000) / 100;
      host.innerHTML = `<span class="eyebrow">LIVE AFTER PAYMENT</span><h2>Referral progress updates when a friend pays.</h2><p>Signups alone do not move this tracker. A successful, unrefunded purchase does. There are no claim-reward milestones.</p><div class="live-ref-stats"><article><span>Referred signups</span><strong>${signups}</strong></article><article><span>Paid referrals</span><strong>${paid}</strong></article><article><span>Commission earned</span><strong>${money(earned)}</strong></article></div><label class="live-ref-bar">Paid referrals tracked<progress max="${Math.max(paid, signups, 1)}" value="${paid}"></progress><span>${paid} successful payment${paid === 1 ? '' : 's'} · live rate ${rate}%</span></label><div class="ref-dashboard-actions"><a class="button primary" href="/workspace?view=earn">Open Refer &amp; earn</a><button class="button secondary" data-refresh type="button">Refresh progress</button></div><p data-result role="status" aria-live="polite"></p><a href="/customer-guide#referrals-en">How referrals work</a>`;
      host.querySelector('[data-refresh]').onclick = () => { host.dataset.loading = 'false'; render(host); };
    } catch (error) {
      host.textContent = error.message || 'Referral progress could not load. Refresh to try again.';
    } finally {
      host.dataset.loading = 'false';
    }
  }
  function dashboard() {
    const account = document.getElementById('view-account');
    if (!account) return;
    let host = document.getElementById('referralMilestones');
    if (!host) { host = document.createElement('section'); host.id = 'referralMilestones'; host.className = 'launch-panel live-ref-track'; account.append(host); }
    render(host);
  }
  document.addEventListener('cvtara-profile-rendered', dashboard);
  document.addEventListener('click', event => { if (event.target.closest('[data-workspace-view="account"]')) dashboard(); });
  if (document.getElementById('profileForm')) dashboard();
})();
