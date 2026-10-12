(() => {
  'use strict';
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function resumeText(d) {
    const p = d.personal || {};
    const loc = [p.city, p.state].filter(Boolean).join(', ');
    const blocks = [
      p.name, p.role, [p.email, p.phone, loc].filter(Boolean).join(' · '),
      d.summary,
      'EXPERIENCE',
      ...(d.experience || []).map(r => [r.role, r.company, r.city, r.bullets].filter(Boolean).join('\n')),
      'EDUCATION',
      ...(d.education || []).map(r => [r.degree, r.school, r.details].filter(Boolean).join('\n')),
      'PROJECTS',
      ...(d.projects || []).map(r => [r.name, r.details].filter(Boolean).join('\n')),
      'SKILLS', d.skills, 'LANGUAGES', d.languages
    ];
    return blocks.filter(Boolean).join('\n\n').slice(0, 120000);
  }
  function banner() {
    const panel = document.querySelector('#view-match .match-input-panel');
    if (!panel || document.getElementById('matchResumeSource')) return;
    panel.insertAdjacentHTML('afterbegin', `<div class="match-resume-pick">
      <label>Compare with a saved resume
        <select id="matchResumeSource"><option value="">Choose a saved resume</option></select>
      </label>
      <p id="matchIdentity" class="match-identity" role="status">Pick a resume from your account. For security, the name must match this account. Email and phone must also match when they appear on the resume.</p>
    </div>`);
    document.getElementById('matchResumeSource').addEventListener('change', loadSelected);
  }
  async function fillList() {
    banner();
    const select = document.getElementById('matchResumeSource');
    if (!select || !window.CVTaraAPI) return;
    try {
      const r = await window.CVTaraAPI.request('/api/resumes');
      const current = select.value;
      select.innerHTML = '<option value="">Choose a saved resume</option>' + (r.resumes || []).map(x => `<option value="${E(x.id)}">${E(x.title)}${x.role ? ' · ' + E(x.role) : ''}</option>`).join('');
      if (current && [...select.options].some(o => o.value === current)) select.value = current;
    } catch {
      const status = document.getElementById('matchIdentity');
      if (status) status.textContent = 'Could not load saved resumes. You can still paste or upload a file.';
    }
  }
  async function loadSelected() {
    const id = document.getElementById('matchResumeSource')?.value;
    const status = document.getElementById('matchIdentity');
    const text = document.getElementById('matchText');
    if (!id) {
      if (status) { status.textContent = 'Pick a resume from your account, or upload a file that belongs to you.'; status.className = 'match-identity'; }
      return;
    }
    if (status) status.textContent = 'Checking that this resume belongs to your account…';
    try {
      const check = await window.CVTaraAPI.request('/api/match/identity', {method: 'POST', body: {resumeId: id}});
      if (!check.allowed) {
        if (status) {
          status.className = 'match-identity match-identity-bad';
          status.textContent = check.reason || 'This resume does not match your account name. For security, Resume Match only runs on a file that belongs to you.';
        }
        if (text) text.value = '';
        return;
      }
      const {resume} = await window.CVTaraAPI.request('/api/resumes/' + encodeURIComponent(id));
      if (text) {
        text.value = resumeText(resume.data);
        text.dispatchEvent(new Event('input', {bubbles: true}));
      }
      const review = document.getElementById('matchReviewed');
      if (review) review.checked = false;
      if (status) {
        status.className = 'match-identity match-identity-ok';
        status.textContent = 'Identity matched' + (check.name ? ' · name' : '') + (check.email ? ' · email' : '') + (check.phone ? ' · phone' : '') + '. Review the text, then compare it with the job.';
      }
    } catch (error) {
      if (status) { status.className = 'match-identity match-identity-bad'; status.textContent = error.message; }
    }
  }
  document.addEventListener('cvtara-workspace-view', e => { if (e.detail === 'match') fillList(); });
  document.addEventListener('cvtara-resume-saved', fillList);
  if (document.getElementById('view-match')) fillList();
})();
