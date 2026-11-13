// Shared helpers for the live-site QA personas. Each persona acts like one real person (or the owner) on the site,
// paced so ten of them together stay gentle on the host. Results go to results/<persona>.json.
const { chromium, devices } = require('playwright');
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const BASE = (process.env.BASE || 'https://cvtara.com').replace(/\/$/, '');
const OUT = process.env.QA_OUT || path.join(__dirname, 'results');
const ACCOUNTS = process.env.QA_ACCOUNTS || path.join(__dirname, '.accounts.json');
// Answers that are normal for the situation (reported once in the summary, not per step).
const EXPECTED = /^404 GET \/api\/partner$/;
const PACE = +(process.env.QA_PACE_MS || 1800);
fs.mkdirSync(path.join(OUT, 'shots'), { recursive: true });
const sleep = ms => new Promise(r => setTimeout(r, ms));
// Customer-facing pages must not mention AI or model/vendor names (owner rule, 2026-10-23/24).
const BANNED = /\b(AI|A\.I\.|artificial intelligence|Claude|Anthropic|ChatGPT|GPT-?\d?|OpenAI|Gemini|LLM|language model|Opus|Sonnet|Haiku)\b/;
function accounts() { return JSON.parse(fs.readFileSync(ACCOUNTS, 'utf8')); }
function totp(secret, t = Date.now()) {
  const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = '';
  for (const c of secret.toUpperCase().replace(/[^A-Z2-7]/g, '')) bits += B32.indexOf(c).toString(2).padStart(5, '0');
  const key = Buffer.from(bits.match(/.{8}/g).map(b => parseInt(b, 2)));
  const msg = Buffer.alloc(8); msg.writeBigUInt64BE(BigInt(Math.floor(t / 30000)));
  const h = crypto.createHmac('sha1', key).update(msg).digest(); const o = h[19] & 15;
  return String(((h[o] & 127) << 24 | h[o + 1] << 16 | h[o + 2] << 8 | h[o + 3]) % 1e6).padStart(6, '0');
}
class Persona {
  constructor(id, title) { this.id = id; this.title = title; this.results = []; this.noise = []; this.cur = null; this.started = new Date().toISOString(); }
  async open({ mobile = false } = {}) {
    this.browser = await chromium.launch();
    this.ctx = await this.browser.newContext({ ...(mobile ? devices['iPhone 13'] : { viewport: { width: 1366, height: 860 } }), baseURL: BASE, acceptDownloads: true });
    // Mark every QA request so the owner can tell test traffic apart in logs (a header would break font CORS).
    const ua = await (await this.ctx.newPage()).evaluate(() => navigator.userAgent); await this.ctx.pages()[0].close();
    await this.ctx.setExtraHTTPHeaders({}); this.ua = ua + ' CVTaraLiveQA/1.0';
    await this.ctx.route('**/*', (route, req) => req.url().startsWith(BASE) ? route.continue({ headers: { ...req.headers(), 'user-agent': this.ua } }) : route.continue());
    this.ctx.setDefaultTimeout(25000);
    this.page = await this.ctx.newPage(); this.watch(this.page); return this.page;
  }
  watch(page) {
    const push = (kind, text) => { const e = { kind, text: String(text).slice(0, 400), url: page.url() }; (this.cur ? this.cur.errors : this.noise).push(e); };
    page.on('console', m => { if (m.type() === 'error' && !/favicon|Failed to load resource: the server responded with a status of/.test(m.text())) push('console', m.text()); });
    page.on('pageerror', e => push('js-exception', e.message));
    page.on('response', r => { const u = r.url(); if (u.startsWith(BASE) && r.status() >= 400 && !EXPECTED.test(`${r.status()} ${r.request().method()} ${u.replace(BASE, '')}`) && !(this.cur && this.cur.expect && this.cur.expect.test(`${r.status()} ${u}`))) push('http', `${r.status()} ${r.request().method()} ${u.replace(BASE, '')}`); });
    page.on('requestfailed', r => { const u = r.url(); if (u.startsWith(BASE) && !/aborted|cancel/i.test(r.failure()?.errorText || '')) push('network', `${r.failure()?.errorText} ${u.replace(BASE, '')}`); });
  }
  // area: 'page' or 'function'; name: the page path or function name shown in the report.
  async step(area, name, fn, { expect = null, critical = false } = {}) {
    const rec = { persona: this.id, area, name, status: 'pass', notes: [], errors: [], ms: 0, expect };
    this.cur = rec; const t = Date.now();
    try { const r = await fn(rec); if (r === 'skip') rec.status = 'skip'; }
    catch (e) { rec.status = 'fail'; rec.notes.push((e && e.message || String(e)).split('\n')[0].slice(0, 300)); }
    await sleep(400);
    rec.ms = Date.now() - t; this.cur = null; delete rec.expect;
    if (rec.status === 'pass' && rec.errors.some(e => e.kind !== 'http' || /^5\d\d/.test(e.text))) rec.status = 'fail';
    else if (rec.status === 'pass' && (rec.errors.length || rec.warn)) rec.status = 'warn';
    if (rec.status === 'fail' || rec.status === 'warn') rec.shot = await this.shot(name).catch(() => null);
    this.results.push(rec); this.save();
    console.log(`[${this.id}] ${rec.status.toUpperCase().padEnd(4)} ${area}: ${name}${rec.notes.length ? ' :: ' + rec.notes.join(' | ') : ''}`);
    await sleep(PACE);
    if (critical && rec.status === 'fail') throw new Error(`critical step failed: ${name}`);
    return rec;
  }
  async shot(name) { if (!this.page || this.page.isClosed()) return null; const f = `shots/${this.id}-${name.replace(/[^a-z0-9]+/gi, '_').slice(0, 60)}.png`; await this.page.screenshot({ path: path.join(OUT, f), fullPage: false }); return f; }
  warn(rec, msg) { rec.warn = true; rec.notes.push(msg); }
  save() { fs.writeFileSync(path.join(OUT, `${this.id}.json`), JSON.stringify({ id: this.id, title: this.title, started: this.started, ended: new Date().toISOString(), results: this.results, noise: this.noise.slice(0, 200) }, null, 1)); }
  async close() { this.save(); await this.browser?.close().catch(() => {}); }
  // ---- common checks ----
  async goto(p, opts = {}) { const r = await this.page.goto(p, { waitUntil: 'domcontentloaded', ...opts }); await this.page.waitForLoadState('load', { timeout: 15000 }).catch(() => {}); await sleep(900); return r; }
  async pageChecks(rec, { customer = true } = {}) {
    const info = await this.page.evaluate(() => {
      const body = document.body ? document.body.innerText : '';
      return { title: document.title, h1: [...document.querySelectorAll('h1')].map(h => h.innerText.trim()).filter(Boolean).length, desc: !!document.querySelector('meta[name=description]')?.content, canonical: document.querySelector('link[rel=canonical]')?.href || null,
        overflow: document.documentElement.scrollWidth - window.innerWidth, brokenImgs: [...document.images].filter(i => i.complete && i.naturalWidth === 0 && i.src && !i.loading?.includes('lazy')).map(i => i.src).slice(0, 5),
        text: body.slice(0, 200000), empty: body.trim().length < 80, links: [...document.querySelectorAll('a[href]')].map(a => a.href) };
    });
    if (!info.title) this.warn(rec, 'no <title>');
    if (info.empty) { rec.status = 'fail'; rec.notes.push('page is blank (under 80 characters of text)'); }
    if (info.overflow > 4) this.warn(rec, `sideways scroll ${info.overflow}px`);
    if (info.brokenImgs.length) this.warn(rec, `broken images: ${info.brokenImgs.join(', ')}`);
    if (customer) { const m = info.text.match(BANNED); if (m) { const i = info.text.indexOf(m[0]); this.warn(rec, `banned wording "${m[0]}": …${info.text.slice(Math.max(0, i - 50), i + 50).replace(/\s+/g, ' ')}…`); } }
    if (/Whoops|Server Error|SQLSTATE|Stack trace|Undefined (index|variable|array key)|ErrorException|\bNaN\b|undefined<|\[object Object\]/.test(info.text)) { rec.status = 'fail'; rec.notes.push('error text visible on the page'); }
    return info;
  }
  async login(email, password) {
    await this.goto('/login');
    await this.page.fill('input[name=email]', email); await this.page.fill('input[name=password]', password);
    await Promise.all([this.page.waitForNavigation({ waitUntil: 'domcontentloaded' }).catch(() => {}), this.page.click('button.au-go')]);
    await sleep(1500);
    if (/\/login/.test(this.page.url())) throw new Error('sign-in did not leave the login page: ' + (await this.page.locator('.error, .alert, [role=alert]').allInnerTexts().catch(() => [])).join(' '));
  }
  // Calls the API and fails the step unless the status is one of `ok`. Returns the JSON body.
  async call(rec, method, p, body, ok = [200]) {
    const r = await this.api(method, p, body);
    rec && rec.notes.length < 6 && !ok.includes(r.status) && rec.notes.push(`${method} /api/${p} → ${r.status} ${JSON.stringify(r.json || '').slice(0, 160)}`);
    if (!ok.includes(r.status)) throw new Error(`${method} /api/${p} answered ${r.status}: ${JSON.stringify(r.json || '').slice(0, 200)}`);
    return r.json || {};
  }
  // Opens one workspace view from the sidebar/menu and checks it rendered.
  async wsView(rec, view) {
    if (!/workspace\.html/.test(this.page.url())) await this.goto('/workspace.html');
    const clicked = await this.page.evaluate(v => { const els = [...document.querySelectorAll(`[data-workspace-view="${v}"]`)]; const el = els.find(e => e.offsetParent) || els[0]; if (!el) return false; el.click(); return true; }, view);
    if (!clicked) throw new Error(`no menu item for "${view}"`);
    await sleep(2200);
    return this.pageChecks(rec, { customer: true });
  }
  // JSON API call inside the signed-in browser (uses the page's cookies and CSRF token).
  async api(method, p, body) {
    return this.page.evaluate(async ([method, p, body]) => {
      const t = (await (await fetch('/api/csrf', { credentials: 'same-origin' })).json()).token;
      const r = await fetch('/api/' + p, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': t, 'X-Requested-With': 'XMLHttpRequest' }, body: body ? JSON.stringify(body) : undefined });
      let j = null; try { j = await r.json(); } catch { } return { status: r.status, json: j };
    }, [method, p, body || null]);
  }
}
async function run(id, title, fn, opts) {
  const p = new Persona(id, title);
  try { await p.open(opts); await fn(p); } catch (e) { console.log(`[${id}] STOPPED: ${e.message}`); p.results.push({ persona: id, area: 'function', name: 'persona run', status: 'fail', notes: ['stopped early: ' + e.message.slice(0, 300)], errors: [] }); }
  finally { await p.close(); }
}
module.exports = { BASE, OUT, PACE, BANNED, sleep, accounts, totp, Persona, run };
