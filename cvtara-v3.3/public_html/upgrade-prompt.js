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
  #cvUp .up-icons{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:0 0 18px}#cvUp .up-icons div{display:grid;gap:6px;justify-items:center;text-align:center;font-size:13px;font-weight:700;padding:12px 8px;border-radius:14px;background:var(--soft,#f1f5f9)}#cvUp .up-icons span{font-size:24px}
  #cvUp small{display:block;margin-top:14px;color:var(--muted,#64748b);font-size:13px}
  .cv-domain[hidden]{display:none}.cv-domain{margin:0 0 16px;padding:16px 18px;border:1px solid var(--line,#e2e8f0);border-radius:16px;background:var(--surface,#fff);display:flex;flex-wrap:wrap;gap:12px;align-items:end}
  .cv-domain div{flex:1 1 200px}.cv-domain b{display:block;margin-bottom:4px}.cv-domain p{margin:0;color:var(--muted,#64748b);font-size:14px}
  .cv-domain label{display:grid;gap:4px;font-weight:700;font-size:13px}.cv-domain select,.cv-domain input{padding:10px 12px;border:1px solid var(--line,#cbd5e1);border-radius:10px;font:inherit;background:var(--surface,#fff);color:inherit}
  .cv-domain button{padding:11px 18px;border:0;border-radius:11px;background:#1557d2;color:#fff;font-weight:800;cursor:pointer}.cv-domain [role=status]{flex-basis:100%;margin:0;font-size:14px}`;
  const style = document.createElement('style'); style.textContent = css; document.head.append(style);

  const copy = {
    download: { tag: 'Clean download', title: 'Your resume is ready to download', lead: 'Building, saving and previewing stay free. Pick how you want the watermark-free PDF and Word files.', choices: true, steps: [] },
    ats: { tag: 'ATS check', title: 'Run more ATS Match scans with Job Hunt', lead: 'Free accounts get 3 checks a day. Job Hunt and Pro add ATS Match scans with a gap report for each job, and tailor your resume to it in one click.', packs: ['job-hunt', 'pro'], steps: [] },
    career: { tag: 'Job Hunt and Pro', title: 'Tailor, write and practise in one click', lead: 'Whole-resume tailoring, one-click cover letters and mock interviews come with Job Hunt and Pro.', icons: [['✎', 'Resume tailored to each job'], ['✉', 'Cover letter in one click'], ['🎤', 'Timed mock interviews']], packs: ['job-hunt', 'pro'], steps: [], cta: ['See Job Hunt and Pro', '/checkout.html?plan=job-hunt'] },
    verify: { tag: 'Confirm your email', title: 'Confirm your email to continue', lead: 'We sent a 6-digit code to your email. It takes a minute and keeps your account safe.', steps: ['Open the email from CVTara (check spam once)', 'Enter the 6-digit code', 'Come back and carry on where you stopped'], cta: ['Enter my code', '/verify-email'] },
  };
  function show(kind) {
    const c = copy[kind] || copy.download;
    if ((c.choices || c.packs) && !window.CVTaraBusiness?.plans) {
      fetch('/api/business-public', { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then(r => r.json()).then(d => { window.CVTaraBusiness = d; show(kind); }).catch(() => { window.CVTaraBusiness = { plans: {} }; show(kind); });
      return;
    }
    let d = document.getElementById('cvUp');
    if (!d) { d = document.createElement('dialog'); d.id = 'cvUp'; d.setAttribute('aria-labelledby', 'cvUpT'); document.body.append(d); }
    const cta = c.cta || ['See plans', '/checkout.html'];
    const inr = n => '₹' + Number(n || 0).toLocaleString('en-IN');
    const pl = window.CVTaraBusiness?.plans || {}, quick = pl.quick, hunt = pl['job-hunt'];
    // 3.75.0: every "plan needed" moment shows real prices, Job Hunt first, with what one job application costs inside the pack.
    const onSale = k => pl[k] && pl[k].sale !== false;
    const pack = (k, pop) => { const p = pl[k]; const mocks = Number(p.mockInterviews || 0); return `<a${pop ? ' class="pop"' : ''} href="/checkout.html?plan=${E(k)}">${pop ? '<em>Most popular</em>' : ''}<span>${E(p.name)}</span><b>${inr(p.price)}</b><span>${E(p.savedResumes)} tailored resumes, ${E(p.savedLetters)} cover letters${mocks ? `, ${mocks} mock interviews` : ''} · ${E(p.accessDays)} days · about ${inr(Math.ceil(p.price / Math.max(1, p.savedResumes)))} per job</span></a>`; };
    let choices = '';
    if (c.choices && quick && hunt && onSale('quick') && onSale('job-hunt')) choices = `<div class="cv-up-choices">${pack('job-hunt', true)}<a href="/checkout.html?plan=quick"><span>Just this resume</span><b>${inr(quick.price)}</b><span>Clean PDF + Word, edit and re-download for ${E(quick.accessDays)} days. Counts toward Job Hunt if you upgrade within 7 days.</span></a></div>`;
    else if (c.packs) { const ks = c.packs.filter(onSale); if (ks.length) choices = `<div class="cv-up-choices">${ks.map((k, i) => pack(k, i === 0 && k === 'job-hunt')).join('')}</div>`; }
    d.innerHTML = `<div class="up-top"><span>${E(c.tag)}</span><h2 id="cvUpT">${E(c.title)}</h2></div><div class="up-body"><p>${E(c.lead)}</p>${c.icons ? `<div class="up-icons">${c.icons.map(([i, t]) => `<div><span aria-hidden="true">${i}</span>${E(t)}</div>`).join('')}</div>` : ''}${choices}${c.steps.length ? `<ol>${c.steps.map(s => `<li>${E(s)}</li>`).join('')}</ol>` : ''}<div class="up-cta">${choices ? '' : `<a class="up-main" href="${E(cta[1])}">${E(cta[0])}</a>`}<button type="button" data-x>Not now</button></div><small>7-day refund if unused · Pay once, no auto-renewal · UPI, cards and net banking. Your saved work stays in your account either way.</small></div>`;
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
      if (res.status === 402 && /\/api\/(letters\/draft|resumes\/[a-f0-9-]{36}\/tailor)/.test(url)) show('career');
      else if (res.status === 402 && /\/export\//.test(url)) show('download');
      else if (res.status === 402 && /\/api\/ats\//.test(url)) show('ats');
      else if (res.status === 403 && /\/api\/(ats\/scan|checkout)/.test(url)) {
        const j = await res.clone().json().catch(() => null);
        if (j && j.verify) show('verify');
      } else if (res.status === 422 && /\/api\/interviews\/start/.test(url)) {
        const j = await res.clone().json().catch(() => null);
        if (j && /no mock interviews left/i.test(JSON.stringify(j.errors || j.message || ''))) show('career');
      } else if (res.status === 422 && /\/api\/ats\/scan/.test(url)) {
        const j = await res.clone().json().catch(() => null);
        if (j && j.errors && j.errors.limit && /free ATS checks|All your ATS scans/i.test(String(j.errors.limit))) show('ats');
      }
    } catch { /* the page still shows its own message */ }
    return res;
  };

  // "Target field" for the primary resume: moving from a non-technical to a technical job (or the reverse) updates the profile so
  // recommendations, examples and the next resume start from the right field.
  const FIELDS = ['Technology', 'Data & Analytics', 'Design', 'HR & Recruitment', 'Sales & Marketing', 'Finance', 'Banking & Insurance', 'Accounting & Audit', 'Operations', 'Supply Chain & Logistics', 'Manufacturing & Engineering', 'Customer Support & BPO', 'Retail & E-commerce', 'Hospitality & Travel', 'Healthcare', 'Pharma & Life Sciences', 'Education', 'Legal & Compliance', 'Government & Public Sector', 'Media & Content', 'Administration & Office', 'Construction & Real Estate', 'Telecom', 'Consulting', 'Other'];
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
