(() => {
  'use strict';
  // "What's new": short, dated notes on improvements the CVTara team made. Signed-in customers see the new ones once,
  // in a small window, after an update; the top-bar button opens the full list any time.
  if (document.body.dataset.page !== 'workspace' || !window.CVTaraAPI) return;
  if ((document.querySelector('meta[name=cvtara-user]')?.content || 'guest') === 'guest') return;
  const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (p, o) => window.CVTaraAPI.request(p, o);
  const LS = 'cvtara-whats-new-seen';
  const TAG = { new: 'New', improved: 'Improved', fixed: 'Fixed', safety: 'Safety' };
  const day = d => new Date(d + 'T12:00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
  const month = d => new Date(d + 'T12:00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' });
  let data = null, dlg = null, btn = null, popupMode = false;

  function button() {
    const bar = document.querySelector('.workspace-top .top-actions'); if (!bar || btn) return;
    btn = document.createElement('button'); btn.type = 'button'; btn.className = 'button secondary small wn-open'; btn.id = 'whatsNewBtn';
    btn.setAttribute('aria-haspopup', 'dialog');
    btn.innerHTML = '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0"/></svg><span class="wn-label">What\'s new</span>';
    btn.onclick = () => open(false);
    bar.prepend(btn);
  }
  function dot(on) {
    if (!btn) return; let d = btn.querySelector('.wn-dot');
    if (on && !d) { d = document.createElement('i'); d.className = 'wn-dot'; btn.append(d); }
    if (d) d.hidden = !on;
    btn.setAttribute('aria-label', on ? "What's new: new updates from the CVTara team" : "What's new");
  }
  function item(e) {
    return `<li class="wn-item"><div class="wn-meta"><span class="wn-tag t-${E(e.tag)}">${E(TAG[e.tag] || 'Update')}</span><time datetime="${E(e.date)}">${E(day(e.date))}</time></div><b>${E(e.title)}</b><p>${E(e.body)}</p></li>`;
  }
  function render(onlyNew) {
    const all = data.entries || [], fresh = all.filter(e => data.unseen.includes(e.id));
    const list = onlyNew ? fresh.slice(0, 4) : all, more = onlyNew ? fresh.length - list.length : 0;
    let html = '', last = '';
    for (const e of list) { const m = month(e.date); if (!onlyNew && m !== last) { html += `<li class="wn-month" aria-hidden="true">${E(m)}</li>`; last = m; } html += item(e); }
    dlg.innerHTML = `<div class="wn-head"><div><h2 id="wnTitle">${onlyNew ? 'New on CVTara since your last visit' : "What's new on CVTara"}</h2><p>Improvements the CVTara team made for you. We keep working on the site every week.</p></div><button type="button" class="wn-x" data-wn-close aria-label="Close">×</button></div>
      <ul class="wn-list">${html || '<li class="wn-empty">No updates yet. Check back soon.</li>'}</ul>
      <div class="wn-foot"><small>Have an idea? Tell us with <a href="#" data-wn-feedback><u>Share feedback</u></a>.</small>${onlyNew ? `<button type="button" class="button secondary small" data-wn-all>See all updates${more > 0 ? ` (${more} more)` : ''}</button>` : ''}<button type="button" class="button primary small" data-wn-close>Got it</button></div>`;
  }
  function open(onlyNew) {
    if (!data) return;
    if (!dlg) {
      dlg = document.createElement('dialog'); dlg.id = 'cvWhatsNew'; dlg.setAttribute('aria-labelledby', 'wnTitle'); document.body.append(dlg);
      dlg.addEventListener('click', e => {
        if (e.target === dlg || e.target.closest('[data-wn-close]')) { dlg.close(); return; }
        if (e.target.closest('[data-wn-all]')) { render(false); dlg.querySelector('.wn-list').scrollTop = 0; return; }
        if (e.target.closest('[data-wn-feedback]')) { e.preventDefault(); dlg.close(); document.querySelector('[data-workspace-view="feedback"]')?.click(); }
      });
      dlg.addEventListener('close', seen);
    }
    popupMode = onlyNew; render(onlyNew);
    if (!dlg.open) { if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', ''); }
    dlg.querySelector('[data-wn-close].button')?.focus();
  }
  async function seen() {
    if (!data || !data.unseen.length) return;
    const newest = (data.entries.find(e => data.unseen.includes(e.id)) || {}).publishedAt || new Date().toISOString();
    try { localStorage.setItem(LS, newest); } catch { /* private mode */ }
    data.unseen = []; dot(false);
    try { await api('/api/whats-new/seen', { method: 'POST', body: {} }); } catch { /* this device remembers it anyway */ }
  }
  const busy = () => document.querySelector('dialog[open]'); // another window (welcome form, plan dialog) goes first
  function popup(tries = 0) {
    if (!data || !data.unseen.length) return;
    if (busy()) { if (tries < 6) setTimeout(() => popup(tries + 1), 5000); return; }
    open(true);
  }
  async function load() {
    button();
    try { data = await api('/api/whats-new'); } catch { return; }
    data.entries = data.entries || []; data.unseen = data.unseen || [];
    // Already closed on this device (the save to the server may have failed): do not show the same notes again.
    let local = ''; try { local = localStorage.getItem(LS) || ''; } catch { /* ignore */ }
    if (local) data.unseen = data.unseen.filter(id => { const e = data.entries.find(x => x.id === id); return e && Date.parse(e.publishedAt) > Date.parse(local); });
    dot(data.unseen.length > 0);
    if (new URLSearchParams(location.search).get('whats-new') === '1') { open(false); return; }
    setTimeout(popup, 2500);
  }
  document.addEventListener('click', e => { const a = e.target.closest('[data-wn-link]'); if (a && data) { e.preventDefault(); open(false); } });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
  window.CVTaraWhatsNew = { open: () => open(false) };
})();
