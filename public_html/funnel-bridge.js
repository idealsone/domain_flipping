(() => {
  'use strict';
  // Runs first on workspace.html. If the visitor just ran the landing-page check and chose "Build my
  // tailored resume", hand the resume text to the builder's existing import and remember the job text.
  try {
    const raw = localStorage.getItem('cvtara-funnel');
    if (!raw) return;
    const s = JSON.parse(raw);
    localStorage.removeItem('cvtara-funnel'); // resume text should not linger in the browser
    if (!s || typeof s.resume !== 'string' || !s.resume.trim() || Date.now() - Number(s.at || 0) > 2 * 60 * 60 * 1000) return;
    sessionStorage.setItem('cvtara-import-pending', s.resume.slice(0, 120000));
    localStorage.setItem('cvtara-target-job', JSON.stringify({ jd: String(s.jd || '').slice(0, 12000), ats: s.ats ?? null, match: s.match ?? null, at: Date.now(), fresh: true }));
    const q = new URLSearchParams(location.search);
    if (q.get('view') !== 'builder') { q.set('view', 'builder'); q.set('from', 'check'); history.replaceState(null, '', location.pathname + '?' + q.toString()); }
  } catch { /* storage unavailable: the user can still paste text into the builder */ }
})();
