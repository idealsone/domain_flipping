/* Contextual help (3.46.0): the "?" button in the top bar opens the guide chapter for the page on screen.
   Owner console -> /admin-guide.html, customer workspace -> /customer-guide.html. */
(() => {
  'use strict';
  const a = document.getElementById('ctxHelp'); if (!a) return;
  const admin = !!document.querySelector('.admin-view');
  const MAP = admin ? { dashboard: 'dashboard', approvals: 'approvals', people: 'customers', users: 'customers', orders: 'orders', business: 'orders', pricing: 'orders', resumelib: 'libraries', letterlib: 'libraries',
    sitedesign: 'templates', referral: 'referrals', desk: 'referrals', mailer: 'comms', campaigns: 'comms', outreach: 'comms', whatsnew: 'comms', reports: 'reports', promos: 'offers', growth: 'growth', tara: 'ai', assistant: 'ai',
    feedback: 'support', speed: 'integrations', settings: 'integrations', integrations: 'integrations', errors: 'help', setup: 'help', bloghub: 'seo', team: 'workforce' }
    : { dashboard: 'dashboard', resumes: 'create', builder: 'create', letter: 'letters', tracker: 'applications', match: 'ats', billing: 'plans', account: 'account', referrals: 'referrals', wallet: 'referrals', 'referral-history': 'referrals', feedback: 'help' };
  const base = admin ? '/admin-guide.html' : '/customer-guide.html';
  const update = () => {
    const v = document.querySelector(admin ? '.admin-view.active' : '.workspace-view.active');
    const key = v ? v.id.replace(/^(admin|view)-/, '') : 'dashboard';
    const ch = MAP[key] || (admin ? 'dashboard' : 'dashboard');
    a.href = base + '#' + ch;
    const t = (document.getElementById(admin ? 'adminTitle' : 'workspaceTitle') || {}).textContent || 'this page';
    a.title = 'Help: ' + t.trim(); a.setAttribute('aria-label', 'Help for ' + t.trim());
  };
  update();
  document.addEventListener('click', e => { if (e.target.closest('[data-admin-view],[data-workspace-view]')) setTimeout(update, 60); });
  const main = document.querySelector('.admin-content,.workspace-content');
  if (main && window.MutationObserver) new MutationObserver(update).observe(main, { subtree: true, attributes: true, attributeFilter: ['class'] });
})();
