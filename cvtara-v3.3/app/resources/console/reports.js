/* Owner console: Reports & Export (custom report builder) and the table toolkit used by every data table. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const stamp = () => new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');

  /* ---------- file helpers ---------- */
  function saveBlob(blob, name) {
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.append(a); a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }
  const csvText = rows => '﻿' + rows.map(r => r.map(c => { let s = String(c ?? ''); if (/^[=+\-@\t\r]/.test(s) && isNaN(Number(s))) s = "'" + s; return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }).join(',')).join('\r\n');
  const xlsText = rows => '<html><head><meta charset="utf-8"></head><body><table border="1">' + rows.map((r, i) => '<tr>' + r.map(c => `<${i ? 'td' : 'th'}>${E(c)}</${i ? 'td' : 'th'}>`).join('') + '</tr>').join('') + '</table></body></html>';
  function printRows(title, rows) {
    const w = window.open('', '_blank'); if (!w) { toast('Allow pop-ups to print', true); return; }
    w.document.write(`<!doctype html><title>${E(title)}</title><style>body{font:12px system-ui,sans-serif;margin:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #cbd5e1;padding:5px 7px;text-align:left}th{background:#f1f5f9}h1{font-size:16px}</style><h1>${E(title)} · ${new Date().toLocaleString('en-IN')}</h1><table>${rows.map((r, i) => '<tr>' + r.map(c => `<${i ? 'td' : 'th'}>${E(c)}</${i ? 'td' : 'th'}>`).join('') + '</tr>').join('')}</table>`);
    w.document.close(); w.focus(); setTimeout(() => w.print(), 300);
  }
  function exportRows(rows, fmt, name) {
    if (fmt === 'csv') saveBlob(new Blob([csvText(rows)], { type: 'text/csv;charset=utf-8' }), name + '.csv');
    else if (fmt === 'xls') saveBlob(new Blob([xlsText(rows)], { type: 'application/vnd.ms-excel' }), name + '.xls');
    else if (fmt === 'json') { const [h, ...b] = rows; saveBlob(new Blob([JSON.stringify(b.map(r => Object.fromEntries(h.map((k, i) => [k, r[i]]))), null, 2)], { type: 'application/json' }), name + '.json'); }
    else if (fmt === 'print') printRows(name, rows);
    else if (fmt === 'copy') (navigator.clipboard ? navigator.clipboard.writeText(rows.map(r => r.join('\t')).join('\n')) : Promise.reject()).then(() => toast('Copied to clipboard'), () => toast('Copy is blocked in this browser', true));
  }
  /* Server-side export of a whole filtered data set (not just the rows on screen). */
  async function exportReport(params, fmt) {
    const qs = new URLSearchParams({ ...params, format: fmt === 'print' ? 'json' : fmt }); qs.delete('page');
    if (fmt === 'print' || fmt === 'copy') { const d = await api('/api/admin/report?' + qs); exportRows([d.columns.map(c => c.label), ...d.rows.map(r => d.columns.map(c => r[c.key]))], fmt, 'Report'); return; }
    const res = await fetch('/api/admin/report?' + qs, { credentials: 'same-origin' });
    if (!res.ok) { let m = 'Export failed.'; try { m = (await res.json()).error || m; } catch (e) { /* not JSON */ } throw new Error(m); }
    const cd = res.headers.get('Content-Disposition') || '', m = /filename="?([^";]+)/.exec(cd);
    saveBlob(await res.blob(), m ? m[1] : `cvtara-report-${stamp()}.${fmt}`);
  }
  K.exportReport = exportReport; K.exportRows = exportRows;

  /* ---------- table toolkit: search, sort, columns and export on every data table ---------- */
  const cellText = c => (c.innerText || c.textContent || '').replace(/\s+/g, ' ').trim();
  const num = s => { const t = s.replace(/[₹,\s]/g, '').replace(/^ORD-/i, ''); return /^-?\d+(\.\d+)?[kKlL]?$/.test(t) ? parseFloat(t) * (/k$/i.test(t) ? 1e3 : /l$/i.test(t) ? 1e5 : 1) : NaN; };
  function enhance(table) {
    if (table.dataset.kit || table.closest('[data-nokit]') || table.closest('.ad-modal')) return;
    const head = table.tHead && table.tHead.rows[0]; if (!head || head.cells.length < 3) return;
    table.dataset.kit = '1';
    const host = table.closest('section.panel') || table.parentElement;
    const cols = [...head.cells].map((th, i) => ({ i, label: cellText(th), on: true, skip: /^(actions?|)$/i.test(cellText(th)) }));
    const st = { q: '', sort: -1, dir: 1, busy: false };
    const bar = document.createElement('div'); bar.className = 'kit-bar';
    bar.innerHTML = `<input type="search" placeholder="Filter rows…" aria-label="Filter rows in this table"><span class="kit-count" role="status"></span>
      <span class="kit-menu"><button type="button" class="button secondary small" data-k="cols">Columns ▾</button><div class="kit-pop" hidden></div></span>
      <span class="kit-menu"><button type="button" class="button primary small" data-k="exp">Export ▾</button><div class="kit-pop" hidden>
        <button type="button" data-f="csv">CSV (Excel-ready)</button><button type="button" data-f="xls">Excel (.xls)</button><button type="button" data-f="json">JSON</button><button type="button" data-f="print">Print / Save as PDF</button><button type="button" data-f="copy">Copy to clipboard</button></div></span>`;
    host.insertBefore(bar, host.firstChild);
    const pops = $$('.kit-pop', bar);
    $('[data-k=cols]', bar).nextElementSibling.innerHTML = cols.filter(c => c.label).map(c => `<label><input type="checkbox" data-c="${c.i}" checked> ${E(c.label)}</label>`).join('');
    const rowsEls = () => [...(table.tBodies[0] ? table.tBodies[0].rows : [])].filter(r => !(r.cells.length === 1 && r.cells[0].colSpan > 1));
    function apply() {
      if (st.busy) return; st.busy = true;
      const body = table.tBodies[0]; let rows = rowsEls();
      if (st.sort >= 0) {
        const k = st.sort; rows.sort((a, b) => { const x = cellText(a.cells[k] || {}), y = cellText(b.cells[k] || {}), nx = num(x), ny = num(y); const c = !isNaN(nx) && !isNaN(ny) ? nx - ny : (Date.parse(x) && Date.parse(y) && /\d{4}/.test(x + y) ? Date.parse(x) - Date.parse(y) : x.localeCompare(y, undefined, { numeric: true })); return c * st.dir; });
        rows.forEach(r => body.append(r));
      }
      const q = st.q.toLowerCase(); let shown = 0;
      rows.forEach(r => { const hit = !q || cellText(r).toLowerCase().includes(q); r.hidden = !hit; if (hit) shown++; });
      cols.forEach(c => { [...table.rows].forEach(r => { const cell = r.cells[c.i]; if (cell && r.cells.length > 1) cell.style.display = c.on ? '' : 'none'; }); });
      $('.kit-count', bar).textContent = rows.length ? `${shown} of ${rows.length} rows` : '';
      $$('th', head).forEach((th, i) => th.setAttribute('aria-sort', i === st.sort ? (st.dir > 0 ? 'ascending' : 'descending') : 'none'));
      st.busy = false;
    }
    const data = () => { const use = cols.filter(c => c.on && !c.skip && c.label); return [use.map(c => c.label), ...rowsEls().filter(r => !r.hidden).map(r => use.map(c => cellText(r.cells[c.i] || {})))]; };
    $('input[type=search]', bar).oninput = e => { st.q = e.target.value; apply(); };
    bar.onclick = e => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.k) { const pop = b.nextElementSibling; pops.forEach(p => { if (p !== pop) p.hidden = true; }); pop.hidden = !pop.hidden; return; }
      if (b.dataset.f) { pops.forEach(p => { p.hidden = true; }); const title = ($('#adminTitle') || {}).textContent || 'CVTara'; exportRows(data(), b.dataset.f, `cvtara-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${stamp()}`); }
    };
    bar.onchange = e => { const c = e.target.dataset.c; if (c !== undefined) { cols[Number(c)].on = e.target.checked; apply(); } };
    document.addEventListener('click', e => { if (!bar.contains(e.target)) pops.forEach(p => { p.hidden = true; }); });
    [...head.cells].forEach((th, i) => { if (cols[i].skip || !cols[i].label) return; th.classList.add('kit-sort'); th.tabIndex = 0; const go = () => { st.dir = st.sort === i ? -st.dir : 1; st.sort = i; apply(); }; th.onclick = go; th.onkeydown = e => { if (e.key === 'Enter') go(); }; });
    if (table.tBodies[0]) new MutationObserver(() => { if (!st.busy) apply(); }).observe(table.tBodies[0], { childList: true });
    apply();
  }
  const scan = () => $$('.admin-view table.ad-table').forEach(enhance);
  new MutationObserver(() => { clearTimeout(scan.t); scan.t = setTimeout(scan, 120); }).observe(document.body, { childList: true, subtree: true });
  scan();

  /* ---------- Reports & Export view ---------- */
  const STATUS = { orders: ['paid', 'pending', 'created', 'refunded', 'partially_refunded', 'disputed'], users: ['paid', 'normal', 'blocked', 'owner'], withdrawals: ['requested', 'processing', 'paid', 'rejected'], errors: ['open', 'error', 'warning', 'info'], mail: ['queued', 'sent', 'failed'], scans: ['done', 'void', 'reserved'], commissions: [] };
  const STATUS_LABEL = { refunded: 'Reversed by bank', partially_refunded: 'Partly reversed', normal: 'Normal users', paid: 'Paid', open: 'Open (unresolved)' };
  const PRESETS = 'cvtara-report-presets';
  const loadPresets = () => { try { return JSON.parse(localStorage.getItem(PRESETS) || '[]'); } catch (e) { return []; } };
  const savePresets = p => { try { localStorage.setItem(PRESETS, JSON.stringify(p.slice(0, 20))); } catch (e) { /* storage blocked */ } };
  const R = { dataset: 'orders', q: '', status: 'all', plan: 'all', provider: 'all', from: '', to: '', min: '', max: '', live: '', columns: null, sort: '', dir: 'desc', page: 1, per: 50 };
  let META = null;

  async function reports() {
    const h = $('#admin-reports'); if (!h) return;
    if (!META) { try { META = (await api('/api/admin/report/meta')).datasets; } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; } }
    const m = META[R.dataset], cols = R.columns && R.columns.dataset === R.dataset ? R.columns.keys : m.defaults, has = f => m.filters.includes(f);
    const opt = (v, l, cur) => `<option value="${E(v)}" ${cur === v ? 'selected' : ''}>${E(l)}</option>`;
    h.innerHTML = `<div class="pg-head"><h2>Reports & Export</h2><p>Build a custom report from any data set, filter it, choose the columns, then export the full result as CSV, Excel, JSON or print it.</p></div>
      <section class="panel panel-pad" data-nokit>
        <div class="rp-tabs" role="tablist">${Object.entries(META).map(([k, d]) => `<button type="button" role="tab" class="${k === R.dataset ? 'active' : ''}" data-ds="${k}">${E(d.label)}</button>`).join('')}</div>
        <div class="ops-filterbox rp-filters">
          ${has('q') ? `<label class="ops-f grow">Search<input id="rq" type="search" value="${E(R.q)}" placeholder="Name, email, reference…"></label>` : ''}
          ${STATUS[R.dataset].length ? `<label class="ops-f">Status<select id="rs"><option value="all">All</option>${STATUS[R.dataset].map(s => opt(s, STATUS_LABEL[s] || s[0].toUpperCase() + s.slice(1).replace(/_/g, ' '), R.status)).join('')}</select></label>` : ''}
          ${has('plan') ? `<label class="ops-f">Plan<input id="rpl" value="${E(R.plan === 'all' ? '' : R.plan)}" placeholder="e.g. job-hunt" list="rpplans"><datalist id="rpplans">${['quick', 'job-hunt', 'pro', 'complete-kit', 'expert-review', 'profile-boost', 'fresher-basic', 'fresher-standard', 'fresher-pro', 'pro-basic', 'pro-standard', 'pro-pro', 'addon-10', 'addon-20', 'addon-30'].map(p => `<option value="${p}">`).join('')}</datalist></label>` : ''}
          ${has('provider') ? `<label class="ops-f">Payment source<select id="rpv"><option value="all">All</option>${['razorpay', 'manual', 'referral_reward', 'demo'].map(p => opt(p, p === 'manual' ? 'Recorded by owner' : p[0].toUpperCase() + p.slice(1).replace('_', ' '), R.provider)).join('')}</select></label>` : ''}
          <label class="ops-f">From<input id="rf" type="date" value="${R.from}"></label><label class="ops-f">To<input id="rt" type="date" value="${R.to}"></label>
          ${has('min') ? `<label class="ops-f">Min ₹<input id="rmin" type="number" min="0" value="${R.min}" style="width:90px"></label><label class="ops-f">Max ₹<input id="rmax" type="number" min="0" value="${R.max}" style="width:90px"></label>` : ''}
          ${has('live') ? `<label class="ops-f ops-check"><input id="rlive" type="checkbox" ${R.live ? 'checked' : ''}> Real money only</label>` : ''}
        </div>
        <details class="rp-cols" open><summary>Columns (${cols.length} of ${m.columns.length})</summary><div class="rp-colgrid">${m.columns.map(c => `<label><input type="checkbox" data-col="${c.key}" ${cols.includes(c.key) ? 'checked' : ''}> ${E(c.label)}</label>`).join('')}</div>
          <p><button type="button" class="linklike" id="rall">Select all</button> · <button type="button" class="linklike" id="rdef">Reset to default</button></p></details>
        <div class="rp-actions"><label class="ops-f">Sort by<select id="rsort">${opt('', 'Newest first', R.sort)}${m.columns.map(c => opt(c.key, c.label, R.sort)).join('')}</select></label>
          <label class="ops-f">Order<select id="rdir">${opt('desc', 'Descending', R.dir)}${opt('asc', 'Ascending', R.dir)}</select></label>
          <label class="ops-f">Rows per page<select id="rper">${[25, 50, 100, 250].map(n => opt(String(n), String(n), String(R.per))).join('')}</select></label>
          <span class="rp-btns"><button type="button" class="button primary" id="rrun">Run report</button>
            <span class="kit-menu"><button type="button" class="button secondary" data-k="exp">Export all rows ▾</button><div class="kit-pop" hidden><button type="button" data-xf="csv">CSV (Excel-ready)</button><button type="button" data-xf="xls">Excel (.xls)</button><button type="button" data-xf="json">JSON</button><button type="button" data-xf="print">Print / Save as PDF</button></div></span>
            <button type="button" class="button secondary" id="rsave">Save this report</button></span></div>
        <div id="rpresets" class="rp-presets"></div></section>
      <div class="ops-kpis" id="rkpi"></div>
      <section class="panel" data-nokit style="overflow:auto"><table class="ad-table" id="rtable"><tbody><tr><td>Choose filters and press Run report.</td></tr></tbody></table></section><div class="ops-pager" id="rpager"></div>`;
    wire(h); presets(h); run(h);
  }
  const params = () => {
    const p = { dataset: R.dataset, q: R.q, status: R.status, plan: R.plan, provider: R.provider, from: R.from, to: R.to, min: R.min, max: R.max, live: R.live ? '1' : '', sort: R.sort, dir: R.dir, per: String(R.per), page: String(R.page) };
    const keys = (R.columns && R.columns.dataset === R.dataset ? R.columns.keys : META[R.dataset].defaults); p.columns = keys.join(',');
    Object.keys(p).forEach(k => { if (p[k] === '' || p[k] === 'all') delete p[k]; }); return p;
  };
  function wire(h) {
    const set = (id, k, ev = 'change') => { const el = $('#' + id, h); if (el) el.addEventListener(ev, e => { R[k] = e.target.type === 'checkbox' ? e.target.checked : e.target.value; R.page = 1; if (ev === 'input') { clearTimeout(wire.t); wire.t = setTimeout(() => run(h), 350); } else run(h); }); };
    set('rq', 'q', 'input'); set('rs', 'status'); set('rpv', 'provider'); set('rf', 'from'); set('rt', 'to'); set('rmin', 'min', 'input'); set('rmax', 'max', 'input'); set('rlive', 'live'); set('rsort', 'sort'); set('rdir', 'dir'); set('rper', 'per');
    const pl = $('#rpl', h); if (pl) pl.addEventListener('change', e => { R.plan = e.target.value.trim() || 'all'; R.page = 1; run(h); });
    h.onclick = e => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.ds) { R.dataset = b.dataset.ds; Object.assign(R, { q: '', status: 'all', plan: 'all', provider: 'all', min: '', max: '', live: '', sort: '', page: 1 }); reports(); }
      else if (b.dataset.k) { const pop = b.nextElementSibling; pop.hidden = !pop.hidden; }
      else if (b.dataset.xf) { b.closest('.kit-pop').hidden = true; toast('Preparing export…'); exportReport(params(), b.dataset.xf).then(() => toast('Export ready'), x => toast(err(x), true)); }
      else if (b.id === 'rrun') run(h);
      else if (b.id === 'rall') { R.columns = { dataset: R.dataset, keys: META[R.dataset].columns.map(c => c.key) }; reports(); }
      else if (b.id === 'rdef') { R.columns = null; reports(); }
      else if (b.id === 'rsave') { const name = prompt('Name this report'); if (!name) return; const p = loadPresets().filter(x => x.name !== name); p.unshift({ name, R: { ...R, columns: R.columns, page: 1 } }); savePresets(p); presets(h); toast('Saved'); }
      else if (b.dataset.pset !== undefined) { const x = loadPresets()[Number(b.dataset.pset)]; if (x) { Object.assign(R, x.R); reports(); } }
      else if (b.dataset.pdel !== undefined) { const p = loadPresets(); p.splice(Number(b.dataset.pdel), 1); savePresets(p); presets(h); }
      else if (b.dataset.pg) { R.page = Number(b.dataset.pg); run(h); }
    };
    h.onchange = e => { const c = e.target.dataset.col; if (c) { const cur = (R.columns && R.columns.dataset === R.dataset ? R.columns.keys : META[R.dataset].defaults).slice(); const i = cur.indexOf(c); e.target.checked ? i < 0 && cur.push(c) : i >= 0 && cur.splice(i, 1); R.columns = { dataset: R.dataset, keys: META[R.dataset].columns.map(x => x.key).filter(k => cur.includes(k)) }; $('summary', h).textContent = `Columns (${R.columns.keys.length} of ${META[R.dataset].columns.length})`; run(h); } };
  }
  function presets(h) {
    const p = loadPresets(), box = $('#rpresets', h); if (!box) return;
    box.innerHTML = p.length ? '<b>Saved reports:</b> ' + p.map((x, i) => `<span class="rp-chip"><button type="button" data-pset="${i}">${E(x.name)}</button><button type="button" data-pdel="${i}" aria-label="Delete ${E(x.name)}">×</button></span>`).join('') : '';
  }
  async function run(h) {
    const tbl = $('#rtable', h); if (!tbl) return;
    tbl.innerHTML = '<tbody><tr><td>Running…</td></tr></tbody>';
    try {
      const d = await api('/api/admin/report?' + new URLSearchParams({ ...params(), format: 'json' }));
      const money = k => /_inr$/.test(k);
      tbl.innerHTML = `<thead><tr>${d.columns.map(c => `<th${c.num ? ' class="r"' : ''}>${E(c.label)}</th>`).join('')}</tr></thead><tbody>${d.rows.map(r => `<tr>${d.columns.map(c => `<td${c.num ? ' class="r"' : ''}>${money(c.key) ? '₹' + Number(r[c.key] ?? 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) : E(r[c.key] ?? '')}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="${d.columns.length || 1}">No rows match these filters.</td></tr>`}</tbody>`;
      const pages = Math.max(1, Math.ceil(d.total / d.per));
      $('#rpager', h).innerHTML = d.total ? `<span>${d.total.toLocaleString('en-IN')} rows · page ${d.page} of ${pages}</span> <button class="button secondary small" data-pg="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>← Prev</button> <button class="button secondary small" data-pg="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>Next →</button>` : '';
      const s = d.sums, f = n => '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 });
      $('#rkpi', h).innerHTML = `<div class="kpi"><span>Rows</span><b>${d.total.toLocaleString('en-IN')}</b></div>` + (s ? `<div class="kpi"><span>Billed</span><b>${f(s.amount_inr)}</b></div><div class="kpi"><span>Reversed by bank</span><b>${f(s.reversed_inr)}</b></div><div class="kpi"><span>Net</span><b>${f(s.net_inr)}</b></div>` : '');
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }
  K.openReport = (preset) => { Object.assign(R, { q: '', status: 'all', plan: 'all', provider: 'all', from: '', to: '', min: '', max: '', live: '', sort: '', page: 1, columns: null }, preset || {}); K.open('reports'); const b = $('.side-nav [data-admin-view="reports"]'); if (b) b.click(); };
  K.register('reports', reports, ['📊', 'Reports & Export', 'Custom reports on orders, users, referral commissions, withdrawals, ATS checks, errors and email — filter, choose columns and export.']);
})();
