/* "Fix my resume for this job": the Resume Builder opened from an ATS Match report (?draft=…&fix=<scan id>).
   Shows the job's missing keywords and gaps beside the resume, adds the keywords the person ticks to Skills in one click,
   can ask the Tara team to tailor the summary (when the owner has switched writing help on), then saves and re-checks against the same job.
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
  let scan = null, box = null, ai = null, last = null;

  async function ready() {
    for (let i = 0; i < 60; i++) {
      if (window.CVTaraStudio?.getDraft?.()?._profileId === draftId) return true;
      await wait(250);
    }
    return false;
  }

  const draft = () => window.CVTaraStudio.getDraft();
  const has = (skills, k) => new RegExp('(^|[^\\p{L}\\p{N}])' + k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '($|[^\\p{L}\\p{N}])', 'iu').test(skills);

  function render() {
    const rep = scan.report, kw = rep.keywords || { missing: [] }, d = draft(), text = [d.summary, d.skills, ...(d.experience || []).map(x => x.bullets)].join('\n');
    const missing = (kw.missing || []).filter(k => !has(text, k));
    const gaps = (rep.gaps || []).filter(g => !/^Keywords ·|^Resume format ·/.test(g)).slice(0, 6);
    let note = ''; try { note = sessionStorage.getItem('cvtara-fix-note') || ''; sessionStorage.removeItem('cvtara-fix-note'); } catch { /* ignore */ }
    box.innerHTML = `<div class="jf-head"><div><span class="eyebrow">FIX FOR THIS JOB</span><h3>${E(scan.title || 'This job')}${scan.company ? ' · ' + E(scan.company) : ''}</h3><p class="ats-muted">Match for this job: <b class="${tone(last ?? scan.score)}">${last ?? scan.score}%</b>${last != null && last !== scan.score ? ` (was ${scan.score}%)` : ''}. Add only what is true for you — every keyword below is one this job uses that your resume does not show yet.${note ? ' ' + E(note) : ''}</p></div><button class="button secondary small" type="button" data-jf-close aria-label="Hide the job panel">Hide</button></div>
<div class="jf-grid"><section><h4>1 · Keywords you really have</h4>${missing.length ? `<div class="jf-kw">${missing.map(k => `<label><input type="checkbox" value="${E(k)}"> ${E(k)}</label>`).join('')}</div><button class="button primary small" type="button" data-jf-add>Add ticked keywords to my Skills</button>` : '<p class="ats-muted">Your resume already shows every keyword we found in this job.</p>'}
<p class="ats-muted">Then use the same words in a bullet under the job where you did that work — recruiters and tracking systems both look for them in context.</p></section>
<section><h4>2 · What this job asks for</h4>${gaps.length ? `<ol class="jf-gaps">${gaps.map(g => `<li>${E(g)}</li>`).join('')}</ol>` : '<p class="ats-muted">No open requirements were found.</p>'}
${ai?.enabled ? `<div class="jf-ai"><label class="jf-consent"><input type="checkbox" data-jf-consent> Share my summary and this job with the Tara team for a suggestion (see our privacy policy).</label><button class="button secondary small" type="button" data-jf-tara ${ai.left ? '' : 'disabled'}>Ask the Tara team to tailor my summary (${ai.left} left today)</button><div data-jf-out></div></div>` : ''}</section>
<section><h4>3 · Save and check again</h4><p class="ats-muted">Saves this resume to your profile and checks it against the same job. Uses 1 ATS scan.</p><button class="button primary small" type="button" data-jf-recheck>Save and re-check</button></section></div>
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
    if (e.target.closest('[data-jf-tara]')) {
      const d = draft(), text = String(d.summary || '').trim();
      if (!box.querySelector('[data-jf-consent]').checked) { say('Tick the box to share your summary with the Tara team.', true); return; }
      if (text.length < 20) { say('Write a short summary first (at least a sentence), then ask the Tara team to tailor it.', true); return; }
      const b = e.target.closest('[data-jf-tara]'); b.disabled = true; say('The Tara team is tailoring your summary…');
      try {
        const r = await api('/api/ai/suggest', { method: 'POST', body: { task: 'tailor', text: text.slice(0, 12000), context: String(scan.jobText || '').slice(0, 8000), consent: true } });
        ai.left = r.left;
        const [answer, checks = ''] = String(r.suggestion).split(/\n?Check before using:/i);
        box.querySelector('[data-jf-out]').innerHTML = `<label class="ats-field"><span>Suggested summary — edit before using</span><textarea rows="6" data-jf-suggest>${E(answer.trim())}</textarea></label>${checks.trim() ? `<p class="ats-muted"><b>Check before using:</b> ${E(checks.trim())}</p>` : ''}<button class="button primary small" type="button" data-jf-use>Use this as my summary</button>`;
        say('');
      } catch (err) { say(err.message, true); } finally { b.disabled = !ai.left; }
      return;
    }
    if (e.target.closest('[data-jf-use]')) {
      const v = box.querySelector('[data-jf-suggest]').value.trim();
      if (v) { window.CVTaraStudio.update(d => { d.summary = v; }); say('Summary updated. Save and re-check to see the new match.'); }
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
