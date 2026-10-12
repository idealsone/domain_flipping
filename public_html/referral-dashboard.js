(() => {
  'use strict';
  const E = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => value == null ? '—' : '₹' + (value / 100).toLocaleString('en-IN', {minimumFractionDigits: 2});
  const date = value => value ? new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z').toLocaleDateString() : '—';
  const table = (heads, rows, empty) => `<div class="ref-table-wrap"><table><thead><tr>${heads.map(v => `<th scope="col">${E(v)}</th>`).join('')}</tr></thead><tbody>${rows.length ? rows.map(row => `<tr>${row.map(v => `<td>${E(v)}</td>`).join('')}</tr>`).join('') : `<tr><td colspan="${heads.length}">${E(empty)}</td></tr>`}</tbody></table></div>`;
  const targets = ['referrals', 'wallet', 'referral-history'];
  let inFlight;
  async function load() {
    if (!window.CVTaraAPI) return;
    if (inFlight) return inFlight;
    inFlight = (async () => {
      const hosts = targets.map(v => document.getElementById('ref-content-' + v));
      if (!hosts.every(Boolean)) return;
      hosts.forEach(h => h.setAttribute('aria-busy', 'true'));
      try {
        const d = await window.CVTaraAPI.request('/api/referrals');
        const connected = d.connected !== false;
        const b = d.balance || {}; const rules={downloadThreshold:5,premiumThreshold:10,premiumDays:30,tierThreshold:100,tierRateBps:3000,...d.rules};
        const rate = Number(d.effectiveRateBps ?? 2000) / 100;
        const note = connected ? 'Only verified live payments can earn commission. Refunds reverse earnings; review periods apply.' : 'Live referral tracking, earnings and withdrawals are not connected in this private preview. No demo money or invented referrals are shown.';
        const metrics = [[b.availablePaise,'Available to withdraw'],[b.pendingPaise,'In review'],[b.reservedPaise,'Withdrawal requested'],[b.paidPaise,'Paid to you']];
        const paid = Number(d.successfulBuyers || 0), signups = Number(d.signups || 0);
        const live = `<section class="live-ref-track"><span class="eyebrow">LIVE AFTER PAYMENT</span><h3>Progress updates when a friend pays.</h3><p>No claim buttons. A successful, unrefunded purchase moves this tracker.</p><div class="live-ref-stats"><article><span>Referred signups</span><strong>${signups}</strong></article><article><span>Paid referrals</span><strong>${paid}</strong></article><article><span>Your rate</span><strong>${rate}%</strong></article></div><label class="live-ref-bar">Paid referrals tracked<progress max="${Math.max(paid,signups,1)}" value="${paid}"></progress><span>${paid} successful payment${paid===1?'':'s'}</span></label></section>`;
        hosts[0].innerHTML = `<div class="ref-dashboard-note">${E(note)}</div>${live}<div class="earn-paths"><article class="earn-path"><small>NORMAL REFERRAL</small><h3>Share with friends</h3><p class="earn-rate-big">20%</p><p>Normal users earn 20% on each qualifying paid referral. Copy your link and send it.</p></article><article class="earn-path earn-path-creator"><small>CREATORS</small><h3>Are you an influencer?</h3><p class="earn-rate-big">Review</p><p>5,000+ audience. Apply. We assign your rate after approval.</p><button class="blink-influencer" type="button" data-workspace-view="earn" data-earn-open="influencer">Are you an influencer?</button></article></div><div class="ref-dashboard-grid"><section class="ref-dashboard-card"><span class="eyebrow">YOUR 20% LINK</span><h3>Your referral link</h3><p>Friends sign up, verify and buy a plan through your link. You earn <strong>${rate}%</strong> on that eligible payment.</p><label>Personal referral link<input data-ref-link readonly value="${E(d.url || '')}" placeholder="Available when referral tracking is activated"></label><div class="ref-dashboard-actions"><button class="button primary" data-copy ${!d.url ? 'disabled' : ''}>Copy link</button>${d.url ? `<a class="button secondary" data-whatsapp target="_blank" rel="noopener noreferrer">Share on WhatsApp</a>` : '<button class="button secondary" disabled>Share on WhatsApp</button>'}</div><p data-copy-status role="status"></p><p>${connected ? Number(d.signups || 0) : '—'} referred signups · ${connected ? Number(d.clicks || 0) : '—'} recorded visits</p></section><section class="ref-dashboard-card"><span class="eyebrow">WHY PEOPLE STAY</span><h3>A finished resume. Not another prompt.</h3><div class="ref-why-grid"><article><b>A real template</b><p>Preview A4 layouts on phone, tablet and computer.</p></article><article><b>Saved to their account</b><p>They keep the draft. You earn when they buy.</p></article></div></section></div><section class="ref-dashboard-card"><h3>Cash commission</h3><p>Your current rate is <strong>${rate}%</strong>. Influencers with 5,000+ audience can apply. We assign the rate after review.</p><div class="ref-dashboard-actions"><button class="button secondary" data-workspace-view="wallet">Open wallet</button><button class="button secondary" data-workspace-view="referral-history">View referral history</button></div></section>`;
        if (d.url) {
          const share = 'Build your resume with CVTara. If you sign up through my link and buy a plan, I may earn commission. ' + d.url;
          hosts[0].querySelector('[data-whatsapp]').href = 'https://wa.me/?text=' + encodeURIComponent(share);
          hosts[0].querySelector('[data-copy]').onclick = async () => { const input = hosts[0].querySelector('[data-ref-link]'), status = hosts[0].querySelector('[data-copy-status]'); try { await navigator.clipboard.writeText(d.url); status.textContent = 'Referral link copied.'; } catch { input.select(); status.textContent = 'Select and copy your link above.'; } };
        }
        hosts[1].innerHTML = `<div class="ref-dashboard-note">${E(note)}</div><div class="wallet-stat-grid">${metrics.map(([v,label]) => `<article><span>${label}</span><strong>${money(v)}</strong></article>`).join('')}</div><section class="ref-dashboard-card"><h3>Withdraw your earnings</h3><p>Minimum withdrawal: <strong>${money(d.rules?.minimumPaise ?? 100000)}</strong>. A verified payout profile, fraud review and sufficient available balance are required.</p><p>Eligible payouts target 24 hours; identity checks and bank processing can take longer.</p>${connected ? '<a class="button primary" href="/workspace?view=account#referralProgram">Manage payout details & request withdrawal</a>' : '<button class="button primary" disabled>Withdrawals not active</button>'}</section><section class="ref-dashboard-card"><h3>Withdrawal history</h3>${table(['Requested','Amount','Status','Reference'],(d.withdrawals || []).map(w => [date(w.created_at),money(w.amount_paise),w.status,w.reference || 'Pending']),connected ? 'No withdrawals requested yet.' : 'No connected withdrawal records.')}</section>`;
        hosts[2].innerHTML = `<div class="ref-dashboard-note">${E(note)}</div><section class="ref-dashboard-card"><h3>Referred friends</h3>${table(['Referral','Joined','Progress'],(d.referredUsers || []).map((u,i) => [u.label || 'Friend ' + (i+1),date(u.joinedAt),u.status]),connected ? 'No referred signups yet. Share your personal link to get started.' : 'Referral tracking is not connected yet.')}</section><section class="ref-dashboard-card"><h3>Commission history</h3>${table(['Date','Purchase','Rate','Earned','Reversed','Available after'],(d.commissions || []).map(c => [date(c.created_at),c.purchase_id,c.rate_bps/100+'%',money(c.amount_paise),money(c.reversed_paise),date(c.available_at)]),connected ? 'No commissions yet. Only eligible paid purchases appear here.' : 'No connected commission records.')}</section><p class="ref-dashboard-muted">History is limited to the latest 200 referral and commission records and 100 withdrawals.</p>`;
      } catch (error) {
        hosts.forEach(h => { h.innerHTML = `<div class="ref-dashboard-note"><p>${E(error.message || 'Could not load your referral account.')}</p><button class="button secondary" data-retry>Try again</button></div>`; h.querySelector('[data-retry]').onclick = load; });
      } finally { hosts.forEach(h => h.removeAttribute('aria-busy')); }
    })();
    try { await inFlight; } finally { inFlight = null; }
  }
  document.addEventListener('cvtara-workspace-view', e => { if (targets.includes(e.detail)) load(); });
  document.addEventListener('cvtara-workspace-view', e => { if (targets.includes(e.detail)) load(); });
  document.querySelectorAll('[data-ref-refresh]').forEach(b => b.onclick = load);
  if (targets.includes(new URLSearchParams(location.search).get('view'))) load();
  function scrollToAccountSection() { if (['#referralProgram','#referralMilestones'].includes(location.hash)) requestAnimationFrame(() => document.querySelector(location.hash)?.scrollIntoView({block:'start'})); }
  document.addEventListener('cvtara-profile-rendered', scrollToAccountSection);
})();
