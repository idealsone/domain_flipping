(() => {
  'use strict';
  // Floating "Feedback" button with a side panel: choose the area, say what happened, attach up to 5 screenshots (2 MB each) and send.
  // Reports reach the owner console (Support Inbox) with the page and screen size attached.
  if (window.__cvSupport || /^\/(admin|setup)/.test(location.pathname)) return;
  window.__cvSupport = true;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MAX_FILES = 5, MAX_BYTES = 2 * 1024 * 1024;
  if ((document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest') return; // the feedback button is for signed-in customers
  const side = () => { try { return localStorage.getItem('cvtara-feedback-side') === 'left' ? 'left' : 'right'; } catch { return 'right'; } };
  const areas = { 'resume-builder': 'Resume Builder', 'ats-match': 'ATS Match', 'cover-letters': 'Cover Letters', applications: 'Applications', downloads: 'Downloads & credits', billing: 'Billing & plans', account: 'Account & login', website: 'Website & pages', other: 'Something else' };
  const kinds = { bug: 'Something is broken', idea: 'Suggestion', question: 'Question', praise: 'What I like' };
  const st = { files: [], rating: 0, kind: 'bug', open: false, busy: false, done: null };

  function guessArea() {
    const v = new URLSearchParams(location.search).get('view') || '', p = location.pathname;
    const m = { builder: 'resume-builder', resumes: 'resume-builder', match: 'ats-match', letter: 'cover-letters', tracker: 'applications', billing: 'billing', account: 'account' }[v];
    if (m) return m;
    if (/checkout|credits/.test(p)) return 'billing';
    if (/login|register|password/.test(p)) return 'account';
    return location.pathname.startsWith('/workspace') ? 'other' : 'website';
  }

  const root = document.createElement('div');
  root.id = 'cvSupport';
  root.innerHTML = `<button type="button" class="cv-sup-fab" id="cvSupOpen" aria-haspopup="dialog" aria-controls="cvSupPanel"><span aria-hidden="true">💬</span><b>Feedback</b></button>
<aside class="cv-sup-panel" id="cvSupPanel" role="dialog" aria-modal="false" aria-labelledby="cvSupTitle" hidden></aside>`;
  root.dataset.side = side();
  document.body.append(root);
  const fab = root.querySelector('#cvSupOpen'), panel = root.querySelector('#cvSupPanel');

  function form() {
    panel.innerHTML = `<header><div><h2 id="cvSupTitle">Send feedback</h2><p>Tell us what is working, what is not, or what you would like to see.</p></div><div class="cv-sup-tools"><button type="button" class="cv-sup-x" data-flip aria-label="Move the feedback panel to the other side" title="Move to the other side">⇄</button><button type="button" class="cv-sup-x" data-close aria-label="Close feedback">×</button></div></header>
<form novalidate>
<label class="cv-sup-f"><span>What is this about?</span><select name="area">${Object.entries(areas).map(([k, l]) => `<option value="${k}">${E(l)}</option>`).join('')}</select></label>
<div class="cv-sup-f"><span>Type</span><div class="cv-sup-kinds" role="radiogroup" aria-label="Type of feedback">${Object.entries(kinds).map(([k, l]) => `<button type="button" role="radio" aria-checked="${k === st.kind}" data-kind="${k}">${E(l)}</button>`).join('')}</div></div>
<label class="cv-sup-f"><span>Your message</span><textarea name="message" rows="5" maxlength="4000" required placeholder="What happened? What did you expect? The more detail, the faster we can fix it."></textarea><small class="cv-sup-count" id="cvSupCount">0 / 4000</small></label>
<div class="cv-sup-f"><span>Screenshots <small>(optional · up to ${MAX_FILES} images · 2 MB each · JPG, PNG or WebP)</small></span>
<label class="cv-sup-drop"><input type="file" id="cvSupFiles" accept="image/jpeg,image/png,image/webp" multiple hidden><b>Add screenshots</b><small>Click to choose images</small></label><div class="cv-sup-thumbs" id="cvSupThumbs"></div></div>
<div class="cv-sup-f"><span>How is your experience? <small>(optional)</small></span><div class="cv-sup-stars" role="radiogroup" aria-label="Rating">${[1, 2, 3, 4, 5].map(n => `<button type="button" role="radio" aria-checked="false" data-star="${n}" aria-label="${n} star${n > 1 ? 's' : ''}">★</button>`).join('')}</div></div>
<input type="text" name="company_site" tabindex="-1" autocomplete="off" aria-hidden="true" class="cv-sup-hp">
<p class="cv-sup-ctx">We send your name and account email with this report so we can reply. We also include the page you are on and your screen size so we can see what you saw. We never include your resume.</p>
<p class="cv-sup-status" id="cvSupStatus" role="status" aria-live="polite"></p>
<button type="submit" class="cv-sup-send">Send feedback</button></form>`;
    panel.querySelector('[name=area]').value = guessArea();
    thumbs();
  }

  function thanks() {
    panel.innerHTML = `<header><div><h2 id="cvSupTitle">Thank you!</h2></div><button type="button" class="cv-sup-x" data-close aria-label="Close feedback">×</button></header>
<div class="cv-sup-thanks"><p>Your feedback reached our team${st.done ? ' (reference <b>#' + E(st.done) + '</b>)' : ''}. We read every message and use it to make CVTara better.</p><button type="button" class="cv-sup-send" data-again>Send another</button></div>`;
  }

  const bytes = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
  function thumbs() {
    const t = panel.querySelector('#cvSupThumbs'); if (!t) return;
    t.innerHTML = st.files.map((f, i) => `<figure><img src="${f.url}" alt="Screenshot ${i + 1}"><figcaption>${E(bytes(f.blob.size))}</figcaption><button type="button" data-rm="${i}" aria-label="Remove screenshot ${i + 1}">×</button></figure>`).join('');
    const d = panel.querySelector('.cv-sup-drop'); if (d) d.classList.toggle('full', st.files.length >= MAX_FILES);
  }
  const say = (m, bad) => { const s = panel.querySelector('#cvSupStatus'); if (s) { s.textContent = m || ''; s.classList.toggle('bad', !!bad); } };

  // Large phone screenshots are shrunk before sending so several images fit in one request. The 2 MB limit applies to the original file.
  async function shrink(file) {
    try {
      const bmp = await createImageBitmap(file), s = Math.min(1, 1800 / Math.max(bmp.width, bmp.height)), c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(bmp.width * s)); c.height = Math.max(1, Math.round(bmp.height * s));
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/webp', 0.85));
      if (blob && blob.size < file.size) return blob;
    } catch { /* send the original */ }
    return file;
  }

  async function addFiles(list) {
    for (const f of list) {
      if (st.files.length >= MAX_FILES) { say('You can attach up to ' + MAX_FILES + ' screenshots.', true); break; }
      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { say('“' + f.name + '” is not a JPG, PNG or WebP image.', true); continue; }
      if (f.size > MAX_BYTES) { say('“' + f.name + '” is larger than 2 MB. Choose a smaller screenshot.', true); continue; }
      const blob = await shrink(f);
      st.files.push({ blob, name: f.name.replace(/\.[a-z0-9]+$/i, '') + (blob.type === 'image/webp' ? '.webp' : /png$/.test(f.type) ? '.png' : '.jpg'), url: URL.createObjectURL(blob) });
    }
    thumbs();
  }

  async function submit(e) {
    e.preventDefault();
    if (st.busy) return;
    const f = e.target, msg = f.message.value.trim();
    if (msg.length < 10) { say('Please tell us a little more (at least 10 characters).', true); f.message.focus(); return; }
    st.busy = true; const btn = f.querySelector('.cv-sup-send'); btn.disabled = true; say('Sending…');
    try {
      const fd = new FormData();
      fd.set('area', f.area.value); fd.set('kind', st.kind); fd.set('message', msg);
      if (st.rating) fd.set('rating', String(st.rating));
      fd.set('company_site', f.company_site.value);
      fd.set('page', location.pathname + location.search); fd.set('viewport', innerWidth + 'x' + innerHeight);
      st.files.forEach(x => fd.append('images[]', x.blob, x.name));
      const token = await window.CVTaraCSRF();
      const r = await fetch('/support/report', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
      let j = {}; try { j = await r.json(); } catch { /* not JSON */ }
      if (!r.ok || j.ok === false) throw Error(j.error || j.message || (r.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'We could not send your feedback. Please try again.'));
      st.done = j.id; st.files.forEach(x => URL.revokeObjectURL(x.url)); st.files = []; st.rating = 0; thanks();
    } catch (err) { say(err.message, true); btn.disabled = false; } finally { st.busy = false; }
  }

  function open() { st.open = true; root.dataset.side = side(); if (!panel.querySelector('form') && !panel.querySelector('.cv-sup-thanks')) form(); panel.hidden = false; root.classList.add('open'); fab.setAttribute('aria-expanded', 'true'); setTimeout(() => (panel.querySelector('[name=area]') || panel.querySelector('button'))?.focus(), 30); }
  function close() { st.open = false; panel.hidden = true; root.classList.remove('open'); fab.setAttribute('aria-expanded', 'false'); fab.focus(); }

  fab.addEventListener('click', () => (st.open ? close() : open()));
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && st.open) close(); });
  panel.addEventListener('click', e => {
    if (e.target.closest('[data-close]')) return close();
    if (e.target.closest('[data-flip]')) { try { localStorage.setItem('cvtara-feedback-side', side() === 'left' ? 'right' : 'left'); } catch { /* keep the current side */ } root.dataset.side = side(); return; }
    if (e.target.closest('[data-again]')) { st.done = null; form(); return; }
    const k = e.target.closest('[data-kind]'); if (k) { st.kind = k.dataset.kind; panel.querySelectorAll('[data-kind]').forEach(b => b.setAttribute('aria-checked', String(b === k))); return; }
    const s = e.target.closest('[data-star]'); if (s) { st.rating = st.rating === +s.dataset.star ? 0 : +s.dataset.star; panel.querySelectorAll('[data-star]').forEach(b => { const on = +b.dataset.star <= st.rating; b.setAttribute('aria-checked', String(+b.dataset.star === st.rating)); b.classList.toggle('on', on); }); return; }
    const rm = e.target.closest('[data-rm]'); if (rm) { const [x] = st.files.splice(+rm.dataset.rm, 1); if (x) URL.revokeObjectURL(x.url); thumbs(); return; }
    if (e.target.closest('.cv-sup-drop') && e.target.tagName !== 'INPUT') panel.querySelector('#cvSupFiles').click();
  });
  panel.addEventListener('change', e => { if (e.target.id === 'cvSupFiles') { addFiles([...e.target.files]); e.target.value = ''; } });
  panel.addEventListener('input', e => { if (e.target.name === 'message') panel.querySelector('#cvSupCount').textContent = e.target.value.length + ' / 4000'; });
  panel.addEventListener('submit', e => { if (e.target.matches('form')) submit(e); });
})();
