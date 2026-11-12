// Careful capacity check for the live site. Steps the number of simultaneous visitors up (each one requests pages
// back to back, keeping its own session cookie) and STOPS at the first sign of strain: any 5xx/busy page, >2% errors,
// or a 95th-percentile answer slower than 3 s. Writes results/capacity.json. Never goes above QA_CAP_MAX workers.
const fs = require('fs'), path = require('path');
const BASE = (process.env.BASE || 'https://cvtara.com').replace(/\/$/, ''); const OUT = process.env.QA_OUT || path.join(__dirname, 'results');
const STEPS = (process.env.QA_CAP_STEPS || '2,5,10,20,30,40').split(',').map(Number).filter(n => n <= +(process.env.QA_CAP_MAX || 40));
const SECONDS = +(process.env.QA_CAP_SECONDS || 20);
const TARGETS = { 'ready-made page (home)': '/', 'SEO page': '/pricing/', 'app page run by PHP (sign-in)': '/login' };
const UA = 'Mozilla/5.0 CVTaraLiveQA/1.0 capacity-check';
async function worker(url, until, out) {
  let cookie = '';
  while (Date.now() < until) {
    const t = Date.now(); let status = 0;
    try { const r = await fetch(url, { headers: { 'User-Agent': UA, Cookie: cookie }, redirect: 'manual' }); status = r.status; const sc = r.headers.getSetCookie?.() || []; if (sc.length) cookie = sc.map(c => c.split(';')[0]).join('; '); await r.arrayBuffer(); }
    catch { status = -1; }
    out.push([Date.now() - t, status]);
  }
}
const pct = (a, p) => a.length ? a.slice().sort((x, y) => x - y)[Math.min(a.length - 1, Math.floor(a.length * p))] : null;
(async () => {
  fs.mkdirSync(OUT, { recursive: true }); const res = { base: BASE, at: new Date().toISOString(), seconds: SECONDS, targets: {} };
  for (const [name, p] of Object.entries(TARGETS)) {
    res.targets[name] = [];
    for (const n of STEPS) {
      const out = []; const until = Date.now() + SECONDS * 1000; await Promise.all(Array.from({ length: n }, () => worker(BASE + p, until, out)));
      const ms = out.map(x => x[0]); const bad = out.filter(x => x[1] < 0 || x[1] >= 500 || x[1] === 429).length;
      const row = { users: n, requests: out.length, perSecond: +(out.length / SECONDS).toFixed(1), p50: pct(ms, .5), p95: pct(ms, .95), max: Math.max(...ms), errors: bad, errorRate: +(bad / Math.max(1, out.length) * 100).toFixed(2), codes: out.reduce((m, x) => (m[x[1]] = (m[x[1]] || 0) + 1, m), {}) };
      res.targets[name].push(row); console.log(name, JSON.stringify(row)); fs.writeFileSync(path.join(OUT, 'capacity.json'), JSON.stringify(res, null, 1));
      if (row.errorRate > 2 || row.p95 > 3000) { row.stoppedHere = true; console.log('  strain seen, not going higher for', name); break; }
      await new Promise(r => setTimeout(r, 15000)); // let the host recover between steps
    }
  }
})();
