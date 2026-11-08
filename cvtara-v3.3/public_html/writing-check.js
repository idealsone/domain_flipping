/* "Check my writing" in the resume and cover-letter builders: weak words, tone and basic grammar, checked on the server.
   The full grammar check (LanguageTool) appears only when the owner switches it on, and only runs when the customer presses it. */
(() => {
  'use strict';
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const E = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const KINDS = [['grammar', 'Grammar & spelling'], ['tone', 'Tone'], ['writing', 'Stronger writing']];
  let dialog, last = null;

  function mark(excerpt, match) {
    const t = E(excerpt); if (!match) return t;
    const m = E(match), i = t.toLowerCase().indexOf(m.toLowerCase());
    return i < 0 ? t : t.slice(0, i) + '<mark>' + t.slice(i, i + m.length) + '</mark>' + t.slice(i + m.length);
  }

  function draft(type) {
    const s = type === 'letter' ? window.CVTaraLetterStudio : window.CVTaraStudio;
    return s && s.getDraft ? s.getDraft() : null;
  }

  function open(type) {
    if (!dialog) {
      dialog = document.createElement('dialog'); dialog.className = 'export-dialog wc-dialog'; dialog.setAttribute('aria-labelledby', 'wcTitle');
      document.body.append(dialog);
      dialog.addEventListener('click', e => { if (e.target === dialog || e.target.closest('[data-wc-close]')) dialog.close(); });
    }
    dialog.dataset.type = type;
    dialog.innerHTML = `<h2 id="wcTitle">Check my writing</h2><p class="wc-lead">Checking your ${type === 'letter' ? 'cover letter' : 'resume'}…</p><button type="button" class="button secondary" data-wc-close>Close</button>`;
    dialog.showModal(); run(type, false);
  }

  async function run(type, full) {
    const data = draft(type), lead = dialog.querySelector('.wc-lead');
    if (!data) { lead.textContent = 'Open the builder first.'; return; }
    try {
      const { check } = await api('/api/writing/check', { method: 'POST', body: { type, data, full } });
      last = check; render(type);
    } catch (e) {
      if (lead) lead.textContent = e.status === 401 ? 'Sign in to check your writing.' : e.message;
      const st = dialog.querySelector('[data-wc-status]'); if (st) st.textContent = e.message;
    }
  }

  function render(type) {
    const c = last, total = c.issues.length, doc = type === 'letter' ? 'cover letter' : 'resume';
    const groups = KINDS.map(([k, label]) => {
      const list = c.issues.filter(x => x.kind === k); if (!list.length) return '';
      return `<section class="wc-group"><h3>${label} <span>${list.length}</span></h3><ol>${list.map(x => `<li><small>${E(x.section)}</small>${x.excerpt ? `<q>${mark(x.excerpt, x.match)}</q>` : ''}<p>${E(x.message)}</p>${x.try && x.try.length ? `<p class="wc-try">Try: ${x.try.map(t => `<b>${E(t)}</b>`).join(', ')}</p>` : ''}</li>`).join('')}</ol></section>`;
    }).join('');
    const full = c.fullGrammar ? `<div class="wc-full"><p>${c.fullGrammarUsed ? 'Full grammar check included.' : 'Want a deeper grammar check? It sends this ' + doc + '’s text to LanguageTool, an outside grammar service.'} ${c.fullGrammarLeft} left today.</p>${c.fullGrammarUsed ? '' : `<button type="button" class="button secondary small" data-wc-full ${c.fullGrammarLeft ? '' : 'disabled'}>Full grammar check</button>`}<p role="status" data-wc-status></p></div>` : '';
    dialog.innerHTML = `<h2 id="wcTitle">Check my writing</h2>
      <p class="wc-lead">${!c.checked ? `There is nothing to check yet. Write a few lines of your ${doc}, then check again.` : total ? `${total} suggestion${total === 1 ? '' : 's'} across ${c.checked} part${c.checked === 1 ? '' : 's'} of your ${doc}. Nothing changes until you edit it yourself.` : `No suggestions. Your ${doc} reads cleanly.`}</p>
      <div class="wc-counts">${KINDS.map(([k, label]) => `<span class="${c.counts[k] ? '' : 'ok'}">${label}<b>${c.counts[k]}</b></span>`).join('')}</div>
      ${groups}${full}
      <p class="wc-note">The basic check runs on CVTara’s own server and follows simple rules, so read each suggestion and keep what is right for you.</p>
      <div class="wc-actions"><button type="button" class="button primary" data-wc-again>Check again</button><button type="button" class="button secondary" data-wc-close>Close</button></div>`;
    dialog.querySelector('[data-wc-again]').onclick = () => run(type, false);
    const f = dialog.querySelector('[data-wc-full]');
    if (f) f.onclick = () => { f.disabled = true; dialog.querySelector('[data-wc-status]').textContent = 'Checking with LanguageTool…'; run(type, true); };
  }

  function button(bar, type, before) {
    if (!bar || bar.querySelector('[data-wc-open]')) return;
    const b = document.createElement('button'); b.type = 'button'; b.className = 'button secondary'; b.dataset.wcOpen = type; b.textContent = 'Check my writing';
    b.title = 'Weak words, tone and grammar';
    b.onclick = () => open(type);
    const ref = before && bar.querySelector(before); ref ? ref.before(b) : bar.append(b);
  }

  function mount() {
    if (document.body.dataset.page !== 'workspace') return;
    button(document.querySelector('.studio-actions'), 'resume', '[data-studio-action="new"]');
    button(document.getElementById('letterStatus')?.parentElement, 'letter', '[data-letter-action="new"]');
  }
  addEventListener('load', mount); document.addEventListener('cvtara-workspace-view', () => setTimeout(mount, 0)); setTimeout(mount, 800);
  window.CVTaraWritingCheck = { open };
})();
