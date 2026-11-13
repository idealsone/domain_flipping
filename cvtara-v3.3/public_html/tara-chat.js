/* Tara, the customer's career assistant: a floating chat on the workspace, a full-screen mode (?tara=1 or "Ask Tara" in the menu),
   past conversations, suggested actions, resume upload through the normal importer, links to the right pages, and a hand-off to the team.
   Everything Tara does goes through the customer's own API on the server. Shown only when the owner has switched Tara on. */
(() => {
  'use strict';
  if (document.querySelector('meta[name=cvtara-user]')?.content === 'guest') return;
  const $ = (s, r = document) => r.querySelector(s);
  const E = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = async (path, opt = {}) => {
    const headers = { Accept: 'application/json', ...(opt.body ? { 'Content-Type': 'application/json', 'X-CVTara-Request': '1', 'X-CSRF-TOKEN': $('meta[name=csrf-token]')?.content || '' } : {}) };
    const res = await fetch(path, { method: opt.method || (opt.body ? 'POST' : 'GET'), credentials: 'same-origin', headers, body: opt.body ? JSON.stringify(opt.body) : undefined });
    let d = {}; try { d = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok || d.ok === false) { const m = d.error || (d.errors && Object.values(d.errors).flat()[0]) || d.message || 'Tara could not answer just now. Please try again.'; throw Object.assign(new Error(m), { status: res.status }); }
    return d;
  };
  // Tara's words: safe text with simple "- " lists, and links only to CVTara's own pages
  const fmt = t => {
    const lines = E(t).split('\n'); let html = '', list = false;
    for (const raw of lines) {
      const l = raw.trim();
      if (/^[-•*]\s+/.test(l)) { if (!list) { html += '<ul>'; list = true; } html += '<li>' + l.replace(/^[-•*]\s+/, '') + '</li>'; continue; }
      if (list) { html += '</ul>'; list = false; }
      html += l ? '<p>' + l + '</p>' : '';
    }
    if (list) html += '</ul>';
    return html.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>').replace(/(^|[\s(])(\/(?:workspace|checkout)\.html(?:\?[\w=&;%.\-]*)?)/g, (m, a, u) => `${a}<a href="${u.replace(/&amp;/g, '&')}">${PAGE[(u.match(/view=([a-z-]+)/) || [])[1]] || (u.includes('checkout') ? 'checkout' : 'this page')}</a>`);
  };
  const PAGE = { billing: 'Billing & Plans', match: 'ATS Match', letter: 'Cover Letters', builder: 'Resume Builder', resumes: 'My Resumes', tracker: 'Applications', account: 'My account', dashboard: 'Dashboard', wallet: 'My Wallet', referrals: 'Refer & Earn' };
  let S = null, conv = null, busy = false, box = null;
  // A click on a package offer is counted (so the owner can see which offers help); it never holds up the page change.
  const track = (plan, source) => { try { fetch('/api/tara/event', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CVTara-Request': '1', 'X-CSRF-TOKEN': $('meta[name=csrf-token]')?.content || '' }, body: JSON.stringify({ kind: 'offer_clicked', plan: plan || null, source }) }).catch(() => {}); } catch (e) { /* ignore */ } };
  const offerLink = a => { const h = a && a.getAttribute('href') || ''; if (!/^\/(checkout\.html|workspace\.html\?view=billing)/.test(h)) return null; const m = h.match(/[?&]plan=([a-z0-9_-]+)/); return m ? m[1] : ''; };

  function shell() {
    box = document.createElement('div');
    box.id = 'cvTara';
    box.innerHTML = `<button type="button" class="tara-fab" aria-haspopup="dialog" aria-controls="taraPanel"><span class="tara-dot" aria-hidden="true">T</span><b>Ask Tara</b></button>
      <section class="tara-panel" id="taraPanel" role="dialog" aria-label="Tara, your CVTara career assistant" hidden inert>
        <header class="tara-head"><span class="tara-dot" aria-hidden="true">T</span><div><h2>Tara</h2><small>Your CVTara career assistant</small></div>
          <button type="button" class="tara-ic" data-t="history" title="Past conversations" aria-label="Past conversations">☰</button>
          <button type="button" class="tara-ic" data-t="new" title="New conversation" aria-label="New conversation">＋</button>
          <button type="button" class="tara-ic" data-t="full" title="Full screen" aria-label="Full screen">⤢</button>
          <button type="button" class="tara-ic" data-t="close" title="Close" aria-label="Close">✕</button></header>
        <div class="tara-credits" aria-live="polite"></div>
        <div class="tara-hist" hidden></div>
        <div class="tara-log" role="log" aria-live="polite"></div>
        <div class="tara-chips"></div>
        <form class="tara-form"><button type="button" class="tara-ic" data-t="upload" title="Upload a resume (PDF or Word)" aria-label="Upload a resume">📎</button>
          <label class="tara-sr" for="taraIn">Message Tara</label><textarea id="taraIn" rows="1" maxlength="6000" placeholder="Ask Tara or paste a job link…"></textarea>
          <button class="tara-send" aria-label="Send">➤</button></form>
        <div class="tara-foot"><span class="tara-left"></span><button type="button" class="tara-link" data-t="human">Talk to our team</button></div>
      </section>`;
    document.body.append(box);
    box.addEventListener('click', onClick);
    box.addEventListener('click', e => { const a = e.target.closest('.tara-log a'); const p = offerLink(a); if (p !== null) track(p, 'chat'); });
    const ta = $('#taraIn', box);
    ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(160, ta.scrollHeight) + 'px'; });
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(ta.value); } });
    $('.tara-form', box).addEventListener('submit', e => { e.preventDefault(); send(ta.value); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#taraPanel').hidden) toggle(false); });
    // 3.41.0: the dashboard's "Get career assistance" opens this chat (with a question when given).
    window.CVTaraAssist = q => { toggle(true); if (q) send(q); };
    document.addEventListener('cvtara-resume-saved', () => { if (!$('#taraPanel').hidden) { note('Your resume was uploaded. Ask me to check it, or to compare it with a job.'); $('#taraIn').value = 'I just uploaded my resume. Can you check it?'; } });
    // 3.47.0: no separate "Ask Tara" menu item. "Career assistance" in the menu and the floating button open this chat.
  }

  function credits(c, left) {
    if (c) $('.tara-credits', box).innerHTML = `Downloads left: <b>${c.resumes.remaining}</b> resume · <b>${c.letters.remaining}</b> cover letter · ATS scans: <b>${c.scans.unlimited ? 'unlimited' : (c.scans.total ? c.scans.remaining : 'daily free')}</b> <a href="/workspace.html?view=billing">Plans</a>`;
    if (left !== undefined) $('.tara-left', box).textContent = `${left} message${left === 1 ? '' : 's'} left today`;
  }

  function bubble(role, text, links = [], id = null) {
    const d = document.createElement('div');
    d.className = 'tara-msg ' + (role === 'user' ? 'me' : 'her');
    d.innerHTML = role === 'user' ? `<p>${E(text).replace(/\n/g, '<br>')}</p>` : fmt(text) + (links.length ? `<div class="tara-links">${links.map(l => `<a class="button small secondary" href="${E(l.url)}">${E(l.label)}</a>`).join('')}</div>` : '') + (id ? `<div class="tara-rate"><button type="button" data-rate="1" data-id="${E(id)}" aria-label="Helpful">👍</button><button type="button" data-rate="-1" data-id="${E(id)}" aria-label="Not helpful">👎</button></div>` : '');
    $('.tara-log', box).append(d);
    d.scrollIntoView({ block: 'end', behavior: 'smooth' });
    return d;
  }
  const note = t => { const d = document.createElement('div'); d.className = 'tara-note'; d.textContent = t; $('.tara-log', box).append(d); d.scrollIntoView({ block: 'end' }); return d; };

  function welcome() {
    $('.tara-log', box).innerHTML = '';
    bubble('assistant', S.greeting || S.welcome);
    $('.tara-chips', box).innerHTML = (S.suggestions || []).map(s => `<button type="button" data-chip="${E(s)}">${E(s)}</button>`).join('');
  }

  async function send(text) {
    text = String(text || '').trim();
    if (!text || busy) return;
    busy = true;
    const ta = $('#taraIn', box); ta.value = ''; ta.style.height = 'auto';
    $('.tara-chips', box).innerHTML = '';
    bubble('user', text);
    const wait = note('');
    wait.className = 'tara-typing'; wait.innerHTML = '<span></span><span></span><span></span> <em>CVTara team is typing…</em>';
    const slow = setTimeout(() => { const em = wait.querySelector('em'); if (em) em.textContent = 'CVTara team is typing… checking your account and documents'; }, 6000);
    try {
      const r = await api('/api/tara/message', { body: { text, ...(conv ? { conversationId: conv } : {}) } });
      conv = r.conversationId;
      wait.remove();
      bubble('assistant', r.message.content, r.message.links || [], r.message.id);
      credits(r.credits, r.left);
    } catch (e) {
      wait.remove();
      const d = note(e.message); d.classList.add('err'); d.setAttribute('role', 'alert');
      if (e.status !== 422 && e.status !== 429) ta.value = text; // keep the words to try again
    } finally { clearTimeout(slow); busy = false; ta.focus(); }
  }

  async function history() {
    const h = $('.tara-hist', box);
    if (!h.hidden) { h.hidden = true; return; }
    h.hidden = false; h.innerHTML = '<p>Loading…</p>';
    try {
      const { conversations } = await api('/api/tara/conversations');
      h.innerHTML = conversations.length ? '<b>Past conversations</b>' + conversations.map(c => `<div class="tara-h"><button type="button" data-open="${E(c.id)}">${E(c.title || 'Conversation')}<small>${E(String(c.last_at || '').slice(0, 16))}</small></button><button type="button" class="tara-ic" data-del="${E(c.id)}" aria-label="Delete this conversation" title="Delete">🗑</button></div>`).join('') : '<p>No past conversations yet.</p>';
    } catch (e) { h.innerHTML = `<p role="alert">${E(e.message)}</p>`; }
  }

  async function openConv(id) {
    try {
      const d = await api('/api/tara/conversations/' + id);
      conv = id; $('.tara-hist', box).hidden = true; $('.tara-chips', box).innerHTML = '';
      $('.tara-log', box).innerHTML = '';
      d.messages.forEach(m => bubble(m.role, m.content || '(removed)', m.links || [], m.role === 'assistant' ? m.id : null));
    } catch (e) { note(e.message).classList.add('err'); }
  }

  function toggle(open, full) {
    const p = $('#taraPanel'); p.hidden = !open; p.inert = !open; box.classList.toggle('open', open);
    if (!open) { const f = $('.tara-fab', box); if (f && box.contains(document.activeElement)) f.focus(); }
    if (full !== undefined) box.classList.toggle('full', !!full);
    if (open) { $('#taraIn').focus(); if (!$('.tara-log', box).children.length) welcome(); }
  }

  async function onClick(e) {
    const t = e.target.closest('[data-t],[data-chip],[data-open],[data-del],[data-rate],.tara-fab');
    if (!t) return;
    if (t.classList.contains('tara-fab')) return toggle(true);
    if (t.dataset.chip) return send(t.dataset.chip);
    if (t.dataset.open) return openConv(t.dataset.open);
    if (t.dataset.del) { if (!confirm('Delete this conversation? Its messages are removed.')) return; try { await api('/api/tara/conversations/' + t.dataset.del + '/delete', { body: {} }); if (conv === t.dataset.del) { conv = null; welcome(); } $('.tara-hist', box).hidden = true; history(); } catch (x) { note(x.message).classList.add('err'); } return; }
    if (t.dataset.rate) { try { await api('/api/tara/rate', { body: { messageId: t.dataset.id, rating: Number(t.dataset.rate) } }); t.parentNode.innerHTML = '<small>Thanks for the feedback.</small>'; } catch (x) { /* rating is optional */ } return; }
    const a = t.dataset.t;
    if (a === 'close') toggle(false);
    else if (a === 'full') box.classList.toggle('full');
    else if (a === 'new') { conv = null; $('.tara-hist', box).hidden = true; welcome(); }
    else if (a === 'history') history();
    else if (a === 'human') send('I would like help from the CVTara support team.');
    else if (a === 'upload') { if (window.CVTaraResumeImport && window.CVTaraResumeImport.pick) window.CVTaraResumeImport.pick(); else location.href = '/workspace.html?view=resumes'; }
  }

  // The dashboard "next step" card: real numbers only, one-click actions, a package only when it fits. Hidden for the day once closed.
  async function nudge() {
    const host = document.getElementById('view-dashboard'); if (!host) return;
    const key = 'cvtara-nudge-' + new Date().toISOString().slice(0, 10);
    try { if (localStorage.getItem(key)) return; } catch (e) { /* storage blocked */ }
    let n; try { n = (await api('/api/tara/nudge')).nudge; } catch (e) { return; }
    if (!n) return;
    // 3.70.0 The next step lives inside the welcome card (#wsNext), replacing its default text in place, so the page does not jump.
    for (let i = 0; i < 20 && !document.getElementById('wsNext'); i++) await new Promise(r => setTimeout(r, 150));
    const slot = document.getElementById('wsNext');
    if (slot) {
      const fill = el => {
        el.dataset.nudge = '1';
        el.innerHTML = `<h2>${E(n.title)}</h2><p>${E(n.line)}</p><p class="ws-acts">${n.actions.map((x, i) => x.url ? `<a class="button ${i ? 'secondary' : 'mint'}" href="${E(x.url)}">${E(x.label)}</a>` : `<button type="button" class="button ${i ? 'secondary' : 'mint'}" data-n="${i}">${E(x.label)}</button>`).join('')}</p>${n.offer ? `<p class="ws-offer"><b>${E(n.offer.name)} · ₹${E(n.offer.price)}</b> ${E(n.offer.why)} <a href="${E(n.offer.url)}">Get ${E(n.offer.name)}</a></p>` : ''}`;
      };
      fill(slot);
      // the welcome card is redrawn when the customer comes back to the dashboard: put the next step back each time
      new MutationObserver(() => { const el = document.getElementById('wsNext'); if (el && !el.dataset.nudge) fill(el); }).observe(host, { childList: true, subtree: true });
      host.addEventListener('click', e => {
        if (!e.target.closest('#wsNext')) return;
        if (e.target.closest('.ws-offer a') && n.offer) track(n.offer.plan, 'dashboard');
        const b = e.target.closest('[data-n]'); if (!b) return;
        const x = n.actions[Number(b.dataset.n)];
        if (x.upload) { if (window.CVTaraResumeImport && window.CVTaraResumeImport.pick) window.CVTaraResumeImport.pick(); else location.href = '/workspace.html?view=resumes'; }
        else if (x.ask && box) { toggle(true); send(x.ask); }
      });
      return;
    }
    const c = document.createElement('section');
    c.className = 'tara-nudge'; c.setAttribute('aria-label', 'Your next step');
    c.innerHTML = `<span class="tara-dot" aria-hidden="true">T</span><div class="tn-body"><small>${E(n.greeting)}</small><h3>${E(n.title)}</h3><p>${E(n.line)}</p>
      <div class="tn-acts">${n.actions.map((x, i) => x.url ? `<a class="button ${i ? 'secondary' : 'primary'} small" href="${E(x.url)}">${E(x.label)}</a>` : `<button type="button" class="button ${i ? 'secondary' : 'primary'} small" data-n="${i}">${E(x.label)}</button>`).join('')}</div>
      ${n.offer ? `<p class="tn-offer"><b>${E(n.offer.name)} · ₹${E(n.offer.price)}</b> ${E(n.offer.why)} <a href="${E(n.offer.url)}">Get ${E(n.offer.name)}</a></p>` : ''}
      ${n.proof.length || n.you ? `<ul class="tn-proof">${n.proof.map(p => `<li>${E(p)}</li>`).join('')}${n.you ? `<li>${E(n.you)}</li>` : ''}</ul>` : ''}</div>
      <button type="button" class="tara-ic tn-x" aria-label="Hide for today" title="Hide for today">✕</button>`;
    host.prepend(c);
    c.addEventListener('click', e => {
      if (e.target.closest('.tn-offer a') && n.offer) track(n.offer.plan, 'dashboard');
      if (e.target.closest('.tn-x')) { c.remove(); try { localStorage.setItem(key, '1'); } catch (x) { /* storage blocked */ } return; }
      const b = e.target.closest('[data-n]'); if (!b) return;
      const x = n.actions[Number(b.dataset.n)];
      if (x.upload) { if (window.CVTaraResumeImport && window.CVTaraResumeImport.pick) window.CVTaraResumeImport.pick(); else location.href = '/workspace.html?view=resumes'; }
      else if (x.ask && box) { toggle(true); send(x.ask); }
    });
  }

  // Once a day after sign-in, Tara says hello in a small bubble beside the button. It never opens the chat by itself
  // (testers on 13 Nov 2026: the auto-opening panel covered the page). The bubble goes away after 8 seconds.
  function greet() {
    const key = 'cvtara-tara-hi-' + new Date().toISOString().slice(0, 10);
    try { if (localStorage.getItem(key)) return; } catch (e) { return; }
    let tries = 0;
    const go = () => {
      if (!box || !$('#taraPanel').hidden || $('.tara-peek', box)) return;
      if (document.querySelector('.ad-modal, [role=dialog][aria-modal=true]:not([hidden])')) { if (++tries < 6) setTimeout(go, 4000); return; } // wait for another pop-up to close
      try { localStorage.setItem(key, '1'); } catch (e) { /* storage blocked */ } // counted only once it is really shown
      const b = document.createElement('button'); b.type = 'button'; b.className = 'tara-peek'; b.textContent = S.greeting.split('. ')[0] + '. Tap to chat.';
      b.onclick = () => { b.remove(); toggle(true); }; box.append(b); setTimeout(() => b.remove(), 8000);
    };
    setTimeout(go, 4000);
  }

  async function start() {
    try { S = (await api('/api/tara/status')).tara; } catch (e) { return; }
    if (!S) return;
    if (S.enabled) {
      shell();
      credits(S.credits, S.left);
      if (new URLSearchParams(location.search).get('tara') === '1') toggle(true, true);
      else if (S.greet) greet();
    }
    if (S.nudges) nudge();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
