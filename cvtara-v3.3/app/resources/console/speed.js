/* Owner console: Speed & traffic — ready-made pages, busy mode and the job link reader. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const post = async (path, body) => { if (!(await ensureRecent())) throw new Error('Please confirm your identity to continue.'); return api(path, { method: 'POST', body }); };
  const kb = n => n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';

  async function speed() {
    const h = $('#admin-speed'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    let s, p;
    try { [s, p] = await Promise.all([api('/api/admin/speed/status'), api('/api/admin/providers')]); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const c = s.cache, t = s.traffic, r = s.reader, jr = (p.providers || {}).jobreader || {};
    const sel = (v, cur) => v === cur ? 'selected' : '';
    h.innerHTML = `<div class="pg-head"><h2>Speed & traffic</h2><p>Keep CVTara fast when many people arrive at once, and power the job-link reading in ATS Match.</p></div>
      <div class="ops-kpis"><div class="kpi"><span>Ready-made pages</span><b>${c.pages}</b></div><div class="kpi"><span>Size</span><b>${kb(c.bytes)}</b></div><div class="kpi"><span>Last built</span><b style="font-size:15px">${c.builtAt ? new Date(c.builtAt).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : 'Not yet'}</b></div><div class="kpi"><span>Server load</span><b>${t.load === null ? 'n/a' : t.load}</b></div><div class="kpi"><span>Busy mode now</span><b>${t.busyNow ? 'ON' : 'off'}</b></div></div>
      <section class="panel panel-pad"><h3>Traffic protection</h3>
        <p>Public pages (home, templates, guides, blog, calculators, policies) are stored as ready-made files. A visitor who is not signed in receives them directly from disk — no database and no page assembly — so a sudden rush of visitors barely loads the server. Pages rebuild by themselves when you save content; use the buttons below after uploading a new version of the site.</p>
        <form id="spForm" class="ops-form four">
          <label>Ready-made pages<select name="pageCache"><option value="1" ${c.enabled ? 'selected' : ''}>On (recommended)</option><option value="0" ${c.enabled ? '' : 'selected'}>Off</option></select></label>
          <label>Busy mode<select name="busyMode"><option value="off" ${sel('off', t.mode)}>Off</option><option value="auto" ${sel('auto', t.mode)}>Automatic (when the server is loaded)</option><option value="on" ${sel('on', t.mode)}>On now</option></select></label>
          <label>Automatic: busy above load<input type="number" name="autoLoad" min="0.5" max="50" step="0.1" value="${t.autoLoad}"></label>
          <label>Job-link pasting for customers<select name="jobLinks"><option value="0" ${s.jobLinks ? '' : 'selected'}>Off (customers paste text or upload a PDF)</option><option value="1" ${s.jobLinks ? 'selected' : ''}>On (show the Job link option)</option></select></label>
          <button class="button primary" type="submit">Save</button></form>
        <p class="muted" style="font-size:13px">Busy mode pauses only the work that is heavy for the server — ATS reviews, job-link reading and PDF / Word file creation — and shows customers a polite "try again in a minute" message. Pages, sign-in, payments and saved work keep working.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" id="spRefresh">Clear cache &amp; refresh site</button><button class="button secondary" id="spBuild">Rebuild all pages now</button><button class="button secondary" id="spPurge">Clear ready-made pages</button></div><p role="status" id="spMsg"></p></section>
      <section class="panel panel-pad"><h3>Job link reader (ATS Match)</h3>
        <p>Customers can paste a job-portal link once you switch <b>Job-link pasting</b> on above (it is off by default). Pages that open normally are read directly and cost nothing. Some portals refuse automatic reading (login walls, bot checks, pages built with scripts). To read those, connect a page-rendering service: only the job link is sent to it — never resume content. Until a key is saved, customers are asked to paste the job text or upload a PDF for those links.</p>
        <p class="muted" style="font-size:13px"><b>Do I need a paid plan?</b> Not to start. Leave the reader <b>Off</b>: most company career pages and many job sites open normally and are read for free, and customers can always paste the job text or upload a PDF. Turn it on later only if many customers hit blocked portals. ScrapingBee and ZenRows are paid services (they usually give a small free trial; check their current plans) and bill per page read, so set a daily limit. Sites that need a login (for example some LinkedIn and Naukri pages) may still fail even with a service.</p>
        <form id="jrForm" class="ops-form four">
          <label>Reader<select name="enabled"><option value="false" ${jr.enabled === 'true' ? '' : 'selected'}>Off</option><option value="true" ${jr.enabled === 'true' ? 'selected' : ''}>On</option></select></label>
          <label>Service<select name="provider"><option value="scrapingbee" ${sel('scrapingbee', jr.provider || 'scrapingbee')}>ScrapingBee</option><option value="zenrows" ${sel('zenrows', jr.provider)}>ZenRows</option><option value="custom" ${sel('custom', jr.provider)}>Other (custom address)</option></select></label>
          <label data-for="scrapingbee">ScrapingBee API key<input type="password" name="scrapingbeeApiKey" autocomplete="off" placeholder="${(jr.scrapingbeeApiKey || {}).configured ? 'Saved securely. Leave blank to keep it.' : ''}"></label>
          <label data-for="zenrows">ZenRows API key<input type="password" name="zenrowsApiKey" autocomplete="off" placeholder="${(jr.zenrowsApiKey || {}).configured ? 'Saved securely. Leave blank to keep it.' : ''}"></label>
          <label data-for="custom" class="wide">Custom address (use {url} for the job link and {key} for the key)<input name="customUrlTemplate" value="${E(jr.customUrlTemplate || '')}" placeholder="https://reader.example.com/get?key={key}&url={url}"></label>
          <label data-for="custom">Custom key<input type="password" name="customApiKey" autocomplete="off" placeholder="${(jr.customApiKey || {}).configured ? 'Saved securely. Leave blank to keep it.' : ''}"></label>
          <label>Daily limit (reads)<input type="number" name="dailyLimit" min="1" max="100000" value="${E(jr.dailyLimit || 200)}"></label>
          <button class="button primary" type="submit">Save reader</button></form>
        <p class="muted" style="font-size:13px">Used today: <b>${r.usage.today}</b> of ${r.usage.limit}. The service you choose bills you per read; the daily limit caps the cost. Respect each job site's terms of use.</p>
        <form id="jrTest" class="ops-form" style="display:flex;gap:8px;flex-wrap:wrap"><input type="url" name="url" placeholder="https://… a job link to test" style="flex:1;min-width:240px" required><button class="button secondary" type="submit">Test the reader (uses 1 read)</button></form><p role="status" id="jrMsg"></p></section>`;
    const say = (id, m, bad) => { const el = $(id, h); el.textContent = m; el.style.color = bad ? 'var(--danger)' : ''; };
    const spForm = $('#spForm', h);
    spForm.onsubmit = async e => { e.preventDefault(); const f = e.target; try { await post('/api/admin/speed/save', { pageCache: f.pageCache.value === '1', busyMode: f.busyMode.value, autoLoad: +f.autoLoad.value, jobLinks: f.jobLinks.value === '1' }); toast('Saved'); speed(); } catch (x) { say('#spMsg', err(x), true); } };
    $('#spRefresh', h).onclick = () => document.getElementById('clearCacheBtn')?.click();
    $('#spBuild', h).onclick = async () => { say('#spMsg', 'Building every public page…'); try { const d = await post('/api/admin/speed/rebuild', {}); toast('Pages rebuilt'); say('#spMsg', 'Built ' + d.result.built + ' pages' + (d.result.skipped.length ? '; could not build: ' + d.result.skipped.join(', ') : '') + '.'); speed(); } catch (x) { say('#spMsg', err(x), true); } };
    $('#spPurge', h).onclick = async () => { if (!confirm('Remove the ready-made pages? They are rebuilt automatically as visitors arrive.')) return; try { const d = await post('/api/admin/speed/purge', {}); toast('Cleared ' + d.removed + ' files'); speed(); } catch (x) { say('#spMsg', err(x), true); } };
    const jr$ = $('#jrForm', h), showFor = () => jr$.querySelectorAll('[data-for]').forEach(l => { l.hidden = l.dataset.for !== jr$.provider.value; });
    jr$.provider.onchange = showFor; showFor();
    jr$.onsubmit = async e => { e.preventDefault(); const f = e.target, v = { enabled: f.enabled.value, provider: f.provider.value, customUrlTemplate: f.customUrlTemplate.value.trim(), dailyLimit: String(f.dailyLimit.value || 200) }; for (const k of ['scrapingbeeApiKey', 'zenrowsApiKey', 'customApiKey']) if (f[k].value.trim()) v[k] = f[k].value.trim(); try { await post('/api/admin/providers', { service: 'jobreader', values: v }); toast('Reader saved'); speed(); } catch (x) { say('#jrMsg', err(x), true); } };
    $('#jrTest', h).onsubmit = async e => { e.preventDefault(); say('#jrMsg', 'Asking the reading service…'); try { const d = await post('/api/admin/speed/test-reader', { url: e.target.url.value.trim() }); say('#jrMsg', d.message); } catch (x) { say('#jrMsg', err(x), true); } };
  }

  K.register('speed', speed, ['⚡', 'Speed & traffic', 'Ready-made pages, busy mode and the job link reader.']);

  // A "Clear cache" button in the top bar of every owner page: drops the ready-made pages and rebuilds them, then reloads this page fresh.
  function cacheButton() {
    const bar = document.querySelector('.admin-top .top-actions'); if (!bar || document.getElementById('clearCacheBtn')) return;
    const b = document.createElement('button'); b.type = 'button'; b.id = 'clearCacheBtn'; b.className = 'button secondary'; b.textContent = 'Clear cache'; b.title = 'Clear stored pages and rebuild the site so your latest changes show';
    b.onclick = async () => {
      b.disabled = true; b.textContent = 'Clearing…';
      try { const d = await post('/api/admin/speed/refresh', {}); toast('Cache cleared · ' + d.result.built + ' pages rebuilt'); b.textContent = 'Done ✓'; setTimeout(() => { alert('Cache cleared and ' + d.result.built + ' pages rebuilt.\n\nTo see the change as a visitor: press Ctrl+Shift+R in the browser, or open the site in a private window.\nIf it still looks old after a minute, also clear the Hostinger cache (hPanel > Websites > Dashboard > Cache Manager > Purge all).'); location.reload(); }, 400); }
      catch (x) { toast(err(x)); b.disabled = false; b.textContent = 'Clear cache'; }
    };
    bar.prepend(b);
  }
  cacheButton(); document.addEventListener('DOMContentLoaded', cacheButton); setTimeout(cacheButton, 1200);
})();
