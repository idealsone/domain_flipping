(() => {
  'use strict';
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function setAvatar(profile) {
    const img = document.getElementById('accountAvatar');
    const initials = document.getElementById('accountInitials');
    const photo = profile?.photo_url || '';
    const name = window.CVTaraDisplay ? window.CVTaraDisplay.pick(profile, window.CVTaraProfile?.user) : String(profile?.name || '').trim();
    if (initials) initials.textContent = name ? name.slice(0, 1).toUpperCase() : 'Me';
    if (!img) return;
    if (photo && /^data:image\/(jpeg|png|webp);base64,/.test(photo)) {
      img.src = photo;
      img.hidden = false;
      if (initials) initials.hidden = true;
    } else {
      img.removeAttribute('src');
      img.hidden = true;
      if (initials) initials.hidden = false;
    }
  }
  function nextAction(p, a, resumes) {
    if (!p?.name) return {score: '01', label: 'Complete your profile'};
    if (!resumes.length) return {score: '02', label: 'Build your first resume'};
    if (!(a?.paid)) return {score: '03', label: 'Unlock a clean download'};
    return {score: '✓', label: 'Match a resume to a job'};
  }
  async function paint() {
    if (!window.CVTaraAPI || !document.getElementById('view-dashboard')) return;
    try {
      const [p, a, r, l, apps] = await Promise.all([
        window.CVTaraAPI.request('/api/profile'),
        window.CVTaraAPI.request('/api/allowance').catch(() => ({limits: {}, used: {}})),
        window.CVTaraAPI.request('/api/resumes').catch(() => ({resumes: []})),
        window.CVTaraAPI.request('/api/letters').catch(() => ({letters: []})),
        window.CVTaraAPI.request('/api/applications').catch(() => ({applications: []}))
      ]);
      const profile = p.profile || {};
      const full = window.CVTaraDisplay ? window.CVTaraDisplay.pick(profile, p.user) : String(profile.name || '').trim();
      const first = full ? full.split(/\s+/)[0] : '';
      const welcome = document.getElementById('dashWelcome');
      if (welcome) welcome.textContent = first ? 'Welcome back, ' + first : 'Welcome back';
      const headline = document.getElementById('dashHeadline');
      if (headline) headline.textContent = first ? first + ', finish the file recruiters will actually open.' : 'Your next application, finished here.';
      setAvatar(profile);
      const used = a.used || {};
      const limits = a.limits || {};
      const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
      set('dashResumes', (used.savedResumes ?? r.resumes?.length ?? 0) + ' / ' + (limits.savedResumes ?? '—'));
      set('dashLetters', (l.letters?.length ?? 0) + ' / ' + (limits.savedLetters ?? limits.savedResumes ?? '—'));
      set('dashApps', String(apps.applications?.length ?? 0));
      set('dashExports', (used.exports ?? 0) + ' / ' + (limits.exports ?? 0));
      const next = nextAction(profile, a, r.resumes || []);
      set('dashNextScore', next.score);
      set('dashNextLabel', next.label);
      const latest = (r.resumes || [])[0];
      if (latest) {
        set('dashRecentTitle', latest.title);
        const meta = document.getElementById('dashRecentMeta');
        if (meta) meta.textContent = (latest.role || latest.template || 'Resume') + ' · updated ' + new Date(latest.updated_at).toLocaleDateString();
        const action = document.getElementById('dashRecentAction');
        if (action) { action.textContent = 'Continue editing →'; action.setAttribute('data-workspace-view', 'builder'); }
      }
      window.CVTaraProfile = p;
    } catch {}
  }
  document.addEventListener('cvtara-profile-rendered', e => {
    setAvatar(e.detail?.profile);
    paint();
  });
  document.addEventListener('cvtara-photo-updated', e => setAvatar({photo_url: e.detail?.photo, name: window.CVTaraProfile?.profile?.name}));
  document.addEventListener('cvtara-resume-saved', paint);
  document.addEventListener('cvtara-letter-saved', paint);
  document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'dashboard') paint(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', paint);
  else paint();
  window.CVTaraAccountPhoto = {set: setAvatar};
})();
