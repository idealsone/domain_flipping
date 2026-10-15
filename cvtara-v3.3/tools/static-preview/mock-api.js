/* CVTara browser-only demo backend. Replaces /api/* with data kept in this browser (localStorage). */
(function () {
  if (window.__cvm) return;
  const M = window.__cvm = {};
  const BUILD = '__BUILD__'; const SNAP = window.__SNAP, PAGES = window.__PAGES, KEY = 'cvtara_demo_v1';
  let st;
  try { st = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { st = null; }
  if (!st) st = { role: null, over: {}, seq: 0 };
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} };
  M.reset = () => { st = { role: null, over: {}, seq: 0 }; save(); };
  M.signIn = r => { st.role = r; save(); };
  const clone = o => JSON.parse(JSON.stringify(o));
  const now = () => new Date().toISOString().slice(0, 19).replace('T', ' ');
  const uid = () => 'demo-' + Date.now().toString(36) + (++st.seq);
  const ok = (data, status) => new Response(JSON.stringify(Object.assign({ ok: true }, data || {})), { status: status || 200, headers: { 'Content-Type': 'application/json' } });
  const fail = (msg, status, extra) => new Response(JSON.stringify(Object.assign({ ok: false, error: msg }, extra || {})), { status: status || 400, headers: { 'Content-Type': 'application/json' } });
  // ---- data access ----
  const scopeFor = p => (p.startsWith('admin/') ? 'own' : (st.role === 'owner' ? 'own' : 'cust'));
  function get(p) {
    if (st.over[p]) return clone(st.over[p]);
    if (SNAP.pub[p]) return clone(SNAP.pub[p]);
    const sc = scopeFor(p), src = SNAP[sc];
    if (src[p]) return clone(src[p]);
    if (sc === 'own' && src[p + '?days=30']) return clone(src[p + '?days=30']);
    return null;
  }
  const put = (p, v) => { st.over[p] = v; save(); };
  function list(p, key) { const d = get(p); return d || { ok: true, [key]: [] }; }
  function upsert(coll, key, body, extra) {
    const d = list(coll, key); const arr = d[key]; const id = body.id || uid();
    let it = arr.find(x => x.id === id); const data = body.data || {};
    const base = { id, title: data.title || data.role || 'Untitled', template: data.template || '', company: data.company || (data.target && data.target.company) || '', role: data.role || (data.target && data.target.role) || '', revision: 1, created_at: now(), updated_at: now() };
    if (it) { Object.assign(it, base, { created_at: it.created_at, revision: (it.revision || 1) + 1 }, extra || {}); } else { it = Object.assign(base, extra || {}); arr.unshift(it); }
    put(coll, d);
    const dk = key === 'resumes' ? 'resume' : 'letter';
    const detail = { ok: true, [dk]: { id, revision: it.revision, data, isPrimary: !!it.is_primary, parentId: it.parent_id || null, jobDescription: body.jobDescription || '' } };
    put(coll + '/' + id, detail);
    return { [dk]: detail[dk], id, revision: it.revision };
  }
  // ---- plans / purchase ----
  function planByKey(k) { const bp = get('business-public'); return (bp.plans || {})[k] || (Array.isArray(bp.plans) ? bp.plans.find(x => x.key === k) : null); }
  function activate(planKey) {
    const pl = planByKey(planKey); if (!pl) return;
    const al = get('allowance'); const isAddon = pl.type === 'addon';
    if (!isAddon) { al.plan = planKey; al.paid = true; al.limits = pl; al.planName = pl.name; al.daysLeft = pl.accessDays; al.accessUntil = new Date(Date.now() + pl.accessDays * 864e5).toISOString().slice(0, 19).replace('T', ' '); }
    else { al.extras = al.extras || { resumes: 0, letters: 0 }; al.extras.resumes += pl.savedResumes || 0; al.extras.letters += pl.savedLetters || 0; }
    const lim = al.limits || {}; al.caps = { resumes: (lim.savedResumes || 0) + 1 + (al.extras ? al.extras.resumes : 0), letters: (lim.savedLetters || 0) + (al.extras ? al.extras.letters : 0), atsScans: lim.atsScans, exports: lim.exports };
    put('allowance', al);
    const pu = get('purchases'); pu.purchases.unshift({ id: uid(), plan: planKey, amount_paise: Math.round((pl.price || 0) * 100), refunded_paise: 0, currency: 'INR', status: 'paid', provider: 'demo', payment_reference: 'demo_' + Math.random().toString(36).slice(2, 10), limits_json: JSON.stringify(pl), created_at: now() });
    put('purchases', pu);
  }
  // ---- request router ----
  async function handle(method, url, body) {
    let u; try { u = new URL(url, location.href); } catch (e) { return null; }
    let p = u.pathname; const i = p.indexOf('/api/'); if (i < 0) return null;
    p = p.slice(i + 5).replace(/\/$/, ''); const q = u.search;
    let b = body; if (typeof b === 'string') { try { b = JSON.parse(b); } catch (e) { b = {}; } } b = b || {};
    await new Promise(r => setTimeout(r, 60));
    const sp = u.searchParams;
    if (p.startsWith('admin/') && st.role !== 'owner') return fail('Owner access required.', 403);
    // ----- users list with search / paging -----
    if (method === 'GET' && p === 'admin/users') { const d = get('admin/users'); const term = (sp.get('q') || '').toLowerCase(); let list = d.users; if (term) list = list.filter(x => ((x.name || '') + ' ' + (x.email || '')).toLowerCase().includes(term)); const fl = sp.get('f') || 'all'; if (fl !== 'all') list = list.filter(x => (x.isAdmin ? 'owner' : x.blocked ? 'blocked' : x.paid ? 'paid' : x.ever_paid ? 'expired' : 'normal') === fl); d.blockedUsers = d.users.filter(x => x.blocked).length; d.usersTotal = list.length; d.users = list.slice(((+sp.get('page') || 1) - 1) * 100, (+sp.get('page') || 1) * 100); return new Response(JSON.stringify(d), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    // ----- reports (built from the captured full data sets) -----
    if (method === 'GET' && (p === 'admin/report/meta' || p === 'admin/report/kpis')) return new Response(JSON.stringify(get(p === 'admin/report/meta' ? 'admin/report/meta' : 'admin/report/kpis?days=30')), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (method === 'GET' && p === 'admin/report') {
      const ds = sp.get('dataset') || 'orders', full = get('admin/report?dataset=' + ds + '&per=500&columns=ALL&format=json'); if (!full) return fail('Unknown report.', 422);
      const meta = get('admin/report/meta').datasets[ds], allKeys = meta.columns.map(c => c.key), want = (sp.get('columns') || '').split(',').filter(k => allKeys.includes(k)); const keys = want.length ? want : meta.defaults;
      let rows = full.rows.slice(); const q = (sp.get('q') || '').toLowerCase(), stt = sp.get('status'), pl = sp.get('plan'), pv = sp.get('provider'), fr = sp.get('from'), to = sp.get('to'), mn = sp.get('min'), mx = sp.get('max');
      const dk = ds === 'users' ? 'joined' : ds === 'errors' ? 'last_seen' : 'date';
      if (q) rows = rows.filter(r => Object.values(r).some(v => String(v == null ? '' : v).toLowerCase().includes(q)));
      if (stt && stt !== 'all' && ds !== 'users') rows = rows.filter(r => ds === 'errors' ? (stt === 'open' ? r.resolved !== 'Yes' : r.level === stt) : r.status === stt);
      if (stt && stt !== 'all' && ds === 'users') rows = rows.filter(r => stt === 'paid' ? r.paid === 'Yes' : stt === 'normal' ? r.paid === 'No' : stt === 'blocked' ? r.status === 'Blocked' : r.status === 'Owner');
      if (pl && pl !== 'all') rows = rows.filter(r => r.plan === pl); if (pv && pv !== 'all') rows = rows.filter(r => r.provider === pv);
      if (fr) rows = rows.filter(r => String(r[dk]).slice(0, 10) >= fr); if (to) rows = rows.filter(r => String(r[dk]).slice(0, 10) <= to);
      if (mn !== null && mn !== '') rows = rows.filter(r => r.amount_inr >= +mn); if (mx !== null && mx !== '') rows = rows.filter(r => r.amount_inr <= +mx);
      const sk = sp.get('sort'); if (sk && allKeys.includes(sk)) { const dr = sp.get('dir') === 'asc' ? 1 : -1; rows.sort((a, b) => (a[sk] > b[sk] ? 1 : a[sk] < b[sk] ? -1 : 0) * dr); }
      const cols = keys.map(k => meta.columns.find(c => c.key === k)), fmt = sp.get('format') || 'json';
      const sums = ds === 'orders' ? { amount_inr: rows.reduce((s, r) => s + r.amount_inr, 0), reversed_inr: rows.reduce((s, r) => s + r.reversed_inr, 0), discount_inr: rows.reduce((s, r) => s + r.discount_inr, 0), net_inr: rows.reduce((s, r) => s + r.net_inr, 0) } : null;
      if (fmt === 'csv' || fmt === 'xls') {
        const head = cols.map(c => c.label), lines = rows.map(r => keys.map(k => r[k] ?? '')), esc = v => { v = String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
        const text = fmt === 'csv' ? '\ufeff' + [head, ...lines].map(l => l.map(esc).join(',')).join('\r\n') : '<table border="1">' + [head, ...lines].map(l => '<tr>' + l.map(c => '<td>' + String(c).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</td>').join('') + '</tr>').join('') + '</table>';
        return new Response(text, { status: 200, headers: { 'Content-Type': fmt === 'csv' ? 'text/csv' : 'application/vnd.ms-excel', 'Content-Disposition': 'attachment; filename="cvtara-' + ds + '.' + fmt + '"' } });
      }
      const per = Math.min(500, Math.max(10, +sp.get('per') || 50)), page = Math.max(1, +sp.get('page') || 1);
      return ok({ dataset: ds, columns: cols, rows: rows.slice((page - 1) * per, page * per).map(r => Object.fromEntries([...keys, ...(ds === 'orders' ? ['id'] : [])].map(k => [k, r[k]]))), total: rows.length, page, per, sums });
    }
    // ----- error log -----
    if (method === 'GET' && p === 'admin/errors') {
      const d = get('admin/errors?days=30') || { ok: true, errors: [] }; const days = +sp.get('days') || 30; const cut = Date.now() - days * 864e5;
      let rows = d.errors.filter(e => new Date(String(e.last_seen).replace(' ', 'T') + 'Z').getTime() >= cut || /^20/.test('') );
      if (sp.get('level')) rows = rows.filter(e => e.level === sp.get('level')); if (sp.get('source')) rows = rows.filter(e => e.source === sp.get('source')); if (sp.get('state') === 'open') rows = rows.filter(e => !e.resolved_at); const q2 = (sp.get('q') || '').toLowerCase(); if (q2) rows = rows.filter(e => e.message.toLowerCase().includes(q2));
      const c = l => rows.filter(e => e.level === l && !e.resolved_at).length;
      return ok({ errors: rows, summary: { error: c('error'), warning: c('warning'), info: c('info'), total: rows.length } });
    }
    if (method === 'POST' && p === 'admin/errors/clear') { const d = get('admin/errors?days=30'); const ids = (b.ids || []).map(String); const hit = e => !ids.length || ids.includes(String(e.id)); if (b.mode === 'delete') d.errors = d.errors.filter(e => !hit(e)); else d.errors.forEach(e => { if (hit(e) && !e.resolved_at) { e.resolved_at = now(); e.resolved = 'yes'; } }); put('admin/errors?days=30', d); return ok({ affected: ids.length || d.errors.length }); }
    // ----- order detail -----
    let om = p.match(/^admin\/orders\/([0-9a-f-]{36})$/);
    if (method === 'GET' && om) { const pre = get(p); if (pre) return new Response(JSON.stringify(pre), { status: 200, headers: { 'Content-Type': 'application/json' } }); const pu = get('admin/purchases').purchases.find(x => x.id === om[1]); if (!pu) return fail('Record or page not found.', 404); const usr = get('admin/users').users.find(x => String(x.owner_id) === String(pu.owner_id)) || {}; return ok({ order: pu, orderNo: 'ORD-' + pu.id.slice(0, 6).toUpperCase(), user: { id: pu.owner_id, name: usr.name, email: usr.email, created_at: usr.created_at }, profile: { phone: usr.phone, city: usr.city, profession: usr.profession, experience_level: usr.experience_level }, limits: JSON.parse(pu.limits_json || '{}'), timeline: [{ event: 'purchase_verified', reference: pu.payment_reference, created_at: pu.created_at }], emails: [{ subject: 'Thank you for subscribing to CVTara ' + (JSON.parse(pu.limits_json || '{}').name || ''), status: 'sent', sent_at: pu.created_at, created_at: pu.created_at }], net: pu.amount_paise - pu.refunded_paise }); }
    // ----- user management -----
    if (method === 'POST' && /^admin\/users\/(grant|update|revoke|delete)$/.test(p)) {
      const act = p.split('/')[2], ud = get('admin/users'), usr = ud.users.find(x => String(x.owner_id) === String(b.userId)); if (!usr) return fail('Record or page not found.', 404);
      if (usr.isAdmin && act !== 'update') return fail('Owner accounts cannot be changed here.', 422);
      const pd = get('admin/purchases');
      if (act === 'update') { if (ud.users.some(x => x !== usr && x.email === String(b.email).toLowerCase())) return fail('That email address belongs to another account.', 422); Object.assign(usr, { name: b.name, email: String(b.email).toLowerCase() }, b.phone != null ? { phone: b.phone } : {}, b.profession != null ? { profession: b.profession } : {}, b.city != null ? { city: b.city } : {}, b.experienceLevel ? { experience_level: b.experienceLevel } : {}); put('admin/users', ud); return ok(); }
      if (act === 'delete') { ud.users = ud.users.filter(x => x !== usr); ud.totalUsers--; ud.usersTotal--; ud.freeUsers = Math.max(0, (ud.freeUsers || 1) - (usr.paid ? 0 : 1)); if (usr.paid) ud.paidUsers--; put('admin/users', ud); return ok(); }
      if (act === 'revoke') { let n = 0; pd.purchases.forEach(x => { if (String(x.owner_id) === String(usr.owner_id) && /paid|partially/.test(x.status) && x.access_until && new Date(String(x.access_until).replace(' ', 'T') + 'Z') > new Date()) { x.status = 'refunded'; x.refunded_paise = x.amount_paise; n++; } }); if (usr.paid) { usr.paid = false; ud.paidUsers--; } put('admin/purchases', pd); put('admin/users', ud); return ok({ revoked: n }); }
      // grant
      const pl = planByKey(b.plan); if (!pl || pl.type === 'free') return fail('Choose one of the paid plans or add-on packs.', 422);
      if (pl.type === 'addon' && !usr.paid) return fail('Add-on packs need an active plan. Choose a plan first.', 422);
      const days = b.days || pl.accessDays || 0, amt = Math.round((b.amount ?? pl.price) * 100), until = new Date(Date.now() + days * 864e5).toISOString().slice(0, 19).replace('T', ' ');
      const row = { id: crypto.randomUUID ? crypto.randomUUID() : uid() + '-0000-0000-0000-000000000000'.slice(0, 24), owner_id: Number(usr.owner_id), plan: b.plan, amount_paise: amt, refunded_paise: 0, currency: 'INR', status: 'paid', provider: 'manual', provider_order_id: 'manual_' + Date.now(), payment_reference: 'manual_' + Math.random().toString(36).slice(2, 12), is_live: amt > 0 ? 1 : 0, limits_json: JSON.stringify(pl), coupon_code: null, discount_paise: 0, created_at: now(), purchased_at: now(), access_until: pl.type === 'addon' ? usr.accessUntil : until, access_days: days };
      pd.purchases.unshift(row); put('admin/purchases', pd);
      if (pl.type !== 'addon') { if (!usr.paid) ud.paidUsers++; Object.assign(usr, { paid: true, ever_paid: true, last_plan: b.plan, accessUntil: until }); }
      put('admin/users', ud);
      let sent = 0; if (b.sendEmail !== false) { const jd = get('admin/email/jobs') || { jobs: [] }; jd.jobs = jd.jobs || []; jd.jobs.unshift({ id: uid(), recipient: usr.email, trigger: 'purchase_verified', subject: 'Thank you for subscribing to CVTara ' + pl.name, status: 'sent', sent_at: now(), due_at: now(), created_at: now(), attempts: 1, error: '' }); put('admin/email/jobs', jd); sent = 1; }
      return ok({ purchaseId: row.id, mail: sent ? { sent: 1, processed: 1 } : null, access: { plan: b.plan, paid: true, accessUntil: until } });
    }
    if (method === 'POST' && p === 'admin/health/run') { const d = get('admin/health/history'); const snap = JSON.parse(JSON.stringify(d.today)); snap.at = new Date().toISOString(); snap.date = snap.at.slice(0, 10); d.history = [snap, ...d.history.filter(h => h.date !== snap.date)]; d.today = snap; put('admin/health/history', d); return ok({ snapshot: snap, history: d.history }); }
    if (method === 'POST' && p === 'admin/media') return ok({ url: '/media/' + uid() + '.webp' });
    if (method === 'POST' && p === 'admin/email/process') return ok({ sent: 0, processed: 0 });
    if (method === 'POST' && p === 'admin/setup/email-test') return ok({ delivery: { sent: 1, processed: 1 } });
    if (method === 'GET') {
      if (p === 'profile' && !st.role) return fail('Please sign in.', 401, { signIn: true });
      if (!SNAP.pub[p] && !st.role && !['public-activity'].includes(p)) return fail('Please sign in.', 401, { signIn: true });
      if (p.startsWith('admin/') && st.role !== 'owner') return fail('Owner access required.', 403);
      if (p === 'security/status') return ok({ verified: true, recent: true });
      if (p.startsWith('admin/reports') && !get(p)) return ok(get('admin/reports?days=30'));
      let d = get(p) || (q ? get(p + q) : null);
      if (!d && /^(admin\/(content|users))/.test(p)) d = get(p.split('?')[0]);
      if (!d) { d = { ok: true }; }
      return new Response(JSON.stringify(d), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }
    if (p === 'traffic' || p === 'feedback/events') return ok();
    if (p === 'checkout/quote') { const pl = planByKey(b.plan) || {}; const price = Math.round((pl.price || 0) * 100); return ok({ plan: b.plan, amountPaise: price, discountPaise: 0, finalPaise: price, coupon: null }); }
    if (p === 'checkout/start') { const pl = planByKey(b.plan); if (!pl) return fail('Unknown plan.', 422); if (!st.role) return fail('Please sign in to continue.', 401, { signIn: true }); st.pending = { plan: b.plan }; save(); return ok({ provider: 'demo', orderId: 'demo_order_' + uid(), plan: b.plan, amount: Math.round(pl.price * 100) }); }
    if (p === 'checkout/verify') { const orderPlan = (st.pending || {}).plan; if (orderPlan) activate(orderPlan); st.pending = null; save(); return ok({ verified: true, plan: orderPlan }); }
    if (p === 'resumes') return ok(upsert('resumes', 'resumes', b));
    if (p === 'letters') return ok(upsert('letters', 'letters', b));
    if (p === 'resumes/variant' || p === 'resumes/primary' || /^resumes\/[^/]+\/(variant|primary)$/.test(p)) {
      const d = get('resumes'); const prim = d.resumes.find(x => x.is_primary) || d.resumes[0];
      if (/primary$/.test(p) && b.id) { d.resumes.forEach(x => x.is_primary = x.id === b.id ? 1 : 0); put('resumes', d); return ok({}); }
      const out = upsert('resumes', 'resumes', { data: { title: (b.role || 'Tailored') + ' · ' + (b.company || 'Company'), role: b.role, company: b.company, template: prim && prim.template }, jobDescription: b.jobDescription }, { is_primary: 0, parent_id: prim && prim.id, role: b.role || '', company: b.company || '' });
      return ok(out);
    }
    if (p === 'applications') { const d = get('applications'); const id = b.id || uid(); let it = d.applications.find(x => x.id === id); if (it) Object.assign(it, b, { updated_at: now() }); else d.applications.unshift(Object.assign({ stage: 'saved', revision: 1, created_at: now(), updated_at: now() }, b, { id })); put('applications', d); return ok({ id, application: d.applications.find(x => x.id === id) }); }
    const m = p.match(/^(resumes|letters|applications|feedback)\/([^/]+)$/);
    if (m && (method === 'DELETE')) { const key = m[1] === 'feedback' ? 'reviews' : m[1]; const d = get(m[1]); if (d && d[key]) { d[key] = d[key].filter(x => x.id !== m[2]); put(m[1], d); } return ok({}); }
    if (m && (method === 'PUT' || method === 'PATCH' || method === 'POST')) {
      if (m[1] === 'applications') { const d = get('applications'); const it = d.applications.find(x => x.id === m[2]); if (it) Object.assign(it, b, { updated_at: now() }); put('applications', d); return ok({ application: it }); }
      if (m[1] === 'resumes' || m[1] === 'letters') return ok(upsert(m[1], m[1], Object.assign({}, b, { id: m[2] })));
    }
    if (p === 'profile') { const d = get('profile'); d.profile = Object.assign(d.profile || {}, b.profile || b); put('profile', d); return ok({ profile: d.profile }); }
    if (p === 'feedback') { const d = get('feedback') || { reviews: [] }; d.reviews.unshift(Object.assign({ id: uid(), status: 'pending', created_at: now() }, b)); put('feedback', d); return ok({}); }
    if (p === 'features/use') return ok({ allowed: true, remaining: 99 });
    if (p === 'account/export') return ok({ message: 'In the real app this downloads a JSON file of your data.' });
    if (p === 'account/delete') return ok({ message: 'Demo: your account is not really deleted.' });
    if (p === 'security/send-code') return ok({ message: 'Demo mode: any 6-digit code works.' });
    if (p === 'security/verify-code') return ok({ verified: true });
    if (p === 'ai/suggest') return ok({ suggestions: ['Led a 5-person team to ship a dashboard that cut reporting time by 40%.', 'Built SQL and Power BI reports used by 120+ stakeholders each week.'] });
    if (p.startsWith('admin/')) {
      // admin saves: store the payload so the matching GET reflects it
      if (p === 'admin/plans') { const d = get('admin/plans'); const pl = b.plans || b; if (pl && typeof pl === 'object') { d.plans = Array.isArray(d.plans) ? pl : Object.assign(d.plans || {}, pl); const bp = get('business-public'); bp.plans = Object.assign(bp.plans || {}, pl); put('business-public', bp); } put('admin/plans', d); return ok({ plans: d.plans }); }
      if (p === 'admin/referrals/settings') { const d = get('admin/referrals'); d.rules = Object.assign(d.rules || {}, b); put('admin/referrals', d); return ok(); }
      if (/^admin\/referrals\/(creators|profiles)\/\d+$/.test(p)) { const d = get('admin/referrals'), id = +p.split('/').pop(), kind = p.split('/')[2]; (d[kind] || []).forEach(x => { if (+x.owner_id === id) { x.status = b.status; if (b.rateBps != null) x.rate_bps = b.rateBps; x.review_note = b.note; } }); put('admin/referrals', d); return ok(); }
      if (/^admin\/referrals\/withdrawals\//.test(p)) { const d = get('admin/referrals'), parts = p.split('/'), id = parts[3], act = parts[4]; (d.withdrawals || []).forEach(w => { if (w.id === id) { if (act === 'review') w.approved_at = new Date().toISOString(); else if (act === 'send') w.status = 'processing'; else if (!act) w.status = b.status; } }); put('admin/referrals', d); return ok(); }
      if (p === 'admin/settings') { const d = get('admin/settings'); d.settings = Object.assign(d.settings || {}, b.settings || b); put('admin/settings', d); return ok({ settings: d.settings }); }
      if (p.startsWith('admin/content/')) { const d = get(p); if (d) { d.data = b.data || b; d.revision = (d.revision || 0) + 1; put(p, d); } return ok({ revision: d ? d.revision : 1, data: d && d.data }); }
      if (p === 'admin/users/block') { const d = get('admin/users'); const u2 = d.users.find(x => x.id == b.id); if (u2) u2.blocked = b.blocked ? 1 : 0; put('admin/users', d); return ok({}); }
      return ok({ message: 'Saved (demo).' });
    }
    return ok();
  }
  M.handle = handle;
  // ---- fetch / XHR shims ----
  const realFetch = window.fetch.bind(window);
  window.fetch = async function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || String(input);
    const method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    if (/\/client-error/.test(url) && method === 'POST') { try { const b = JSON.parse(init.body); const d = get('admin/errors?days=30') || { ok: true, errors: [] }; const hit = d.errors.find(e => e.source === 'browser' && e.message === b.message); if (hit) { hit.occurrences++; hit.last_seen = now(); hit.resolved_at = null; } else d.errors.unshift({ id: uid(), level: 'error', source: 'browser', message: b.message, location: (b.source || '').split('/').pop() + ':' + (b.line || 0), url: b.url, occurrences: 1, first_seen: now(), last_seen: now(), resolved_at: null, resolved: 'no' }); put('admin/errors?days=30', d); } catch (e) { /* ignore */ } return new Response(null, { status: 204 }); }
    if (/\/api\//.test(url)) { const r = await handle(method, url, init && init.body); if (r) return r; }
    const pg = M.pageFor(url); if (pg && !/\.(js|css|mjs|json|png|ico|svg)(\?|$)/.test(url)) return new Response(PAGES[pg], { status: 200, headers: { 'Content-Type': 'text/html' } });
    if (/^https?:\/\/(?!.*claude)/.test(url) && !url.startsWith(location.origin)) return new Response('{}', { status: 200 });
    return realFetch(input, init);
  };
  // ---- dialogs (sandbox blocks the native ones) ----
  let toastEl;
  M.toast = msg => { if (!toastEl) { toastEl = document.createElement('div'); toastEl.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#111827;color:#fff;padding:12px 18px;border-radius:12px;font:600 14px system-ui;z-index:99999;box-shadow:0 8px 30px #0005;max-width:90vw'; } toastEl.textContent = msg; document.body.appendChild(toastEl); clearTimeout(M.tt); M.tt = setTimeout(() => toastEl.remove(), 3500); };
  window.alert = m => M.toast(String(m));
  window.confirm = () => true;
  window.prompt = (m, d) => d || 'Demo';
  window.open = u => { if (u) M.go(String(u)); return null; };
  // ---- page routing ----
  M.pageFor = function (url) {
    let u; try { u = new URL(url, location.href); } catch (e) { return null; }
    if (u.origin !== location.origin && !/^about:|^blob:/.test(u.protocol)) return null;
    let p = u.pathname; let base = location.pathname.replace(/[^/]*$/, ''); try { const bp = new URL(window.__BASE || './', location.href).pathname; if (p.startsWith(bp)) base = bp; } catch (e) {} if (p.startsWith(base) && base !== '/') p = p.slice(base.length); p = p.replace(/^\/+/, '').replace(/\/$/, '');
    if (p === '' || p === 'index.html') return 'home.html';
    if (PAGES[p]) return p; if (PAGES[p + '.html']) return p + '.html';
    if (/^(guides|blog|resume|cover-letter)\//.test(p)) return 'article.html';
    if (p === 'reset-password' || p.startsWith('reset-password')) return 'forgot-password.html';
    return null;
  };
  const key = k => (k === 'home' ? 'home.html' : /\./.test(k) ? k : k + '.html');
  const cleanKey = { 'login': 'login.html', 'register': 'register.html', 'forgot-password': 'forgot-password.html' };
  const ASSET = /\.(js|css|mjs|png|ico|svg|webp|jpg|woff2?)(\?|$)/;
  function transform(html) {
    return html
      .replace(/(src|href)="\/(?!\/)([^"]*)"/g, (m, a, v) => ASSET.test(v) ? `${a}="${v}${v.includes('?') ? '' : '?v=' + BUILD}"` : m)
      .replace(/(<meta name="cvtara-user" content=")[^"]*"/, (m, a) => a + (st.role === 'owner' ? '1' : st.role ? '2' : 'guest') + '"')
      .replace('</head>', '<link rel="stylesheet" href="cvtara-ds.css?v=' + BUILD + '"></head>')
      .replace(/<meta[^>]*http-equiv="refresh"[^>]*>/gi, '')
      .replace(/(<script[^>]*src=")(?!(https?:|\/\/))([^"]*)"/gi, (m, a, b, c) => a + c + '"');
  }
  M.render = function (name, hash) {
    let html = PAGES[name]; if (!html) html = PAGES['home.html'];
    st.cur = name; save();
    try { history.replaceState(null, '', location.pathname + location.search + (hash || '')); } catch (e) {}
    document.open(); document.write(transform(html).replace(/<head([^>]*)>/i, '<head$1><base href="' + (window.__BASE || './') + '">')); document.close(); M.attach(); M.chrome(name);
    window.scrollTo(0, 0);
  };
  M.chrome = function (name) {
    try {
      document.body.classList.add('has-topbar');
      const bar = document.createElement('div'); bar.className = 'cv-topbar';
      const cur = name === 'workspace.html' ? 'w' : (name === 'admin.html' || name === 'admin-guide.html') ? 'a' : 'l';
      bar.innerHTML = '<span class="cv-brand">CVTara v3.7.0</span><div class="cv-tabs"><button data-t="l">Landing Page</button><button data-t="w">Customer Workspace</button><button data-t="a">Admin Dashboard</button></div><button data-t="theme" title="Day / night">🌙 Dark</button>';
      bar.querySelectorAll('[data-t]').forEach(b => { if (b.dataset.t === cur) b.classList.add('on'); b.onclick = () => {
        const t = b.dataset.t;
        if (t === 'theme') { if (window.CVTaraTheme) window.CVTaraTheme.toggle(); return; }
        if (t === 'l') return M.render('home.html');
        M.signIn(t === 'a' ? 'owner' : 'customer'); M.render(t === 'a' ? 'admin.html' : 'workspace.html');
      }; });
      document.body.insertBefore(bar, document.body.firstChild);
      const tb = bar.querySelector('[data-t=theme]'), lab = () => { const d = document.documentElement.classList.contains('dark'); tb.textContent = d ? '☀ Day mode' : '🌙 Night mode'; };
      lab(); document.addEventListener('cvtara-theme', lab);
      const cover = document.createElement('div'); cover.className = 'cv-loading'; cover.innerHTML = '<i></i>'; document.body.appendChild(cover);
      const finish = () => { cover.classList.add('off'); setTimeout(() => cover.remove(), 400); };
      const t0 = Date.now(); const ready = () => name !== 'admin.html' ? Date.now() - t0 > 500 : (document.body.classList.contains('admin-ready') && Date.now() - t0 > 600) || Date.now() - t0 > 9000;
      const poll = () => document.readyState === 'complete' && ready() ? finish() : setTimeout(poll, 120); poll();
    } catch (e) {}
  };
  M.go = function (url) {
    if (/^(mailto|tel|javascript):/.test(url)) return;
    let u; try { u = new URL(url, location.href); } catch (e) { return; }
    if (u.origin !== location.origin && u.protocol.startsWith('http')) { M.toast('External link (opens in the real site): ' + u.hostname); return; }
    const pg = u.pathname.match(/\/preview\/go\/(customer|admin)/);
    if (pg) { M.signIn(pg[1] === 'admin' ? 'owner' : 'customer'); return M.render(pg[1] === 'admin' ? 'admin.html' : 'workspace.html'); }
    if (/\/preview\/?$/.test(u.pathname)) return M.render('home.html');
    const k = M.pageFor(url);
    // route access
    if (k === 'workspace.html' && !st.role) { M.toast('Please log in first'); return M.render('login.html'); }
    if (k === 'admin-guide.html' && st.role !== 'owner') { M.toast('The platform guide is private — log in as the owner'); return M.render('login.html'); }
    if (k === 'admin.html' && st.role !== 'owner') { M.toast(st.role ? 'Owner access required — log in as the owner.' : 'Please log in as the owner first'); return M.render('login.html'); }
    if (/\/(export|media)\//.test(u.pathname) || /\/api\//.test(u.pathname)) { M.download(u.pathname); return; }
    if (!k) { M.toast('This page is not part of the demo.'); return; }
    // keep the query string available to page scripts (e.g. ?view=resumes)
    const search = u.search + u.hash;
    window.__cvq = search;
    const real = history.replaceState.bind(history);
    try { real(null, '', location.pathname + u.search + u.hash); } catch (e) {}
    M.render(PAGES[k] ? k : key(k));
  };
  M.download = function (name) {
    const blob = new Blob(['CVTara demo export\n\nIn the real app this is your resume/cover letter as PDF, DOCX or TXT.\n'], { type: 'text/plain' });
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'cvtara-demo-export.txt'; document.body.appendChild(a); a.click(); a.remove(); M.toast('Demo file downloaded');
  };
  // ---- global handlers (survive document.write) ----
  function attach() {
  window.addEventListener('click', e => {
    const a = e.target.closest && e.target.closest('a[href]'); if (!a || e.defaultPrevented) return;
    if (e.metaKey || e.ctrlKey) return;
    const h = a.getAttribute('href'); if (!h || h.startsWith('#') || /^(mailto|tel|javascript|blob|data):/.test(h)) return;
    if (a.hasAttribute('download') && !/\/(export|api)\//.test(h)) { e.preventDefault(); M.download(h); return; }
    e.preventDefault(); M.go(a.href);
  }, true);
  window.addEventListener('submit', e => {
    const f = e.target; const act = (f.getAttribute('action') || '').replace(/^.*\//, '');
    if (!['login', 'register', 'logout', 'forgot-password', 'reset-password'].includes(act)) return;
    e.preventDefault(); e.stopImmediatePropagation();
    const em = (f.querySelector('[name=email]') || {}).value || '';
    if (act === 'logout') { M.signIn(null); M.toast('Signed out'); return M.render('home.html'); }
    if (act === 'forgot-password') { M.toast('If that email exists, a reset link has been sent (demo).'); return; }
    if (act === 'login') { if (!em) { M.toast('Enter an email'); return; } M.signIn(/owner|admin/i.test(em) ? 'owner' : 'customer'); M.toast('Welcome back!'); return M.render(st.role === 'owner' ? 'admin.html' : 'workspace.html'); }
    if (act === 'register') { M.signIn('customer'); M.toast('Account created (demo) — welcome!'); return M.render('workspace.html'); }
  }, true);
  }
  M.attach = attach; attach();
  window.addEventListener('popstate', () => {});
  M.start = function (role, page) {
    if (role && !st.role) M.signIn(role);
    if (role === 'owner' && st.role !== 'owner') M.signIn('owner');
    if (role === 'customer' && st.role === 'owner') M.signIn('customer');
    if (st.cur && st.cur !== page && window.__resume) { /* keep last page when reloading */ }
    M.render(page);
  };
})();
