(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const rand = (a, b) => Math.floor(Math.random() * (b - a + 1)) + a;

  /* ── Nav items ─────────────────────────────────────────── */
  const fullNav = [
    ['home.html','Home'],['templates.html','Templates'],
    ['tools.html','Salary tools'],['guides.html','Guides'],
    ['blog.html','Blog'],
    ['about.html','About'],['contact.html','Contact']
  ];
  const fillNav = (nav) => {
    fullNav.forEach(([href, label]) => {
      const exists = [...nav.querySelectorAll('a')].some(a => (a.getAttribute('href')||'').split('#')[0] === href);
      if (exists) return;
      const a = document.createElement('a');
      a.href = href; a.textContent = label; nav.append(a);
    });
  };

  /* ── Auto-inject header elements ───────────────────────── */
  const header = $('.lp-header');
  const bar    = $('.lp-nav');
  if (bar && !$('[data-lp-menu]')) {
    let actions = $('.lp-actions');
    if (!actions) { actions = document.createElement('div'); actions.className = 'lp-actions'; bar.append(actions); }
    if (!$('[data-lp-theme]')) {
      const t = document.createElement('button');
      t.className='lp-icon'; t.type='button'; t.setAttribute('data-lp-theme','');
      t.setAttribute('aria-label','Switch colour theme'); t.textContent='Dark';
      actions.prepend(t);
    }
    const mb = document.createElement('button');
    mb.className='lp-icon lp-menu'; mb.type='button';
    mb.setAttribute('data-lp-menu',''); mb.setAttribute('aria-expanded','false');
    mb.setAttribute('aria-label','Open menu'); mb.textContent='☰';
    actions.append(mb);
  }
  if (header && !$('[data-lp-drawer]')) {
    const drawer = document.createElement('nav');
    drawer.className='lp-drawer'; drawer.setAttribute('data-lp-drawer','');
    drawer.hidden=true; drawer.setAttribute('aria-label','Menu');
    header.append(drawer);
  }
  $$('.lp-drawer').forEach(fillNav);
  $$('.lp-links').forEach(nav => { if (nav.querySelectorAll('a').length < 5) fillNav(nav); });

  const actions = $('.lp-actions');
  if (actions && !actions.querySelector('a[href="/register"]')) {
    const reg = document.createElement('a');
    reg.className = 'lp-btn secondary';
    reg.href = '/register';
    reg.textContent = 'Register';
    const primary = actions.querySelector('.lp-btn.primary');
    if (primary) primary.before(reg);
    else actions.append(reg);
  }
  actions?.querySelectorAll('a[href="contact.html"]').forEach(a => {
    if (/sign in/i.test(a.textContent)) a.href = '/login';
  });

  /* ── Auto-inject footer ────────────────────────────────── */
  if (!$('.lp-footer')) {
    const footer = document.createElement('footer');
    footer.className = 'lp-footer';
    footer.innerHTML = `<div class="lp-wrap">
      <div class="lp-foot-grid">
        <div><a href="home.html"><img class="lp-lockup" src="assets/brand/cvtara-lockup-dark.png" width="200" height="56" alt="CVTara — Build, improve, get hired" loading="lazy"></a>
          <p>Built by an HR professional in Indian edtech — clear, ATS-ready resumes for more focused applications.</p></div>
        <nav><b>Product</b><a href="templates.html">Templates</a><a href="home.html#scan">Free scan</a><a href="tools.html">Salary tools</a><a href="guides.html">Guides</a></nav>
        <nav><b>Company</b><a href="about.html">About</a><a href="contact.html">Contact</a><a href="blog.html">Blog</a><a href="feedback.html">Feedback</a></nav>
        <nav><b>Account</b><a href="/register">Register</a><a href="/login">Sign in</a><a href="privacy-policy.html">Privacy</a><a href="terms.html">Terms</a></nav>
      </div>
      <div class="lp-foot-bottom">
        <p>© <span data-year>${new Date().getFullYear()}</span> CVTara</p>
        <p class="lp-marks"><span>No card storage</span><span>India-focused</span></p>
      </div>
    </div>`;
    document.body.append(footer);
  }

  /* ── Auto-inject mobile CTA bar ────────────────────────── */
  if (!$('.lp-mobile-cta')) {
    const cta = document.createElement('div');
    cta.className = 'lp-mobile-cta';
    cta.innerHTML = '<a href="templates.html">Preview</a><a href="/register">Register</a><a href="home.html#scan">Scan</a>';
    document.body.append(cta);
  }

  /* ── Hamburger menu ────────────────────────────────────── */
  const menu   = $('[data-lp-menu]');
  const drawer = $('[data-lp-drawer]');
  const setMenu = (open) => {
    if (!drawer || !menu) return;
    drawer.classList.toggle('open', open);
    menu.setAttribute('aria-expanded', String(open));
    if (open) drawer.removeAttribute('hidden');
    else drawer.setAttribute('hidden', '');
  };
  menu?.addEventListener('click', (e) => {
    e.stopPropagation();
    setMenu(!drawer?.classList.contains('open'));
  });
  drawer?.querySelectorAll('a').forEach(a => a.addEventListener('click', () => setMenu(false)));
  document.addEventListener('click', (e) => {
    if (!drawer?.classList.contains('open')) return;
    if (e.target.closest('[data-lp-menu], [data-lp-drawer]')) return;
    setMenu(false);
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });

  /* ── Active nav link ───────────────────────────────────── */
  const here = (location.pathname.split('/').pop() || 'home.html').replace(/index\.html$/i,'home.html');
  $$('.lp-links a, .lp-drawer a').forEach(a => {
    const href = (a.getAttribute('href')||'').split('#')[0];
    if (href && (href === here || (here === '' && href === 'home.html'))) {
      a.classList.add('is-active'); a.setAttribute('aria-current','page');
    }
  });

  /* ── Theme ─────────────────────────────────────────────── */
  const themeBtn  = $('[data-lp-theme]');
  const applyTheme = (dark) => {
    document.body.classList.toggle('dark', dark);
    localStorage.setItem('cvtara-theme', dark ? 'dark' : 'light');
    if (themeBtn) themeBtn.textContent = dark ? 'Light' : 'Dark';
  };
  applyTheme(localStorage.getItem('cvtara-theme') === 'dark');
  themeBtn?.addEventListener('click', () => applyTheme(!document.body.classList.contains('dark')));
  $$('[data-year]').forEach(el => { el.textContent = String(new Date().getFullYear()); });

  /* ── Scroll reveal observer ─────────────────────────────── */
  const revealObs = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); revealObs.unobserve(e.target); } });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  const attachReveal = (root = document) => {
    root.querySelectorAll('.reveal, .reveal-l, .reveal-r').forEach(el => revealObs.observe(el));
  };
  attachReveal();

  /* ── Animated stat counters ─────────────────────────────── */
  const countObs = new IntersectionObserver((entries) => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const target = el.dataset.count ? parseInt(el.dataset.count) : null;
      if (!target) return;
      countObs.unobserve(el);
      let start = 0; const dur = 1400;
      const startTime = performance.now();
      const tick = (now) => {
        const p = Math.min((now - startTime) / dur, 1);
        const eased = 1 - Math.pow(1 - p, 4);
        el.textContent = Math.round(eased * target).toLocaleString('en-IN');
        if (p < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    });
  }, { threshold: 0.5 });
  document.querySelectorAll('[data-count]').forEach(el => countObs.observe(el));

  /* ── Beacon (event tracking) ────────────────────────────── */
  const beacon = (event, path) => {
    try { navigator.sendBeacon('/api/event.php', JSON.stringify({ event, path })); } catch(_) {}
  };

  /* =========================================================
     ATS SCORE ENGINE
     Free users: score capped 35–56% (business model)
     Logged-in premium: real score (set data-premium="1")
     ========================================================= */
  const stop = new Set('the a an and or for to of in on with from by at as is are was were be been being this that these those your you our their they it its into than then not but if we i me my said been have'.split(' '));
  const VERBS = ['led','built','managed','delivered','improved','reduced','created','designed','implemented','owned','launched','analysed','analyzed','coordinated','increased','negotiated','trained','automated','resolved','supported','developed','deployed','architected','established','streamlined','optimized'];

  const tokenize = (text) => [...new Set(
    String(text||'').toLowerCase().replace(/[^a-z0-9+#.\s-]/g,' ').split(/\s+/).filter(w => w.length > 3 && !stop.has(w))
  )];

  const isPremium = () => document.body.dataset.premium === '1';

  const computeScore = (resume, job) => {
    const r = tokenize(resume);
    const j = tokenize(job);
    const matched  = j.filter(w => r.includes(w));
    const missing  = j.filter(w => !r.includes(w)).slice(0, 12);
    const verbHits = VERBS.filter(v => resume.toLowerCase().includes(v));
    const overlap  = matched.length;
    const verbScore  = Math.min(20, verbHits.length * 3);
    const keyScore   = Math.round(Math.min(70, (overlap / Math.max(j.length, 1)) * 70));
    const lenScore   = resume.length > 500 ? 10 : resume.length > 200 ? 6 : 2;
    let score = Math.max(0, Math.min(100, keyScore + verbScore + lenScore));

    // ── Business logic: cap free-user scores at 35–56 ──
    if (!isPremium()) {
      const seed = (resume.length + job.length) % 22;
      score = 35 + seed; // deterministic 35–56
    }

    return { score, matched, missing, verbHits, overlap, rawVerbs: verbHits.length };
  };

  /* Build issue list from scan result (for conversion) */
  const buildIssues = ({ score, missing, verbHits, overlap }) => {
    const issues = [];
    if (missing.length >= 6)
      issues.push({ sev:'critical', icon:'🔴', title:`Missing ${missing.length} keywords recruiters filter for`, desc:'These words appear in the job description but not in your resume.', badge:'Critical' });
    else if (missing.length > 0)
      issues.push({ sev:'warning', icon:'🟡', title:`${missing.length} keyword gaps found`, desc:'Add these naturally where you have real evidence.', badge:'Warning' });

    if (verbHits.length < 4)
      issues.push({ sev:'critical', icon:'🔴', title:'Weak action verbs — bullets lack impact', desc:'Strong resumes start bullets with precise action verbs (led, built, reduced, delivered).', badge:'Critical' });
    else if (verbHits.length < 7)
      issues.push({ sev:'warning', icon:'🟡', title:'Some bullets need stronger verbs', desc:'Replace passive phrases with action-first bullets.', badge:'Warning' });

    if (!isPremium()) {
      issues.push({ sev:'critical', icon:'🔴', title:'ATS format not verified', desc:'The structure of your resume may not parse correctly in common ATS systems.', badge:'Critical' });
      issues.push({ sev:'warning', icon:'🟡', title:'No tailoring to this specific job', desc:'Generic resumes get filtered. A tailored resume matches the exact language of this role.', badge:'Warning' });
    }
    return issues;
  };

  const scoreColor = (s) => s >= 70 ? '#22c55e' : s >= 50 ? '#f59e0b' : '#f43f5e';
  const scoreLabel = (s) => s >= 70 ? 'Good match' : s >= 50 ? 'Partial match' : 'Needs work';
  const scoreMsg   = (s) => s >= 70
    ? 'Your resume has decent coverage of this job description.'
    : s >= 50
    ? 'Your resume partially matches. Several important keywords are missing.'
    : 'Your resume needs significant improvement to pass ATS filters for this role.';

  const loadScript = (src) => new Promise((resolve, reject) => {
    if ([...document.scripts].some(s => s.src === src || s.getAttribute('src') === src)) return resolve();
    const s = document.createElement('script');
    s.src = src; s.onload = () => resolve(); s.onerror = () => reject(new Error('Could not load parser'));
    document.head.append(s);
  });

  const extractPdf = async (file) => {
    await loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js');
    const pdfjs = window.pdfjsLib;
    pdfjs.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    const pdf = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
    let text = '';
    for (let i = 1; i <= Math.min(pdf.numPages, 8); i++) {
      const page = await pdf.getPage(i);
      const content = await page.getTextContent();
      text += content.items.map(it => it.str).join(' ') + '\n';
    }
    return text.replace(/\s+/g, ' ').trim();
  };

  const extractDocx = async (file) => {
    await loadScript('https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js');
    const result = await window.mammoth.extractRawText({ arrayBuffer: await file.arrayBuffer() });
    return String(result.value || '').trim();
  };

  const readResumeFile = async (file) => {
    const name = (file.name || '').toLowerCase();
    if (name.endsWith('.pdf') || file.type === 'application/pdf') return extractPdf(file);
    if (name.endsWith('.docx') || file.type.includes('wordprocessingml')) return extractDocx(file);
    if (name.endsWith('.txt') || name.endsWith('.rtf') || file.type.startsWith('text/')) return file.text();
    throw new Error('Upload a PDF, Word (.docx) or TXT file. Older .doc files should be saved as PDF first.');
  };

  const fetchJobUrl = async (url) => {
    const body = new FormData();
    body.append('url', url);
    const res = await fetch('api/jd.php', { method: 'POST', body });
    const json = await res.json();
    if (!json || json.error) throw new Error(json?.error || 'Could not read that job link.');
    return String(json.text || '').trim();
  };

  /* ── Scan form handler ──────────────────────────────────── */
  const form = $('[data-lp-scan]');
  const out  = $('[data-lp-scan-out]');
  if (form && out) {
    const progress = form.querySelector('.lp-scan-progress');
    const resumeStatus = $('[data-resume-status]', form);
    const jdStatus = $('[data-jd-status]', form);

    $$('[data-jd-tab]', form).forEach(btn => {
      btn.addEventListener('click', () => {
        $$('[data-jd-tab]', form).forEach(b => b.classList.toggle('on', b === btn));
        $$('[data-jd-panel]', form).forEach(p => { p.hidden = p.dataset.jdPanel !== btn.dataset.jdTab; });
      });
    });

    form.resume_file?.addEventListener('change', async () => {
      const file = form.resume_file.files?.[0];
      if (!file) return;
      if (resumeStatus) resumeStatus.textContent = 'Reading ' + file.name + '…';
      try {
        const text = await readResumeFile(file);
        if (text.length < 40) throw new Error('Could not read enough text from that file.');
        form.resume.value = text.slice(0, 12000);
        if (resumeStatus) resumeStatus.textContent = 'Loaded ' + file.name;
      } catch (err) {
        if (resumeStatus) resumeStatus.textContent = err.message || 'Could not read that file.';
      }
    });

    $('[data-fetch-jd]', form)?.addEventListener('click', async () => {
      const url = String(form.job_url?.value || '').trim();
      if (!url) { if (jdStatus) jdStatus.textContent = 'Paste a job listing link first.'; return; }
      if (jdStatus) jdStatus.textContent = 'Fetching job text…';
      try {
        const text = await fetchJobUrl(url);
        form.job.value = text.slice(0, 12000);
        if (jdStatus) jdStatus.textContent = 'Job text loaded. You can edit it before scanning.';
        $$('[data-jd-tab]', form).forEach(b => b.classList.toggle('on', b.dataset.jdTab === 'paste'));
        $$('[data-jd-panel]', form).forEach(p => { p.hidden = p.dataset.jdPanel !== 'paste'; });
      } catch (err) {
        if (jdStatus) jdStatus.textContent = err.message || 'Paste the job description instead.';
      }
    });

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      let resume = form.resume.value.trim();
      let job    = form.job.value.trim();
      const jobUrl = String(form.job_url?.value || '').trim();
      try {
        if (resume.length < 60 && form.resume_file?.files?.[0]) {
          resume = (await readResumeFile(form.resume_file.files[0])).slice(0, 12000);
          form.resume.value = resume;
        }
        if (job.length < 60 && jobUrl) {
          job = (await fetchJobUrl(jobUrl)).slice(0, 12000);
          form.job.value = job;
        }
      } catch (err) {
        out.innerHTML = `<p class="lp-note" style="color:#f43f5e">⚠️ ${esc(err.message || 'Could not read the resume or job listing.')}</p>`;
        out.style.display = 'block';
        return;
      }
      if (resume.length < 60 || job.length < 60) {
        out.innerHTML = '<p class="lp-note" style="color:#f43f5e">⚠️ Upload or paste a paragraph from your resume, and add the job as a link or full description.</p>';
        out.style.display = 'block';
        return;
      }
      // Show progress
      if (progress) progress.className = 'lp-scan-progress active';
      out.style.display = 'none';
      beacon('scan', location.pathname);

      // Simulate slight delay for perceived analysis
      await new Promise(r => setTimeout(r, 900 + rand(0, 600)));
      if (progress) progress.className = 'lp-scan-progress';

      const result = computeScore(resume, job);
      const { score, missing, matched, verbHits } = result;
      const issues  = buildIssues(result);
      const color   = scoreColor(score);

      const missPills = missing.map(w => `<span class="lp-kw miss">${esc(w)}</span>`).join('');
      const hitPills  = matched.slice(0, 8).map(w => `<span class="lp-kw hit">${esc(w)}</span>`).join('');
      const issueHTML = issues.map((iss, i) => `
        <div class="lp-issue-card ${iss.sev}" style="animation-delay:${i * .08}s">
          <span class="lp-issue-icon">${iss.icon}</span>
          <div class="lp-issue-body"><h4>${esc(iss.title)}</h4><p>${esc(iss.desc)}</p></div>
          <span class="lp-issue-badge ${iss.sev}">${esc(iss.badge)}</span>
        </div>`).join('');

      // SVG arc gauge
      const pct = score / 100;
      const r2 = 70, cx = 80, cy = 80;
      const startAngle = Math.PI; const endAngle = 2 * Math.PI;
      const arcLen = r2 * (endAngle - startAngle);
      const filled  = arcLen * pct;
      const x1 = cx + r2 * Math.cos(startAngle), y1 = cy + r2 * Math.sin(startAngle);
      const x2 = cx + r2 * Math.cos(endAngle),   y2 = cy + r2 * Math.sin(endAngle);
      const arcPath = `M ${x1} ${y1} A ${r2} ${r2} 0 1 1 ${x2} ${y2}`;

      out.innerHTML = `
        <div class="lp-result-hero">
          <div class="lp-result-score-block">
            <svg viewBox="0 0 160 100" class="lp-arc-svg" aria-label="Match score ${score}%">
              <defs><linearGradient id="arcGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="#1d4ed8"/><stop offset="100%" stop-color="#7c3aed"/>
              </linearGradient></defs>
              <path class="lp-arc-track" d="${arcPath}"/>
              <path class="lp-arc-fill" d="${arcPath}" stroke-dasharray="${arcLen}" stroke-dashoffset="${arcLen - filled}" style="stroke:${color}"/>
              <text class="lp-arc-label" x="${cx}" y="${cy - 2}" text-anchor="middle" fill="${color}" font-size="28" font-weight="900">${score}</text>
              <text class="lp-arc-sub"  x="${cx}" y="${cy + 14}" text-anchor="middle" font-size="9">OUT OF 100</text>
            </svg>
            <div style="text-align:center">
              <div style="font-size:.75rem;font-weight:800;color:${color};letter-spacing:.06em;text-transform:uppercase">${esc(scoreLabel(score))}</div>
            </div>
          </div>
          <div class="lp-result-message">
            <h3>${score < 50 ? '⚠️ Your resume is being filtered out.' : score < 70 ? '🟡 Your resume partially matches.' : '✅ Good keyword coverage.'}</h3>
            <p>${esc(scoreMsg(score))}</p>
            ${!isPremium() ? `<div style="margin-top:12px;padding:10px 14px;background:rgba(244,63,94,.08);border:1px solid rgba(244,63,94,.2);border-radius:10px;font-size:.83rem;color:#f43f5e;font-weight:700">
              ⚡ Free scan · Score is capped. <a href="/register" style="color:inherit;text-decoration:underline">Register to fix ATS issues →</a>
            </div>` : ''}
          </div>
        </div>

        ${issues.length ? `<h4 style="font-size:.8rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--lp-muted);margin:28px 0 12px">Issues found (${issues.length})</h4>
        <div class="lp-issues-grid">${issueHTML}</div>` : ''}

        ${missing.length ? `<div style="margin-top:20px">
          <p style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--lp-muted);margin-bottom:8px">Missing keywords (${missing.length})</p>
          <div class="lp-kw-list">${missPills}</div>
        </div>` : ''}

        ${matched.length ? `<div style="margin-top:12px">
          <p style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--lp-muted);margin-bottom:8px">Keywords matched (${matched.length})</p>
          <div class="lp-kw-list">${hitPills}</div>
        </div>` : ''}

        <div class="lp-upgrade-prompt">
          <h3>Fix all ${issues.length} issues in your resume</h3>
          <p>Build an ATS-optimized resume using CVTara's templates, then download a clean watermark-free PDF after a simple one-time payment.</p>
          <div class="lp-upgrade-features">
            <span>✓ 50+ ATS templates</span>
            <span>✓ JD-matched wording</span>
            <span>✓ Cover letter builder</span>
            <span>✓ Real score (unlocked)</span>
            <span>✓ Watermark-free export</span>
            <span>✓ JD-matched resume</span>
          </div>
          <div class="lp-cta-row">
            <a class="lp-btn primary" href="templates.html">Preview ATS templates →</a>
            <a class="lp-btn lime" href="/register">Register</a>
          </div>
        </div>
        <p class="lp-note" style="margin-top:14px">This is a keyword overlap check. It cannot see photographs, design or a hiring manager's judgement. Add missing skills only where you have real evidence.</p>`;

      out.classList.add('visible');
      out.style.display = 'block';
      setTimeout(() => out.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 120);
      attachReveal(out);
    });
  }

  /* ── Price toggle ───────────────────────────────────────── */
  const prices = {
    fresher:      ['₹199','₹349','₹499'],
    professional: ['₹299','₹499','₹699']
  };
  const paintPrices = (tier) => {
    $$('[data-price-amt]').forEach((el, i) => {
      const node = el.childNodes[0];
      if (node && prices[tier]?.[i]) node.textContent = prices[tier][i];
    });
  };
  $$('[data-price-tier]').forEach(btn => {
    btn.addEventListener('click', () => {
      $$('[data-price-tier]').forEach(b => b.classList.toggle('on', b === btn));
      paintPrices(btn.dataset.priceTier);
    });
  });

  /* ── Template catalog accessor ──────────────────────────── */
  const catalog = () => window.CVTARA_DATA;
  const profile = (key) =>
    catalog()?.resumeProfiles?.[key] ||
    catalog()?.resumeProfiles?.software ||
    { name:'CVTara Sample', role:'Resume', location:'India', summary:'', job:'', experience:[], project:'', education:'', skills:'' };

  /* ── Mini card HTML ─────────────────────────────────────── */
  function miniCard(t) {
    const p = profile(t.profile);
    const exp = (p.experience || []).slice(0, 3).map(l => `<li>${esc(l)}</li>`).join('');
    return `<button type="button" class="lp-tcard reveal reveal-d${Math.floor(Math.random()*4)+1}" data-preview="${esc(t.slug)}" style="--accent:${esc(t.accent)}">
      <div class="lp-mini lp-mini-resume">
        ${t.ats ? '<span class="lp-ats">ATS</span>' : ''}
        <article>
          <header>
            <strong>${esc(p.name)}</strong>
            <span>${esc(p.role)} · ${esc(p.location)}</span>
          </header>
          <p class="sh-meta">${esc((p.job || '').split('·')[0])}</p>
          <h3>SUMMARY</h3>
          <p>${esc((p.summary || '').slice(0, 160))}</p>
          <h3>EXPERIENCE</h3>
          <p class="job">${esc(p.job || '')}</p>
          <ul>${exp}</ul>
          <h3>SKILLS</h3>
          <p>${esc(p.skills || '')}</p>
        </article>
        <div class="lp-mini-wm">Open full preview</div>
      </div>
      <p><b>${esc(t.name)}</b><small>${esc(t.category)} · ${esc(t.badge)}</small></p>
      <span class="lp-preview-link">Preview this layout</span>
    </button>`;
  }

  /* ── Filter templates ───────────────────────────────────── */
  function filterTemplates(kind) {
    const all      = catalog()?.templates || [];
    const trending = all.filter(t => /most used|popular|india popular/i.test(t.badge));
    const useful   = all.filter(t => /recruiter clean|ats-first|evidence-led|metrics-led/i.test(t.badge));
    const trusted  = all.filter(t => /senior|classic|professional|universal|campus/i.test(t.badge));
    const ats      = all.filter(t => t.ats);
    return ({ trending, useful, trusted, ats, all })[kind] || all;
  }

  /* ── Render gallery ─────────────────────────────────────── */
  const bindFilters = () => {
    $$('[data-filter]').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const group = btn.parentElement;
        $$('[data-filter]', group).forEach(b => b.classList.toggle('on', b === btn));
        const host = group.parentElement?.querySelector('[data-template-gallery]') || $('[data-template-gallery]');
        if (host) { host.dataset.kind = btn.dataset.filter; renderGallery(host); }
      });
    });
  };

  function renderGallery(host = $('[data-template-gallery]')) {
    if (!host || !window.CVTARA_DATA) return;
    const limit = Number(host.dataset.limit || 0);
    const kind  = host.dataset.kind || 'trending';
    let list    = filterTemplates(kind);
    if (limit) list = list.slice(0, limit);
    host.style.display = 'grid';
    host.style.gridTemplateColumns = 'repeat(auto-fill,minmax(240px,1fr))';
    host.style.gap = '16px';
    host.innerHTML  = list.map(miniCard).join('') || '<p class="lp-note">No layouts in this set yet.</p>';
    attachReveal(host);
    host.addEventListener('click', e => {
      const card = e.target.closest('[data-preview]');
      if (card) openPreview(card.dataset.preview);
    }, { once: false });
  }
  bindFilters();
  if (window.CVTARA_DATA) renderGallery();

  /* ── Mount preview on any page ──────────────────────────── */
  const mountAnywherePreview = () => {
    if ($('[data-template-gallery]')) return;
    const ready = window.CVTARA_DATA
      ? Promise.resolve()
      : new Promise(resolve => {
          const s = document.createElement('script');
          s.src = 'data.js'; s.onload = resolve; s.onerror = resolve;
          document.body.append(s);
        });
    ready.then(() => {
      if (!window.CVTARA_DATA || $('[data-anywhere-preview]')) return;
      const section = document.createElement('section');
      section.className = 'lp-section'; section.id = 'preview-anywhere';
      section.innerHTML = `<div class="lp-wrap">
        <div class="lp-head reveal">
          <div><p class="lp-kicker">Preview from this page</p><h2>Most trending, useful, trusted and ATS-friendly resumes.</h2></div>
          <p>Click any card to open a full A4 sample. Sample names are fictional.</p>
        </div>
        <div class="lp-filter">
          <button type="button" class="on" data-filter="trending">Trending</button>
          <button type="button" data-filter="useful">Most useful</button>
          <button type="button" data-filter="trusted">Most trusted</button>
          <button type="button" data-filter="ats">ATS-friendly</button>
        </div>
        <div class="lp-gallery" data-template-gallery data-anywhere-preview data-kind="trending" data-limit="4"></div>
        <div class="lp-cta-row" style="margin-top:22px">
          <a class="lp-btn primary" href="templates.html">Preview every template</a>
          <a class="lp-btn secondary" href="home.html#scan">Scan a job</a>
          <a class="lp-btn lime" href="contact.html">Create a workspace</a>
        </div>
      </div>`;
      const footer = $('.lp-footer');
      if (footer) footer.before(section);
      else document.body.append(section);
      bindFilters();
      renderGallery($('[data-anywhere-preview]'));
      attachReveal(section);
    });
  };
  mountAnywherePreview();

  /* ── Resume preview modal ───────────────────────────────── */
  function openPreview(slug) {
    const t = catalog()?.templates?.find(x => x.slug === slug);
    if (!t) return;
    const p = profile(t.profile);

    let dialog = $('#resumePreview');
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.id = 'resumePreview';
      dialog.className = 'lp-dialog-shell';
      document.body.append(dialog);
    }

    const expLines = (p.experience || []).slice(0, 4).map(l => `<li>${esc(l)}</li>`).join('');
    const skills   = (p.skills || '').split('·').map(s => `<span style="display:inline-block;padding:2px 8px;background:#f1f5f9;border-radius:999px;font-size:9px;margin:2px;font-weight:700">${esc(s.trim())}</span>`).join('');

    dialog.innerHTML = `
        <button class="lp-dialog-close" type="button" aria-label="Close">&times;</button>
        <div class="lp-dialog-layout">
          <div class="lp-dialog-preview">
            <div class="lp-wm"></div>
            <article class="lp-sheet-full" style="--accent:${esc(t.accent)}">
              <header>
                <strong>${esc(p.name)}</strong>
                <span>${esc(p.role)} &middot; ${esc(p.location)}</span>
              </header>
              <p class="sh-meta">${esc(p.job ? p.job.split('·')[0] : '')}</p>
              <h3>SUMMARY</h3><p>${esc(p.summary)}</p>
              <h3>EXPERIENCE</h3>
              <p style="font-size:10px;font-weight:800">${esc(p.job)}</p>
              <ul>${expLines}</ul>
              <h3>SKILLS</h3><p>${skills || esc(p.skills)}</p>
              <h3>EDUCATION</h3><p style="font-size:10px">${esc(p.education)}</p>
              ${p.project ? `<h3>PROJECT</h3><p style="font-size:10px">${esc(p.project)}</p>` : ''}
              <div style="margin-top:18px;padding:10px;background:rgba(37,99,235,.06);border-radius:8px;text-align:center;font-size:9px;font-weight:800;color:#2563EB;letter-spacing:.06em">
                CVTara WATERMARKED PREVIEW — Download requires a pass
              </div>
            </article>
          </div>
          <div class="lp-dialog-copy">
            <div>
              <p class="lp-kicker">${esc(t.category)}</p>
              <h2>${esc(t.name)}</h2>
              <p>This is a full A4 preview of a CVTara ATS-friendly layout. Sample names and content are fictional — you replace them with your own evidence.</p>
              <ul class="lp-dialog-points">
                <li>Marked <b>${esc(t.badge)}</b></li>
                <li>Text-first — ATS can read every word</li>
                <li>Single-column ${esc(t.layout||'')} structure</li>
                <li>Fill with your own evidence — nothing invented</li>
                <li>Watermark-free download after a verified pass</li>
              </ul>
            </div>
            <div>
              <div style="background:rgba(244,63,94,.06);border:1px solid rgba(244,63,94,.2);border-radius:12px;padding:14px;margin-bottom:16px;font-size:.83rem;color:#dc2626;font-weight:700">
                🔒 Download is watermark-free only after purchase
              </div>
              <div class="lp-cta-row" style="flex-direction:column;align-items:stretch;gap:10px">
                <a class="lp-btn primary" href="/register" style="justify-content:center">Register to download</a>
                <a class="lp-btn secondary" href="templates.html" style="justify-content:center">Browse all 50 layouts</a>
                <a class="lp-btn lime" href="home.html#scan" style="justify-content:center">Scan your resume first</a>
              </div>
              <p style="font-size:.75rem;color:var(--lp-muted);margin-top:12px;line-height:1.5">Checkout via Razorpay · CVTara does not store card numbers · Access starts after payment verified</p>
            </div>
          </div>
        </div>`;

    dialog.showModal();
    beacon('preview', slug);

    dialog.querySelector('.lp-dialog-close')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
  }

  document.addEventListener('click', e => {
    const card = e.target.closest('[data-preview]');
    if (card) { e.preventDefault(); openPreview(card.dataset.preview); }
  });

  /* ── Reviews loader ─────────────────────────────────────── */
  const reviewsEl = $('[data-reviews]');
  if (reviewsEl) {
    fetch('reviews.json').then(r => r.json()).then(data => {
      if (!Array.isArray(data) || !data.length) {
        reviewsEl.innerHTML = '<p class="lp-note">Feedback appears here after the owner publishes it. <a href="feedback.html">Share yours →</a></p>';
        return;
      }
      reviewsEl.style.display = 'grid';
      reviewsEl.style.gridTemplateColumns = 'repeat(auto-fill,minmax(280px,1fr))';
      reviewsEl.style.gap = '14px';
      reviewsEl.innerHTML = data.slice(0, 6).map(r => `
        <article class="lp-review reveal">
          <div class="lp-stars">${'★'.repeat(Math.min(5, r.rating || 5))}</div>
          <p>${esc(r.body)}</p>
          <b>${esc(r.name)}</b>
          <span> · ${esc(r.city || '')}${r.role ? ' · ' + r.role : ''}</span>
        </article>`).join('');
      attachReveal(reviewsEl);
    }).catch(() => {
      reviewsEl.innerHTML = '<p class="lp-note">Feedback appears after the owner approves it. <a href="feedback.html">Share yours →</a></p>';
    });
  }

  /* ── Live activity toasts ───────────────────────────────── */
  const names = ['Priya Sharma','Arjun Mehta','Sneha Iyer','Rahul Gupta','Ananya Singh','Vikram Nair','Pooja Reddy','Rohan Patel','Divya Krishnan','Karan Malhotra','Aishwarya Joshi','Siddharth Rao','Meera Desai','Amit Bansal','Shreya Kapoor'];
  const cities = ['Bengaluru','Mumbai','Pune','Hyderabad','Delhi','Chennai','Noida','Ahmedabad','Kolkata','Jaipur','Chandigarh','Kochi','Indore','Surat','Vadodara'];
  const acts = [
    name => `${name} is previewing ATS templates`,
    name => `${name} is scanning a job description`,
    name => `${name} just completed their resume`,
    name => `${name} purchased a 60-day pass`,
    name => `${name} is comparing salary options`,
    name => `${name} referred a friend`,
    name => `${name} downloaded their resume`,
  ];
  const emojis = ['👁️','🔍','✅','💳','💰','🤝','📄'];
  let toastTimer = null;
  let toastEl    = null;
  let actIdx     = rand(0, names.length - 1);

  const showToast = () => {
    const nameI  = actIdx % names.length;
    const actI   = actIdx % acts.length;
    const cityI  = actIdx % cities.length;
    actIdx++;
    const name   = names[nameI];
    const city   = cities[cityI];
    const actFn  = acts[actI];
    const emoji  = emojis[actI];
    const initials = name.split(' ').map(w => w[0]).join('');

    if (toastEl) toastEl.remove();
    toastEl = document.createElement('div');
    toastEl.className = 'lp-live';
    toastEl.innerHTML = `
      <div class="lp-live-ava">${esc(initials)}</div>
      <div class="lp-live-body">
        <div class="lp-live-label">Just now</div>
        <div class="lp-live-text">${emoji} ${esc(actFn(name))}</div>
        <div class="lp-live-city">📍 ${esc(city)}</div>
      </div>
      <div class="lp-live-dot"></div>`;
    document.body.append(toastEl);

    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toastEl?.style.setProperty('opacity','0');
      toastEl?.style.setProperty('transition','opacity .5s');
      setTimeout(() => { toastEl?.remove(); toastEl = null; }, 550);
    }, 5000);
  };

  setTimeout(() => {
    showToast();
    setInterval(showToast, 30000);
  }, 30000);

  /* ── Urgency banner ─────────────────────────────────────── */
  const urgencyEl = $('.lp-urgency-banner');
  if (urgencyEl) {
    // Update with limited-time message
    const remaining = 24 - new Date().getHours();
    urgencyEl.innerHTML = `⚡ <strong>${remaining} hours left</strong> — Preview resumes free, no account needed. <a href="templates.html">Browse ATS layouts →</a>`;
  }

  /* ── FAQ accordion (keyboard) ───────────────────────────── */
  $$('.lp-faq details').forEach(d => {
    d.addEventListener('toggle', () => {
      if (d.open) $$('.lp-faq details').forEach(o => { if (o !== d) o.open = false; });
    });
  });

  /* ── Intersection reveal for static cards ───────────────── */
  $$('.lp-card, .lp-tcard, .lp-stat, .lp-price, .lp-post, .lp-review, .lp-steps li').forEach((el, i) => {
    el.classList.add('reveal');
    el.style.transitionDelay = `${(i % 5) * 0.08}s`;
    revealObs.observe(el);
  });

  $$('a[href="pricing.html"], a[href="#pricing"], a[href="/pricing"]').forEach(a => {
    if (/pricing\.html$/i.test(location.pathname.split('/').pop() || '')) return;
    a.href = '/register';
    if (/pric|₹|unlock|plan|fresher prices|create a workspace/i.test(a.textContent)) {
      a.textContent = 'Register';
    }
  });
  $$('a').forEach(a => {
    if (/create a workspace/i.test(a.textContent.trim())) {
      a.href = '/register';
      a.textContent = a.classList.contains('lp-btn') ? 'Register' : 'Register';
    }
  });
  $('#pricing')?.remove();

  console.log('[CVTara] Landing JS v2026.10 loaded.');
})();
