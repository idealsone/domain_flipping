/* Sidebar icons for the customer workspace and the owner console: one set of simple line icons instead of emoji.
   Menu items added later by console modules get theirs too; anything without a match gets a small dot. */
(() => {
  'use strict';
  const P = {
    grid: 'M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z',
    home: 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10',
    users: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
    bag: 'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
    sliders: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8',
    files: 'M15 2H8a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7zM15 2v5h5M4 7v13a2 2 0 0 0 2 2h10',
    mail: 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
    layout: 'M3 3h18v18H3zM3 9h18M9 21V9',
    trend: 'M22 7l-8.5 8.5-5-5L2 17M16 7h6v6',
    chart: 'M18 20V10M12 20V4M6 20v-6',
    gift: 'M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z',
    tag: 'M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82zM7 7h.01',
    megaphone: 'M3 11l18-5v12L3 14v-3zM11.6 16.8a3 3 0 1 1-5.8-1.6',
    send: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
    chat: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    star: 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z',
    zap: 'M13 2L3 14h9l-1 8 10-12h-9z',
    alert: 'M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z',
    pen: 'M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z',
    scale: 'M12 3v18M7 21h10M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z',
    pulse: 'M22 12h-4l-3 9L9 3l-3 9H2',
    target: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
    check: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    wallet: 'M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z',
    history: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l4 2',
    rupee: 'M6 3h12M6 8h12M6 13l8.5 8M6 13h3a5 5 0 0 0 0-10',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
    bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
    smile: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01'
  };
  const MAP = {
    dashboard: 'grid', people: 'users', orders: 'bag', resumelib: 'files', letterlib: 'mail', sitedesign: 'layout', business: 'trend', referral: 'gift',
    mailer: 'mail', reports: 'chart', promos: 'tag', campaigns: 'megaphone', outreach: 'send', feedback: 'chat', speed: 'zap', errors: 'alert',
    bloghub: 'pen', desk: 'star', reviews: 'star', pricing: 'rupee', settings: 'sliders', integrations: 'sliders', features: 'sliders', policies: 'scale',
    legal: 'scale', health: 'pulse', setup: 'pulse', seo: 'search', keywords: 'search', analytics: 'chart', push: 'bell',
    resumes: 'file', builder: 'pen', match: 'target', letter: 'mail', tracker: 'check', billing: 'rupee', referrals: 'gift', wallet: 'wallet',
    'referral-history': 'history', account: 'user'
  };
  const svg = d => `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${d ? `<path d="${d}"/>` : '<circle cx="12" cy="12" r="2.5"/>'}</svg>`;
  const paint = root => root.querySelectorAll('[data-admin-view]>i,[data-workspace-view]>i,[data-ops-toggle]>i').forEach(i => {
    if (i.dataset.icon) return;
    const b = i.parentElement, key = b.dataset.adminView || b.dataset.workspaceView || (b.hasAttribute('data-ops-toggle') ? 'settings' : '');
    i.dataset.icon = '1'; i.setAttribute('aria-hidden', 'true'); i.innerHTML = svg(P[MAP[key]]);
  });
  const start = () => document.querySelectorAll('.side-nav').forEach(n => { paint(n); new MutationObserver(() => paint(n)).observe(n, { childList: true, subtree: true }); });
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
})();
