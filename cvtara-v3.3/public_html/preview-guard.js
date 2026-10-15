/* Preview protection: on-screen resume and cover-letter previews always carry a traceable watermark and cannot be
   selected, copied, dragged or printed from the page. This discourages casual copying; no website can fully stop a
   screenshot or screen recording (the browser gives pages no control over the operating system's screen capture). */
(() => {
  'use strict';
  const SEL = '.studio-paper,.studio-inspect-paper,.studio-template-paper,#letterLiveDocument,#letterInspectDocument,.ref-resume,.letter-document';
  const css = document.createElement('style');
  css.textContent = `
body.free-resume-preview :is(${SEL}){-webkit-user-select:none;user-select:none;-webkit-touch-callout:none;position:relative}
body.free-resume-preview :is(.studio-paper,.studio-inspect-paper,#letterLiveDocument,#letterInspectDocument)::before{content:"";position:absolute;inset:0;z-index:5;pointer-events:none;background-image:var(--pg-tile);background-repeat:repeat}
body.free-resume-preview :is(${SEL}) *{-webkit-user-drag:none}
body.pg-hide :is(${SEL}){filter:blur(22px)!important;transition:filter .1s}
@media print{body.free-resume-preview :is(${SEL}){display:none!important}body.free-resume-preview::after{content:"Previews cannot be printed. Download your document from CVTara (cvtara.com).";display:block;font:16px system-ui;padding:40px;text-align:center}}`;
  document.head.append(css);

  function tile(label) {
    const t = String(label || 'cvtara.com').replace(/[<>&"']/g, '');
    const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='260' height='170'><text x='130' y='85' text-anchor='middle' transform='rotate(-28 130 85)' font-family='Arial' font-size='15' font-weight='700' fill='rgba(60,75,105,0.20)'>${t}</text></svg>`;
    document.documentElement.style.setProperty('--pg-tile', 'url("data:image/svg+xml;utf8,' + encodeURIComponent(svg) + '")');
  }
  tile('CVTara · cvtara.com · preview');
  // A per-account code in the watermark lets us trace a leaked preview back to the account.
  if (window.CVTaraAPI) window.CVTaraAPI.request('/api/profile').then(p => { if (p && p.user) tile('CVTara · cvtara.com · preview · ID ' + p.user.id); }).catch(() => {});

  const guarded = el => el && el.closest && el.closest(SEL) && document.body.classList.contains('free-resume-preview');
  ['contextmenu', 'dragstart', 'copy', 'cut', 'selectstart'].forEach(ev => document.addEventListener(ev, e => { if (guarded(e.target)) e.preventDefault(); }, true));
  document.addEventListener('keydown', e => {
    if (!document.body.classList.contains('free-resume-preview') || !document.querySelector(SEL)) return;
    const k = (e.key || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && ['p', 's', 'u'].includes(k)) { e.preventDefault(); }
  }, true);
  const hideBriefly = () => { document.body.classList.add('pg-hide'); clearTimeout(hideBriefly.t); hideBriefly.t = setTimeout(() => document.body.classList.remove('pg-hide'), 1800); };
  document.addEventListener('keyup', e => {
    if (e.key === 'PrintScreen' || (e.metaKey && e.shiftKey && ['3', '4', '5', 's'].includes((e.key || '').toLowerCase()))) { try { navigator.clipboard && navigator.clipboard.writeText(''); } catch (x) { /* blocked */ } hideBriefly(); }
  }, true);
  document.addEventListener('visibilitychange', () => { if (document.hidden) document.body.classList.add('pg-hide'); else setTimeout(() => document.body.classList.remove('pg-hide'), 250); });
  window.addEventListener('blur', () => document.body.classList.add('pg-hide'));
  window.addEventListener('focus', () => setTimeout(() => document.body.classList.remove('pg-hide'), 250));
})();
