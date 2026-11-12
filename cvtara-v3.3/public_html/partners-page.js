/* For colleges, coaching and partners page (3.60.0): sends the partner application to /api/partners/apply. */
(() => {
  'use strict';
  const f = document.getElementById('partnerForm'); if (!f) return;
  const st = document.getElementById('partnerStatus');
  f.addEventListener('submit', async e => {
    e.preventDefault();
    if (!f.reportValidity()) return;
    const body = Object.fromEntries(new FormData(f));
    Object.keys(body).forEach(k => { if (body[k] === '' && k !== 'website') delete body[k]; });
    const btn = f.querySelector('button[type=submit]'); btn.disabled = true; st.textContent = 'Sending…';
    try {
      const r = await window.CVTaraAPI.request('/api/partners/apply', { method: 'POST', body });
      st.textContent = r.message || 'Thank you. We will reply by email within two working days.';
      f.reset();
    } catch (x) { st.textContent = (x && x.message) || 'Could not send. Please email support@cvtara.com.'; btn.disabled = false; }
  });
})();
