/* "Fix my resume for this job": the Resume Builder opened from an ATS Match report (?draft=…&fix=<scan id>).
   Shows the job's missing keywords and gaps beside the resume, adds the keywords the person ticks to Skills in one click,
   can have the CVTara team tailor the whole resume for the job (when the owner has switched writing help on; shown for review first),
   then saves and re-checks against the same job.
   Nothing is added that the person did not choose. */
(() => {
  'use strict';
  const q = new URLSearchParams(location.search), scanId = q.get('fix'), draftId = q.get('draft') || q.get('draftId');
  if (document.body.dataset.page !== 'workspace' || !scanId || !draftId || !window.CVTaraAPI) return;
  const view = document.getElementById('view-builder');
  if (!view) return;
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const wait = ms => new Promise(r => setTimeout(r, ms));
  const tone = n => n >= 75 ? 'good' : n >= 50 ? 'mid' : 'low';
  let scan = null, box = null, ai = null, last = null, proposal = null;

  async function ready() {
    for (let i = 0; i < 60; i++) {
      if (window.CVTaraStudio?.getDraft?.()?._profileId === draftId) return true;
      await wait(250);
    }
    return false;
  }

  const draft = () => window.CVTaraStudio.getDraft();
  const has = (skills, k) => new RegExp('(^|[^\\p{L}\\p{N}])' + k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '($|[^\\p{L}\\p{N}])', 'iu').test(skills);

  // 3.64.0: a compact panel. Score ring, keyword chips to tick, the job's requirements as short pills (details on click)
  // and one row of actions, instead of three columns of text.
  const gapParts = g => {
    const m = String(g).match(/^(?:[^·]*·\s*)?(.*?)\s+—\s+([^.]*)\.?\s*(.*)$/);
    return m ? { name: m[1], state: m[2], tip: m[3] } : { name: String(g), state: '', tip: '' };
  };
  const ring = n => `<div class="jf-ring ${tone(n)}" style="--p:${Math.max(0, Math.min(100, n))}" role="img" aria-label="Match for this job ${n}%"><b>${n}%</b><small>match</small></div>`;

  function render() {
    const rep = scan.report, kw = rep.keywords || { missing: [] }, d = draft(), text = [d.summary, d.skills, ...(d.experience || []).map(x => x.bullets)].join('\n');
    const missing = (kw.missing || []).filter(k => !has(text, k));
    const gaps = (rep.gaps || []).filter(g => !/^Keywords ·|^Resume format ·/.test(g)).slice(0, 6).map(gapParts);
    const score = last ?? scan.score;
    let note = ''; try { note = sessionStorage.getItem('cvtara-fix-note') || ''; sessionStorage.removeItem('cvtara-fix-note'); } catch { /* ignore */ }
    box.innerHTML = `<div class="jf-head">${ring(score)}<div class="jf-title"><span class="eyebrow">FIX FOR THIS JOB</span><h3>${E(scan.title || 'This job')}${scan.company ? ' · ' + E(scan.company) : ''}</h3>${last != null && last !== scan.score ? `<p class="jf-up">Was ${scan.score}%, now ${last}%</p>` : ''}${note ? `<p class="ats-muted">${E(note)}</p>` : ''}</div><button class="button secondary small" type="button" data-jf-close aria-label="Hide the job panel">Hide</button></div>
<div class="jf-steps">
<section><h4><span class="jf-n">1</span>Tick keywords you really have</h4>${missing.length ? `<div class="jf-kw">${missing.map(k => `<label><input type="checkbox" value="${E(k)}"> ${E(k)}</label>`).join('')}</div><button class="button secondary small" type="button" data-jf-add>Add to my Skills</button>` : '<p class="jf-ok">✓ All keywords found</p>'}</section>
<section><h4><span class="jf-n">2</span>The job asks for</h4>${gaps.length ? `<ul class="jf-req">${gaps.map(g => `<li title="${E(g.tip)}"><span class="jf-dot ${/not found/i.test(g.state) ? 'miss' : 'part'}" aria-hidden="true"></span>${E(g.name)}<small>${/not found/i.test(g.state) ? 'missing' : 'add an example'}</small></li>`).join('')}</ul><details class="jf-why"><summary>What to do</summary><p>Add one true example for each: what you did and the result. Missing items you have not done are role gaps, so leave them out.</p></details>` : '<p class="jf-ok">✓ Nothing open</p>'}</section>
</div>
<div class="jf-actions"><button class="button primary small" type="button" data-jf-recheck>Save and re-check <small>(1 scan)</small></button>${ai?.enabled ? `<button class="button secondary small" type="button" data-jf-tailor ${ai.left ? '' : 'disabled'}>Tailor whole resume for me <small>(${ai.left} left today)</small></button><label class="jf-consent"><input type="checkbox" data-jf-consent> Share this resume and job with the CVTara team (<a href="/privacy.html" target="_blank" rel="noopener">privacy</a>). You review every change; nothing you have not done is added.</label>` : ''}</div>
${ai?.enabled ? '<div data-jf-out></div>' : ''}
<p role="status" class="ats-status" data-jf-status></p>`;
  }

  const say = (m, bad) => { const s = box.querySelector('[data-jf-status]'); s.textContent = m || ''; s.classList.toggle('bad', !!bad); };

  async function start() {
    try { scan = (await api('/api/ats/scans/' + encodeURIComponent(scanId))).scan; } catch { return; }
    box = document.createElement('section');
    box.className = 'panel panel-pad job-fix'; box.setAttribute('aria-label', 'Fix this resume for the job');
    view.prepend(box);
    box.innerHTML = '<p class="ats-muted">Opening this job beside your resume…</p>';
    try { ai = (await api('/api/ai/status')).ai; } catch { ai = null; }
    if (!await ready()) { box.innerHTML = '<p class="ats-muted">Your resume did not open. Refresh the page to see this job beside it.</p>'; return; }
    render();
    box.addEventListener('click', onClick);
  }

  async function onClick(e) {
    if (e.target.closest('[data-jf-close]')) { box.hidden = true; return; }
    if (e.target.closest('[data-jf-add]')) {
      const picked = [...box.querySelectorAll('.jf-kw input:checked')].map(i => i.value);
      if (!picked.length) { say('Tick the keywords that are true for you first.', true); return; }
      window.CVTaraStudio.update(d => { const now = String(d.skills || '').trim().replace(/[,;\s]+$/, ''), add = picked.filter(k => !has(now, k)).join(', '); d.skills = now ? now + ', ' + add : add; });
      render(); say(`Added ${picked.length} keyword${picked.length > 1 ? 's' : ''} to Skills. Save and re-check to see the new match.`);
      return;
    }
    if (e.target.closest('[data-jf-tailor]')) {
      if (!box.querySelector('[data-jf-consent]').checked) { say('Tick the box to share this resume with the CVTara team.', true); return; }
      const b = e.target.closest('[data-jf-tailor]'); b.disabled = true;
      try {
        say('Saving your resume first…');
        if (!await window.CVTaraStudio.saveToProfile()) { say('Your resume could not be saved. Check the message next to Save to profile, then try again.', true); return; }
        say('The CVTara team is tailoring your resume for this job — this takes up to a minute…');
        const t = (await api('/api/resumes/' + encodeURIComponent(draftId) + '/tailor', { method: 'POST', body: { scanId, consent: true } })).tailored;
        ai.left = t.left; proposal = t;
        const d = draft(), row = (label, before, after) => after && after !== before ? `<details class="jf-change" open><summary>${E(label)}</summary><div class="jf-ba"><div><small>Now</small><p>${E(before || '—').replace(/\n/g, '<br>')}</p></div><div><small>Tailored</small><p>${E(after).replace(/\n/g, '<br>')}</p></div></div></details>` : '';
        box.querySelector('[data-jf-out]').innerHTML = `<div class="jf-proposal"><h4>Tailored for ${E(scan.title || 'this job')} — review before applying</h4>${row('Profile summary', d.summary, t.summary)}${row('Skills', d.skills, t.skills)}${t.experience.map(x => row([d.experience?.[x.i]?.role, d.experience?.[x.i]?.company].filter(Boolean).join(' · ') || 'Experience', d.experience?.[x.i]?.bullets, x.bullets)).join('')}
${t.notShown.length ? `<p class="ats-muted"><b>Not shown in your resume, so not added:</b> ${E(t.notShown.join(', '))}. Add them yourself only if they are true.</p>` : ''}${t.check.length ? `<p class="ats-muted"><b>Check before using:</b> ${t.check.map(E).join(' · ')}</p>` : ''}<div class="jf-actions"><button class="button primary small" type="button" data-jf-apply>Apply all changes</button><button class="button secondary small" type="button" data-jf-discard>Discard</button></div></div>`;
        say('');
      } catch (err) { say(err.message, true); } finally { b.disabled = !ai.left; }
      return;
    }
    if (e.target.closest('[data-jf-discard]')) { proposal = null; box.querySelector('[data-jf-out]').innerHTML = ''; say('Nothing was changed.'); return; }
    if (e.target.closest('[data-jf-apply]') && proposal) {
      const t = proposal; proposal = null;
      window.CVTaraStudio.update(d => {
        if (t.summary) d.summary = t.summary;
        if (t.skills) d.skills = t.skills;
        t.experience.forEach(x => { if (d.experience?.[x.i]) d.experience[x.i].bullets = x.bullets; });
      });
      render(); say('Applied. Read it through in the editor (Undo works as usual), then Save and re-check.');
      return;
    }
    if (e.target.closest('[data-jf-recheck]')) {
      const b = e.target.closest('[data-jf-recheck]'); b.disabled = true; say('Saving your resume…');
      try {
        if (!await window.CVTaraStudio.saveToProfile()) { say('Your resume could not be saved. Check the message next to Save to profile, then try again.', true); return; }
        say('Checking against the same job…');
        const s = await api('/api/ats/scan', { method: 'POST', body: { resumeId: draftId, source: 'text', text: String(scan.jobText || ''), title: scan.title || '', company: scan.company || '' } });
        last = s.report.score; scan.report = s.report;
        render(); say(`Checked again — 1 ATS scan used. Match for this job is now ${last}%.`);
      } catch (err) { say(err.message, true); } finally { const x = box.querySelector('[data-jf-recheck]'); if (x) x.disabled = false; }
    }
  }

  start();
})();
