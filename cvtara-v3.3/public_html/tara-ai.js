/* Tara AI in the workspace: pick a task, send only the text the customer chooses, review the suggestion, copy what helps.
   Shown only when the owner has switched AI on. Nothing in the saved resume or letter changes by itself. */
(() => {
  'use strict';
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const E = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const NEEDS_JOB = ['tailor', 'cover_letter', 'review_requirements', 'interview'];
  let dialog, status = null;

  function fromResume() {
    const d = window.CVTaraStudio?.getDraft?.(); if (!d) return '';
    const lines = [];
    if (d.summary) lines.push(d.summary);
    (d.experience || []).forEach(x => { if (x.role || x.company) lines.push([x.role, x.company].filter(Boolean).join(' at ')); if (x.bullets) lines.push(x.bullets); });
    if (d.skills) lines.push('Skills: ' + d.skills);
    return lines.join('\n').slice(0, 12000);
  }

  function render() {
    const tasks = Object.entries(status.tasks);
    dialog.innerHTML = `<h2 id="taraTitle">Tara AI <small class="tara-left">${status.left} left today</small></h2>
      <p class="tara-lead">Choose what you want help with. Tara works only from what you paste, so every name, date and number stays yours.</p>
      <form class="tara-form">
        <div class="tara-tasks" role="radiogroup" aria-label="What should Tara do?">${tasks.map(([k, label], i) => `<label class="tara-chip"><input type="radio" name="task" value="${k}" ${i ? '' : 'checked'}><span>${E(label)}</span></label>`).join('')}</div>
        <label>Your text <button type="button" class="tara-link" data-fill>Use my resume</button><textarea name="text" rows="7" minlength="20" maxlength="12000" required placeholder="Paste a summary, some bullets or your resume"></textarea></label>
        <label data-job hidden>Job description<textarea name="context" rows="4" maxlength="8000" placeholder="Paste the job description or its key requirements"></textarea></label>
        <label class="tara-consent"><input name="consent" type="checkbox" required> Send this text to Claude by Anthropic to get a suggestion. It is not used to train models.</label>
        <div class="tara-actions"><button class="button primary" ${status.left ? '' : 'disabled'}>Ask Tara</button><button type="button" class="button secondary" data-close>Close</button></div>
        <p role="status" aria-live="polite"></p>
        <div class="tara-result" hidden><h3>Suggestion <small>review before using</small></h3><textarea data-result rows="10" readonly></textarea><button type="button" class="button secondary small" data-copy>Copy</button></div>
      </form>`;
    const f = dialog.querySelector('form'), st = f.querySelector('[role=status]'), job = f.querySelector('[data-job]');
    const sync = () => { const t = f.task.value; job.hidden = !NEEDS_JOB.includes(t); job.querySelector('textarea').required = ['tailor', 'cover_letter', 'review_requirements'].includes(t); };
    f.addEventListener('change', sync); sync();
    f.querySelector('[data-fill]').onclick = () => { const t = fromResume(); if (t) f.text.value = t; else st.textContent = 'Open the resume builder first, or paste your text.'; };
    dialog.querySelector('[data-close]').onclick = () => dialog.close();
    f.querySelector('[data-copy]').onclick = async () => { try { await navigator.clipboard.writeText(f.querySelector('[data-result]').value); st.textContent = 'Copied. Paste it where you want it, then check the facts.'; } catch { f.querySelector('[data-result]').select(); } };
    f.onsubmit = async e => {
      e.preventDefault(); const b = f.querySelector('.button.primary'); b.disabled = true; st.textContent = 'Tara is writing…';
      try {
        const r = await api('/api/ai/suggest', { method: 'POST', body: { task: f.task.value, text: f.text.value, context: f.context.value, consent: true } });
        f.querySelector('[data-result]').value = r.suggestion; f.querySelector('.tara-result').hidden = false;
        status.left = r.left; dialog.querySelector('.tara-left').textContent = r.left + ' left today';
        st.textContent = 'Ready. Your saved resume has not changed.';
        b.disabled = !r.left;
      } catch (err) { st.textContent = err.message; b.disabled = false; }
    };
  }

  async function open() {
    if (!dialog) { dialog = document.createElement('dialog'); dialog.className = 'export-dialog tara-dialog'; dialog.setAttribute('aria-labelledby', 'taraTitle'); document.body.append(dialog); dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); }); }
    dialog.innerHTML = '<p>Opening Tara AI…</p>'; dialog.showModal();
    try { status = (await api('/api/ai/status')).ai; if (!status.enabled) { dialog.innerHTML = '<p>Tara AI is not switched on yet.</p><button class="button secondary" data-close>Close</button>'; dialog.querySelector('[data-close]').onclick = () => dialog.close(); return; } render(); }
    catch (err) { dialog.innerHTML = `<p>${E(err.status === 401 ? 'Sign in to use Tara AI.' : err.message)}</p><button class="button secondary" data-close>Close</button>`; dialog.querySelector('[data-close]').onclick = () => dialog.close(); }
  }

  function mount() {
    if (document.body.dataset.page !== 'workspace' || !window.CVTaraOwnerContent?.integrations?.ai || document.getElementById('taraButton')) return;
    const b = document.createElement('button'); b.id = 'taraButton'; b.type = 'button'; b.className = 'button secondary small tara-button'; b.textContent = '✨ Tara AI'; b.title = 'Writing help from Tara AI';
    b.onclick = open; document.querySelector('.top-actions')?.prepend(b);
  }
  window.addEventListener('cvtara-owner-content', mount); addEventListener('load', mount);
  window.CVTaraTara = { open };
})();
