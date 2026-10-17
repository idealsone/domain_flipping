(() => {
  'use strict';
  // Download dialog help for accounts without a plan: one clear path, pay once and download.
  const originalExport = window.CVTaraExport;
  if (!originalExport) return;
  window.CVTaraExport = (type, id) => {
    originalExport(type, id);
    const dialog = document.getElementById('exportDialog');
    if (!dialog || type !== 'resume') return;
    window.CVTaraAPI.request('/api/allowance').then(a => { if (!a.paid) addUnlock(dialog); }).catch(() => addUnlock(dialog));
  };
  function addUnlock(dialog) {
    if (dialog.querySelector('.launch-panel')) return;
    const section = document.createElement('section');
    section.className = 'launch-panel';
    section.innerHTML = '<h3>Unlock clean downloads</h3><p>Your saved work stays in your profile. A plan gives you watermark-free PDF and Word files, paid once with no auto-renewal.</p><a class="button primary" href="/workspace.html?view=billing">View plans &amp; upgrade</a>';
    dialog.querySelector('[data-close]').before(section);
  }
})();
