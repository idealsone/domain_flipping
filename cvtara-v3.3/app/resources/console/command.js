/* Console: grouped side menu and the Ctrl+K command palette (3.39.0).
   The menu is regrouped in the browser from the existing buttons, so every view, test and link keeps working. Each
   group opens and closes; the choice is remembered in this browser. The palette searches menu pages, settings pages,
   customers, orders and support requests (the server returns only what the person's role may see) and can hand a
   question to the Business assistant, where any change still waits for the owner's Confirm. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api } = K;
  const store = {
    get: (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch (_) { return d; } },
    set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (_) { /* private window */ } }
  };

  /* ---------------- Grouped side menu ---------------- */
  const GROUPS = [
    ['customers', 'Customers & orders', ['people', 'orders', 'feedback', 'referral', 'partners']],
    ['content', 'Content & pages', ['resumelib', 'letterlib', 'sitedesign', 'bloghub', 'desk', 'whatsnew', 'interviews']],
    ['growth', 'Growth & marketing', ['growth', 'promos', 'campaigns', 'outreach', 'mailer', 'tara']],
    ['money', 'Money & plans', ['pricing', 'business', 'reports']],
    ['system', 'System', ['speed', 'errors', 'settings']]
  ];
  const OPEN_KEY = 'cvtara-nav-groups';
  function groupNav() {
    const nav = $('.side-nav'); if (!nav || nav.dataset.grouped) return;
    nav.dataset.grouped = '1';
    const btn = v => nav.querySelector(`[data-admin-view="${v}"]`);
    const guide = nav.querySelector('a.nav-link');
    const open = store.get(OPEN_KEY, {});
    const oldToggle = nav.querySelector('[data-ops-toggle]'), oldSub = $('#opsNav');
    const used = new Set();
    for (const [key, label, views] of GROUPS) {
      const sub = document.createElement('div'); sub.className = 'nav-sub'; sub.id = 'navg-' + key; sub.dataset.navGroup = key;
      views.forEach(v => { const b = btn(v); if (b) { sub.append(b); used.add(v); } });
      if (key === 'system' && guide) sub.append(guide);
      const t = document.createElement('button'); t.type = 'button'; t.className = 'nav-toggle'; t.dataset.navToggle = key;
      t.setAttribute('aria-controls', sub.id); t.innerHTML = `<span>${E(label)}</span><em aria-hidden="true"></em>`;
      nav.append(t, sub);
      setOpen(key, open[key] !== false);
    }
    // Anything a later release adds to the old Operations list stays reachable under System.
    if (oldSub) { $$('[data-admin-view]', oldSub).forEach(b => { if (!used.has(b.dataset.adminView)) $('#navg-system').prepend(b); }); oldSub.remove(); }
    if (oldToggle) oldToggle.remove();
    nav.addEventListener('click', e => {
      const t = e.target.closest('[data-nav-toggle]'); if (!t) return;
      const k = t.dataset.navToggle, now = t.getAttribute('aria-expanded') !== 'true';
      setOpen(k, now); const o = store.get(OPEN_KEY, {}); o[k] = now; store.set(OPEN_KEY, o);
    });
    tidy();
    new MutationObserver(tidy).observe(nav, { subtree: true, attributes: true, attributeFilter: ['hidden', 'class'] });
  }
  function setOpen(key, on) {
    const t = $(`[data-nav-toggle="${key}"]`), sub = $('#navg-' + key); if (!t || !sub) return;
    t.setAttribute('aria-expanded', String(on)); sub.classList.toggle('closed', !on); t.querySelector('em').textContent = on ? '▲' : '▼';
  }
  function tidy() {
    $$('.side-nav [data-nav-group]').forEach(sub => {
      const any = $$('[data-admin-view],a', sub).some(b => !b.hidden);
      const t = $(`[data-nav-toggle="${sub.dataset.navGroup}"]`);
      // Only write when something changes: writing the same value still wakes the observer and would loop.
      if (t && t.hidden !== !any) t.hidden = !any; if (sub.hidden !== !any) sub.hidden = !any;
      // The page you are on is never hidden inside a closed group.
      if ($('.active', sub) && sub.classList.contains('closed')) setOpen(sub.dataset.navGroup, true);
    });
  }

  /* ---------------- Command palette ---------------- */
  const RECENT_KEY = 'cvtara-cmd-recent', FAV_KEY = 'cvtara-cmd-fav';
  let who = null;
  const isOwner = () => !who || who.role === 'owner';
  function pages() {
    const out = [], seen = new Set();
    $$('.side-nav [data-admin-view]').forEach(b => {
      const v = b.dataset.adminView; if (b.hidden || seen.has(v)) return; seen.add(v);
      const m = K.META[v] || [];
      out.push({ id: 'view:' + v, kind: 'Page', title: m[1] || b.dataset.title || b.textContent.trim(), sub: m[2] || '', run: () => b.click() });
    });
    K.HUB.forEach(v => {
      if (seen.has(v) || !K.META[v]) return; seen.add(v);
      out.push({ id: 'view:' + v, kind: 'Settings', title: K.META[v][1], sub: K.META[v][2] || '', run: () => K.open(v) });
    });
    return out;
  }
  const norm = s => String(s || '').toLowerCase();
  const match = (it, q) => { const t = norm(it.title + ' ' + it.sub); return q.split(/\s+/).every(w => t.includes(w)); };

  let box = null, items = [], sel = 0, timer = 0, seq = 0;
  function ensureBox() {
    if (box) return box;
    const css = document.createElement('style');
    css.textContent = `.cmdk-back{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:9998;display:flex;align-items:flex-start;justify-content:center;padding:10vh 16px 16px}
      .cmdk{width:min(640px,100%);max-height:70vh;display:flex;flex-direction:column;background:var(--paper,#fff);color:var(--ink,#0f172a);border-radius:14px;box-shadow:0 24px 60px rgba(15,23,42,.35);overflow:hidden}
      .cmdk input{border:0;border-bottom:1px solid var(--line,#e2e8f0);padding:16px 18px;font:inherit;font-size:16px;background:transparent;color:inherit;outline:none}
      .cmdk ul{list-style:none;margin:0;padding:6px;overflow:auto}
      .cmdk li{display:flex;gap:10px;align-items:center;padding:9px 12px;border-radius:9px;cursor:pointer}
      .cmdk li[aria-selected=true]{background:var(--soft,#eef2ff)}
      .cmdk li .k{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b;min-width:74px}
      .cmdk li .t{flex:1;min-width:0}.cmdk li .t b{display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .cmdk li .t small{color:#64748b;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .cmdk li .fav{border:0;background:none;font-size:16px;cursor:pointer;color:#94a3b8;padding:2px 6px}.cmdk li .fav.on{color:#f59e0b}
      .cmdk .hint{padding:8px 14px;font-size:12px;color:#64748b;border-top:1px solid var(--line,#e2e8f0)}
      .cmdk .grp{padding:8px 12px 2px;font-size:11px;color:#64748b;font-weight:700}
      @media (prefers-color-scheme:dark){:root:not([data-theme=light]) .cmdk li[aria-selected=true]{background:rgba(148,163,184,.18)}}
      :root[data-theme=dark] .cmdk li[aria-selected=true]{background:rgba(148,163,184,.18)}`;
    document.head.append(css);
    box = document.createElement('div'); box.className = 'cmdk-back'; box.hidden = true;
    box.innerHTML = `<div class="cmdk" role="dialog" aria-modal="true" aria-label="Go to or search"><input id="cmdkIn" type="search" autocomplete="off" placeholder="Go to a page, or search customers, orders, support…" aria-controls="cmdkList" aria-autocomplete="list"><ul id="cmdkList" role="listbox"></ul><div class="hint">↑ ↓ to move · Enter to open · Esc to close · ☆ keeps a page at the top</div></div>`;
    document.body.append(box);
    box.addEventListener('mousedown', e => { if (e.target === box) close(); });
    const inp = $('#cmdkIn', box);
    inp.addEventListener('input', () => refresh());
    inp.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); } else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); choose(items[sel]); } else if (e.key === 'Escape') { e.preventDefault(); close(); }
    });
    $('#cmdkList', box).addEventListener('click', e => {
      const f = e.target.closest('.fav'); const li = e.target.closest('li[data-i]'); if (!li) return;
      const it = items[+li.dataset.i];
      if (f) { const fav = store.get(FAV_KEY, []); const i = fav.indexOf(it.id); i < 0 ? fav.unshift(it.id) : fav.splice(i, 1); store.set(FAV_KEY, fav.slice(0, 12)); refresh(); inp.focus(); return; }
      choose(it);
    });
    return box;
  }
  function move(d) { if (!items.length) return; sel = (sel + d + items.length) % items.length; paint(); }
  function paint() {
    const ul = $('#cmdkList', box), fav = store.get(FAV_KEY, []);
    let last = '';
    ul.innerHTML = items.length ? items.map((it, i) => {
      const head = it.group && it.group !== last ? `<li class="grp" role="presentation">${E(it.group)}</li>` : ''; last = it.group || last;
      const canFav = it.id.startsWith('view:');
      return `${head}<li role="option" id="cmdk-${i}" data-i="${i}" aria-selected="${i === sel}"><span class="k">${E(it.kind)}</span><span class="t"><b>${E(it.title)}</b>${it.sub ? `<small>${E(it.sub)}</small>` : ''}</span>${canFav ? `<button type="button" class="fav ${fav.includes(it.id) ? 'on' : ''}" aria-label="${fav.includes(it.id) ? 'Remove from favourites' : 'Add to favourites'}">${fav.includes(it.id) ? '★' : '☆'}</button>` : ''}</li>`;
    }).join('') : '<li class="grp" role="presentation">Nothing found.</li>';
    $('#cmdkIn', box).setAttribute('aria-activedescendant', items.length ? 'cmdk-' + sel : '');
    const cur = $('#cmdk-' + sel, box); if (cur) cur.scrollIntoView({ block: 'nearest' });
  }
  function refresh() {
    const q = norm($('#cmdkIn', box).value).trim(), all = pages(), byId = Object.fromEntries(all.map(x => [x.id, x]));
    if (!q) {
      const fav = store.get(FAV_KEY, []).map(id => byId[id]).filter(Boolean).map(x => ({ ...x, group: 'Favourites' }));
      const rec = store.get(RECENT_KEY, []).map(id => byId[id]).filter(x => x && !fav.some(f => f.id === x.id)).slice(0, 6).map(x => ({ ...x, group: 'Recent' }));
      const rest = all.filter(x => !fav.some(f => f.id === x.id) && !rec.some(r => r.id === x.id)).map(x => ({ ...x, group: 'Pages' }));
      items = [...fav, ...rec, ...rest]; sel = 0; paint(); return;
    }
    // Title matches first (start of the title before anywhere in it), then pages that only mention it in their description.
    const rank = x => { const t = norm(x.title); return t.startsWith(q) ? 0 : t.includes(q) ? 1 : 2; };
    items = all.filter(x => match(x, q)).sort((a, b) => rank(a) - rank(b)).slice(0, 8).map(x => ({ ...x, group: 'Pages' }));
    if (isOwner() && q.length > 3) items.push({ id: 'ask', kind: 'Ask', group: 'Business assistant', title: 'Ask the Business assistant: “' + $('#cmdkIn', box).value.trim() + '”', sub: 'Answers from CVTara\'s records; any change waits for your Confirm', run: () => ask($('#cmdkIn', box).value.trim()) });
    sel = 0; paint();
    clearTimeout(timer);
    if (q.length < 2) return;
    const mine = ++seq;
    timer = setTimeout(async () => {
      let d; try { d = await api('/api/admin/find?q=' + encodeURIComponent(q)); } catch (_) { return; }
      if (mine !== seq || box.hidden) return;
      const found = [
        ...d.users.map(u => ({ id: 'user:' + u.id, kind: 'Customer', group: 'Customers', title: u.name || u.email, sub: u.email + (u.owner ? ' · owner' : '') + (u.blocked ? ' · blocked' : ''), run: () => K.openUsers ? K.openUsers(u.email) : K.open('people') })),
        ...d.orders.map(o => ({ id: 'order:' + o.id, kind: 'Order', group: 'Orders', title: (o.email || 'Order') + ' · ' + o.plan + ' · ' + K.inr(o.amountPaise), sub: o.status + ' · ' + String(o.at).slice(0, 16) + ' · ' + o.id.slice(0, 8), run: () => K.openOrder ? K.openOrder(o.id) : K.open('orders') })),
        ...d.tickets.map(x => ({ id: 'ticket:' + x.id, kind: 'Support', group: 'Support requests', title: x.email + ' · ' + x.status, sub: x.text, run: () => K.openSupport ? K.openSupport(x.email) : K.open('feedback') }))
      ];
      const ask = items.filter(x => x.id === 'ask');
      items = [...items.filter(x => x.id !== 'ask'), ...found, ...ask];
      if (sel >= items.length) sel = 0;
      paint();
    }, 250);
  }
  function choose(it) {
    if (!it) return;
    if (it.id.startsWith('view:')) { const r = store.get(RECENT_KEY, []).filter(x => x !== it.id); r.unshift(it.id); store.set(RECENT_KEY, r.slice(0, 8)); }
    close(); try { it.run(); } catch (_) { /* a view that failed to load shows its own message */ }
  }
  function ask(text) {
    K.open('assistant');
    let n = 0; const t = setInterval(() => { const a = $('#tbText'); if (a && !a.disabled) { clearInterval(t); a.value = text; a.focus(); } else if (++n > 40) clearInterval(t); }, 150);
  }
  let back = null;
  function show() { ensureBox(); back = document.activeElement; box.hidden = false; const i = $('#cmdkIn', box); i.value = ''; refresh(); i.focus(); }
  function close() { if (!box) return; box.hidden = true; seq++; if (back && back.focus) back.focus(); }
  K.palette = show;

  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); box && !box.hidden ? close() : show(); }
  });
  function addButton() {
    const top = $('.admin-top .top-actions'); if (!top || $('#cmdkBtn')) return;
    const mac = /Mac|iPhone|iPad/.test(navigator.platform || '');
    top.insertAdjacentHTML('afterbegin', `<button type="button" id="cmdkBtn" class="button secondary small" aria-keyshortcuts="Control+K Meta+K" title="Go to a page or search (${mac ? '⌘' : 'Ctrl'}+K)">🔎 Search <kbd style="font:inherit;font-size:11px;opacity:.7;margin-left:4px">${mac ? '⌘' : 'Ctrl'} K</kbd></button>`);
    $('#cmdkBtn').onclick = show;
  }
  const start = () => { groupNav(); addButton(); if (window.CVTaraWho) window.CVTaraWho.then(w => { who = w; tidy(); }).catch(() => 0); };
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
})();
