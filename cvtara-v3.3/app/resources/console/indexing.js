/* Owner console: "Search engines" box on the Overview (3.60.1). How many pages Google and Bing have indexed and what IndexNow
   has sent, from GET /api/admin/indexing/engines. Shown to the owner and to staff who may view SEO. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api } = K;
  const n = v => v == null ? '—' : Number(v).toLocaleString('en-IN');
  const day = s => s ? new Date(s).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'never';
  const date = s => s ? new Date(s + (String(s).length === 10 ? 'T00:00:00' : '')).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '';
  let busy = false;

  const card = (title, big, sub, body) => `<div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;min-width:0"><small class="muted" style="font-weight:700">${title}</small><div style="font-size:24px;font-weight:800;margin:2px 0">${big}</div><div class="muted" style="font-size:12.5px">${sub}</div>${body || ''}</div>`;
  const list = rows => rows.length ? `<ul style="margin:8px 0 0;padding-left:18px;font-size:13px">${rows.map(r => `<li>${r}</li>`).join('')}</ul>` : '';
  const go = (view, label) => `<button type="button" class="button secondary small" data-admin-view="${view}" style="margin-top:8px">${label}</button>`;

  function google(g) {
    if (!g.pages) return card('Google', '—', 'Page scan has not run yet.', '');
    const rows = g.reasons.map(r => `${n(r.count)} ${E(r.label)}`);
    if (g.notChecked) rows.push(`${n(g.notChecked)} not checked yet (about 60 are checked a day)`);
    const s = g.search ? `<p style="margin:8px 0 0;font-size:13px">Search, ${E(date(g.search.from))} to ${E(date(g.search.to))}: <b>${n(g.search.impressions)}</b> impressions, <b>${n(g.search.clicks)}</b> clicks, ${n(g.search.pages)} pages seen</p>` : '';
    return card('Google', `${n(g.indexed)} <span style="font-size:14px;font-weight:600" class="muted">of ${n(g.pages)} pages indexed</span>`,
      g.connected ? `${n(g.checked)} pages checked with Search Console · last check ${E(day(g.lastChecked))}` : 'Search Console is not connected (Integrations > Analytics).', list(rows) + s);
  }

  function bing(b) {
    if (!b.connected) return card('Bing', '—', 'Not connected yet.', `<p style="margin:8px 0 0;font-size:13px">In Bing Webmaster Tools add ${E(b.site)} (Import from Google Search Console is quickest), then copy Settings &gt; API access &gt; API key into Integrations &gt; Analytics.</p>${go('integrations', 'Open Integrations')}`);
    if (b.error) return card('Bing', '—', `Checked ${E(day(b.at))}`, `<p role="alert" style="margin:8px 0 0;font-size:13px">${E(b.error)}</p>`);
    if (b.inIndex == null) return card('Bing', '—', `Checked ${E(day(b.at))}`, `<p style="margin:8px 0 0;font-size:13px">${E(b.note || '')}</p>`);
    return card('Bing', `${n(b.inIndex)} <span style="font-size:14px;font-weight:600" class="muted">pages in index</span>`, `Bing's figure for ${E(date(b.asOf))} · checked ${E(day(b.at))}`,
      list([`${n(b.crawled)} pages crawled that day`, `${n(b.crawlErrors)} crawl errors`, ...(b.blockedByRobots ? [`${n(b.blockedByRobots)} blocked by robots.txt`] : []), `Last 30 days: ${n(b.impressions30)} impressions, ${n(b.clicks30)} clicks`]));
  }

  function indexnow(x) {
    const rows = [x.lastAt ? `Last send ${E(day(x.lastAt))}: ${n(x.lastCount)} page(s)` : 'Nothing sent yet',
      x.auto ? 'New and changed pages are sent automatically every hour' : 'Automatic sending is off (Keyword intelligence > Fast indexing)'];
    if (!x.indexing) rows.unshift('<b>Search indexing is switched off in SEO settings, so nothing is sent.</b>');
    return card('IndexNow (Bing, Yandex and others)', `${n(x.sent)} <span style="font-size:14px;font-weight:600" class="muted">of ${n(x.pages)} pages sent</span>`,
      `<b>${n(x.waiting)}</b> new or changed page(s) waiting`, list(rows) + go('keywords', 'Fast indexing'));
  }

  async function panel(refresh) {
    const host = $('#admin-dashboard'); if (!host || busy) return;
    let box = $('#indexPanel');
    if (!box) { box = document.createElement('section'); box.id = 'indexPanel'; box.className = 'panel panel-pad'; box.style.marginBottom = '18px'; box.innerHTML = '<div class="ops-skel"></div>'; place(host, box); }
    busy = true;
    let j; try { j = await api('/api/admin/indexing/engines' + (refresh ? '/refresh' : ''), refresh ? { method: 'POST', body: {} } : undefined); } catch (e) { box.innerHTML = `<p role="alert">${E((e && e.message) || 'Could not load search engine status.')}</p>`; return; } finally { busy = false; }
    box.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap"><h2 style="margin:0;font-size:18px">Search engines</h2><button type="button" class="button secondary small" data-ix-refresh>Refresh</button></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-top:12px">${google(j.google)}${bing(j.bing)}${indexnow(j.indexnow)}</div>
      <p class="muted" style="margin:10px 0 0;font-size:12.5px">Indexing is decided by each search engine; sending a page does not guarantee it is indexed. New sites often take weeks.</p>`;
    box.querySelector('[data-ix-refresh]').onclick = e => { e.target.disabled = true; e.target.textContent = 'Checking…'; panel(true); };
  }

  // Sits after the Business numbers box when there is one, otherwise after the banner.
  function place(host, box) { const after = $('#kpiPanel') || host.querySelector(':scope > .ops-banner'); after ? after.after(box) : host.prepend(box); }

  const watch = async () => {
    const h = $('#admin-dashboard'); if (!h) return;
    const who = await (window.CVTaraWho || Promise.resolve(null)).catch(() => null);
    if (who && who.role !== 'owner' && !(who.can && who.can('seo.view'))) return;
    new MutationObserver(() => { const box = $('#indexPanel'); if (!box && h.querySelector(':scope > :not(.ops-skel)')) panel(); else if (box) { const want = $('#kpiPanel') || h.querySelector(':scope > .ops-banner'); if (want && want.nextElementSibling !== box) want.after(box); } }).observe(h, { childList: true });
    if (!$('#indexPanel')) panel();
  };
  addEventListener('load', () => setTimeout(watch, 1700));
})();
