// Persona 1 + 2: a visitor reading every public page (sitemap split in two halves: desktop / phone).
const { run, BASE, sleep } = require('../lib.cjs');
const half = +(process.env.HALF || 0), mobile = half === 1, id = mobile ? 'p02-public-phone' : 'p01-public-desktop';
run(id, mobile ? 'Visitor on a phone: second half of all public pages, redirects, 404, robots' : 'Visitor on a laptop: first half of all public pages + link check', async p => {
  const xml = await (await fetch(BASE + '/sitemap.xml')).text();
  let urls = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1].replace(BASE, '') || '/');
  const extra = ['/', '/login', '/register', '/forgot-password', '/checkout.html', '/refund.html', '/customer-guide.html', '/pricing/', '/partners.html', '/contact.html'];
  urls = [...new Set([...extra, ...urls])];
  if (process.env.QA_LIMIT) urls = urls.slice(0, +process.env.QA_LIMIT * 2);
  const mine = urls.filter((_, i) => i % 2 === half);
  const links = new Set();
  for (const u of mine) {
    await p.step('page', u, async rec => {
      const r = await p.goto(u);
      if (!r) throw new Error('no response');
      if (r.status() >= 400) throw new Error(`HTTP ${r.status()}`);
      const info = await p.pageChecks(rec, { customer: true });
      if (!/^\/(login|register|forgot-password|checkout)/.test(u)) { if (!info.h1) p.warn(rec, 'no H1 heading'); if (!info.desc) p.warn(rec, 'no meta description'); }
      if (info.canonical && !info.canonical.startsWith(BASE)) p.warn(rec, 'canonical points off-site: ' + info.canonical);
      info.links.filter(h => h.startsWith(BASE)).forEach(h => links.add(h.split('#')[0]));
    });
  }
  if (!mobile) {
    // Link check: every internal link seen on these pages that is not in the sitemap, fetched once.
    const known = new Set(urls.map(u => BASE + u));
    const todo = [...links].filter(l => !known.has(l) && !/\/(logout|r\/|p\/|go\/|email\/|api\/)/.test(l)).slice(0, 400);
    await p.step('function', `internal links not in sitemap (${todo.length})`, async rec => {
      const bad = [];
      for (const l of todo) { const r = await fetch(l, { redirect: 'follow' }).catch(e => ({ status: 'ERR ' + e.message })); if (!(r.status < 400)) bad.push(`${r.status} ${l.replace(BASE, '')}`); await sleep(350); }
      if (bad.length) { rec.status = 'fail'; rec.notes.push(`${bad.length} broken: ` + bad.slice(0, 25).join(', ')); }
    });
  } else {
    const checks = [['/pricing', 301, '/pricing/'], ['/pricing.html', 301, '/pricing/'], ['/plans', 301, '/pricing/'], ['/this-page-does-not-exist-qa', 404], ['/robots.txt', 200], ['/sitemap.xml', 200], ['/llms.txt', 200], ['/site.webmanifest', 200], ['/r/not-a-real-code-qa', 404], ['/p/not-a-real-link-qa', 404]];
    for (const [u, code, to] of checks) await p.step('function', `status ${u} → ${code}${to ? ' ' + to : ''}`, async () => {
      const r = await fetch(BASE + u, { redirect: 'manual' });
      if (r.status !== code) throw new Error(`got ${r.status}`);
      if (to && !(r.headers.get('location') || '').replace(BASE, '').startsWith(to)) throw new Error('redirects to ' + r.headers.get('location'));
      await sleep(400);
    });
    await p.step('page', '404 page looks friendly', async rec => { const r = await p.goto('/this-page-does-not-exist-qa'); await p.pageChecks(rec); if (r.status() !== 404) throw new Error('status ' + r.status()); }, { expect: /^404/ });
  }
}, { mobile });
