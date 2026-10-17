(() => {
  'use strict';
  // One friendly dialog for every "a plan is needed" moment (clean download, ATS check, buying before the email is confirmed),
  // and a small "target field" control so the primary resume can be pointed at a new kind of job.
  if (window.CVTaraUpgrade) return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const css = `
  #cvUp{border:0;padding:0;border-radius:22px;max-width:min(520px,calc(100vw - 28px));width:100%;box-shadow:0 30px 80px rgba(15,23,42,.35);background:var(--surface,#fff);color:var(--ink,#0f172a);font-family:inherit}
  #cvUp::backdrop{background:rgba(15,23,42,.55);backdrop-filter:blur(3px)}
  #cvUp .up-top{padding:26px 28px 18px;background:linear-gradient(135deg,#1e1b4b,#3730a3);color:#fff}
  #cvUp .up-top span{display:inline-block;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#c7d2fe}
  #cvUp h2{margin:8px 0 0;font-size:24px;line-height:1.2;letter-spacing:-.02em;color:#fff}
  #cvUp .up-body{padding:22px 28px 26px}
  #cvUp p{margin:0 0 14px;line-height:1.6;color:var(--muted,#475569)}
  #cvUp ol{margin:0 0 18px;padding:0;list-style:none;display:grid;gap:10px;counter-reset:s}
  #cvUp li{counter-increment:s;display:flex;gap:12px;align-items:flex-start;line-height:1.5;color:var(--ink,#0f172a)}
  #cvUp li:before{content:counter(s);flex:none;width:26px;height:26px;border-radius:50%;background:#eef2ff;color:#3730a3;font-weight:800;font-size:13px;display:grid;place-items:center}
  #cvUp li.done:before{content:"✓";background:#dcfce7;color:#166534}
  #cvUp .up-cta{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
  #cvUp .up-cta a.up-main{padding:13px 22px;border-radius:12px;background:#1557d2;color:#fff;font-weight:800;text-decoration:none}
  #cvUp .up-cta button{padding:12px 16px;border:0;background:transparent;color:var(--muted,#475569);font-weight:700;cursor:pointer;border-radius:10px}
  #cvUp small{display:block;margin-top:14px;color:var(--muted,#64748b);font-size:13px}
  .cv-domain[hidden]{display:none}.cv-domain{margin:0 0 16px;padding:16px 18px;border:1px solid var(--line,#e2e8f0);border-radius:16px;background:var(--surface,#fff);display:flex;flex-wrap:wrap;gap:12px;align-items:end}
  .cv-domain div{flex:1 1 200px}.cv-domain b{display:block;margin-bottom:4px}.cv-domain p{margin:0;color:var(--muted,#64748b);font-size:14px}
  .cv-domain label{display:grid;gap:4px;font-weight:700;font-size:13px}.cv-domain select,.cv-domain input{padding:10px 12px;border:1px solid var(--line,#cbd5e1);border-radius:10px;font:inherit;background:var(--surface,#fff);color:inherit}
  .cv-domain button{padding:11px 18px;border:0;border-radius:11px;background:#1557d2;color:#fff;font-weight:800;cursor:pointer}.cv-domain [role=status]{flex-basis:100%;margin:0;font-size:14px}`;
  const style = document.createElement('style'); style.textContent = css; document.head.append(style);

  const copy = {
    download: { tag: 'Clean download', title: 'Unlock clean downloads with a plan', lead: 'You can build, preview and save for free. Downloads without a watermark come with a plan.', steps: ['Choose a plan (paid once, no auto-renewal)', 'Download clean PDF and Word files that are ready to send', 'Then run the ATS check against the job you want'] },
    ats: { tag: 'ATS check', title: 'A plan is needed to run more ATS checks', lead: 'Free accounts get a few checks. To check your resume against more jobs and see exactly what each one needs, pick a plan first.', steps: ['Choose a plan (paid once, no auto-renewal)', 'Run ATS checks against the jobs you are applying for', 'Fix the missing requirements and download a clean copy'] },
    verify: { tag: 'Confirm your email', title: 'Confirm your email to continue', lead: 'We sent a 6-digit code to your email. It takes a minute and keeps your account safe.', steps: ['Open the email from CVTara (check spam once)', 'Enter the 6-digit code', 'Come back and carry on where you stopped'], cta: ['Enter my code', '/verify-email'] },
  };
  function show(kind) {
    const c = copy[kind] || copy.download;
    let d = document.getElementById('cvUp');
    if (!d) { d = document.createElement('dialog'); d.id = 'cvUp'; d.setAttribute('aria-labelledby', 'cvUpT'); document.body.append(d); }
    const cta = c.cta || ['See plans', '/checkout.html'];
    d.innerHTML = `<div class="up-top"><span>${E(c.tag)}</span><h2 id="cvUpT">${E(c.title)}</h2></div><div class="up-body"><p>${E(c.lead)}</p><ol>${c.steps.map(s => `<li>${E(s)}</li>`).join('')}</ol><div class="up-cta"><a class="up-main" href="${E(cta[1])}">${E(cta[0])}</a><button type="button" data-x>Not now</button></div><small>All payments are final and non-refundable. Your saved work stays in your account either way.</small></div>`;
    d.querySelector('[data-x]').onclick = () => d.close();
    document.querySelectorAll('dialog[open]').forEach(o => { if (o !== d && o.id === 'exportDialog') o.close(); });
    if (!d.open) d.showModal();
  }
  window.CVTaraUpgrade = { show };

  // Any API or export answer that says "a plan is needed" or "confirm your email" opens the dialog.
  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init) => {
    const res = await nativeFetch(input, init);
    try {
      const url = String(typeof input === 'string' ? input : input?.url || '');
      if (res.status === 402 && /\/export\//.test(url)) show('download');
      else if (res.status === 402 && /\/api\/ats\//.test(url)) show('ats');
      else if (res.status === 403 && /\/api\/(ats\/scan|checkout)/.test(url)) {
        const j = await res.clone().json().catch(() => null);
        if (j && j.verify) show('verify');
      } else if (res.status === 422 && /\/api\/ats\/scan/.test(url)) {
        const j = await res.clone().json().catch(() => null);
        if (j && j.errors && j.errors.limit && /free ATS checks|All your ATS scans/i.test(String(j.errors.limit))) show('ats');
      }
    } catch { /* the page still shows its own message */ }
    return res;
  };

  // "Target field" for the primary resume: moving from a non-technical to a technical job (or the reverse) updates the profile so
  // recommendations, examples and the next resume start from the right field.
  const FIELDS = ['Technology', 'HR & Recruitment', 'Sales & Marketing', 'Finance', 'Operations', 'Education', 'Healthcare', 'Design', 'Other'];
  async function mountDomain() {
    if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI || document.getElementById('cvDomain')) return;
    if ((document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest') return;
    const host = document.getElementById('view-resumes');
    if (!host) return;
    let p; try { p = (await window.CVTaraAPI.request('/api/profile')).profile; } catch { return; }
    if (!p || !p.onboarding_done) return;
    const box = document.createElement('form'); box.id = 'cvDomain'; box.className = 'cv-domain';
    box.innerHTML = `<div><b>Job field you are applying in</b><p>Planning to move to a different kind of job, for example from non-technical to technical? Change it here and your suggestions follow.</p></div><label>Field<select name="category">${FIELDS.map(f => `<option${f === p.category ? ' selected' : ''}>${E(f)}</option>`).join('')}</select></label><label>Target job title<input name="profession" maxlength="120" value="${E(p.profession || '')}"></label><button>Update</button><p role="status"></p>`;
    host.before(box);
    const sync = () => { box.hidden = !host.classList.contains('active'); };
    sync(); new MutationObserver(sync).observe(host, { attributes: true, attributeFilter: ['class'] });
    box.onsubmit = async e => {
      e.preventDefault(); const s = box.querySelector('[role=status]'); const f = new FormData(box); s.textContent = 'Saving…';
      try {
        await window.CVTaraAPI.request('/api/profile', { method: 'POST', body: { name: p.name, phone: p.phone || '', language: 'en', experienceLevel: p.experience_level, profession: f.get('profession'), category: f.get('category'), experienceYears: Number(p.experience_years || 0), city: p.city || '', marketingConsent: !!p.marketing_consent } });
        s.textContent = 'Updated. Refreshing your suggestions…'; setTimeout(() => location.reload(), 700);
      } catch (err) { s.textContent = err.message || 'Could not save. Try again.'; }
    };
  }
  document.addEventListener('DOMContentLoaded', () => setTimeout(mountDomain, 600));
})();
