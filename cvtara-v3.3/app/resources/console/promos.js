/* Owner console: Offers & festivals — one-click campaigns with a top bar, timed, exit-intent and idle pop-ups, and a coupon that applies at checkout. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast, ensureRecent } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const post = async (path, body) => { if (!(await ensureRecent())) throw new Error('Please confirm your identity to continue.'); return api(path, { method: 'POST', body }); };
  const inr = p => '₹' + Math.round(p / 100).toLocaleString('en-IN');
  const STATUS = { live: ['Live now', 'ok'], scheduled: ['Scheduled (running)', 'ok'], upcoming: ['Starts later', 'mid'], ended: ['Ended', 'bad'], off: ['Off', ''] };
  let S = null;

  const toView = (f, coupon, themes) => {
    const th = themes.find(t => t.key === f.theme) || themes[themes.length - 1], year = String(new Date().getFullYear());
    const disc = coupon ? (coupon.type === 'percent' ? coupon.value + '% OFF' : '₹' + Number(coupon.value).toLocaleString('en-IN') + ' OFF') : '';
    const fill = s => String(s || '').replace(/\{\{discount\}\}/g, disc).replace(/\{\{code\}\}/g, (f.couponCode || '').toUpperCase()).replace(/\{\{year\}\}/g, year);
    return { id: f.id || 'preview', badge: fill(f.badge), heading: fill(f.heading), subheading: fill(f.subheading), body: fill(f.body), cta: f.cta || 'Claim this offer', code: (f.couponCode || '').toUpperCase(), discount: disc, plan: f.planKey || '', image: f.imageUrl || '',
      theme: { from: th.from, to: th.to, ink: th.ink, accent: th.accent, art: th.art }, endsAt: f.endsAt ? new Date(f.endsAt + '+05:30').toISOString() : null, countdown: !!f.countdown && !!f.endsAt,
      triggers: {}, variants: { exit: { heading: fill(f.exitHeading), text: fill(f.exitText) }, idle: { heading: fill(f.idleHeading), text: fill(f.idleText) } }, pages: f.pages, frequencyHours: f.frequencyHours };
  };
  const swatch = t => `linear-gradient(135deg,${t.from},${t.to})`;

  async function load() { S = await api('/api/admin/promos'); return S; }

  async function promos() {
    const h = $('#admin-promos'); if (!h) return;
    h.innerHTML = '<div class="ops-skel"></div>';
    try { await load(); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    const live = S.campaigns.find(c => c.status === 'live') || S.campaigns.find(c => c.status === 'scheduled');
    const tot = S.campaigns.reduce((a, c) => { a.shown += c.stats.shown; a.click += c.stats.click; a.orders += c.stats.orders; a.rev += c.stats.revenuePaise; return a; }, { shown: 0, click: 0, orders: 0, rev: 0 });
    h.innerHTML = `<p class="muted" style="margin:0 0 12px;max-width:760px">Run a festival, early-bird or flash offer in a few clicks. Visitors see a top bar and pop-ups with your coupon, and the coupon is applied for them at checkout. Switch the whole promotion with one click.</p>
      <div class="ops-kpis"><div class="kpi"><span>Live now</span><b style="font-size:16px">${live ? E(live.name) : 'None'}</b></div><div class="kpi"><span>Offers seen</span><b>${tot.shown}</b></div><div class="kpi"><span>Clicks on the offer</span><b>${tot.click}</b></div><div class="kpi"><span>Orders with an offer coupon</span><b>${tot.orders}</b></div><div class="kpi"><span>Revenue from them</span><b>${inr(tot.rev)}</b></div></div>
      <section class="panel panel-pad"><h3>Start a new offer</h3><p class="muted" style="margin:0 0 12px">Pick a festival or occasion. Everything can be edited, including the year, the heading and the artwork.</p>
        <div class="pm-tpls">${S.templates.map(t => { const th = S.themes.find(x => x.key === t.theme); return `<button type="button" class="pm-tpl" data-tpl="${E(t.key)}" style="background:${swatch(th)};color:${E(th.ink)}"><i>${E(th.art)}</i><b>${E(t.label)}</b></button>`; }).join('')}</div></section>
      <section class="panel panel-pad"><h3>Your offers</h3>${S.campaigns.length ? `<div class="pm-list">${S.campaigns.map(c => { const th = S.themes.find(x => x.key === c.theme) || S.themes[0], st = STATUS[c.status] || ['', ''], s = c.stats; return `<article class="pm-card ${c.status === 'live' ? 'is-live' : ''}" data-id="${E(c.id)}">
          <div class="pm-head" style="background:${swatch(th)};color:${E(th.ink)}"><i>${E(th.art)}</i><div><b>${E(c.name)}</b><small>${E(c.view.heading)}</small></div><span class="ad-pill ${st[1]}">${st[0]}</span></div>
          <div class="pm-body"><p class="pm-meta">${c.couponCode ? `Coupon <code>${E(c.couponCode)}</code> · ${E(c.view.discount)}${c.couponOk ? '' : ' <b style="color:var(--danger)">(coupon is off or expired — the offer is hidden)</b>'}` : 'No coupon'} · ${c.startsAt ? 'from ' + E(c.startsAt.replace('T', ' ')) : 'no start date'}${c.endsAt ? ' to ' + E(c.endsAt.replace('T', ' ')) : ''}</p>
            <p class="pm-stats"><span><b>${s.shown}</b> seen</span><span><b>${s.click}</b> clicks</span><span><b>${s.copy}</b> codes copied</span><span><b>${s.orders}</b> orders</span><span><b>${inr(s.revenuePaise)}</b></span></p>
            <div class="pm-acts">${c.status === 'live' ? '<button class="button secondary small" data-act="off">Switch off</button>' : '<button class="button primary small" data-act="live">Make live now</button>'}${c.startsAt || c.endsAt ? '<button class="button secondary small" data-act="schedule">Run by dates</button>' : ''}<button class="button secondary small" data-act="edit">Edit</button><button class="button secondary small" data-act="preview">Preview</button><button class="button secondary small" data-act="print">Print coupon</button><button class="button secondary small" data-act="link">Copy offer link</button><button class="button secondary small" data-act="dup">Duplicate</button><button class="button secondary small" data-act="del">Delete</button></div></div></article>`; }).join('')}</div>` : '<p class="muted">No offers yet. Pick a festival above to create your first one.</p>'}</section>
      <p role="status" id="pmMsg"></p>`;
    h.onclick = async e => {
      const t = e.target.closest('[data-tpl]'); if (t) return edit(null, t.dataset.tpl);
      const b = e.target.closest('[data-act]'); if (!b) return;
      const id = b.closest('[data-id]').dataset.id, c = S.campaigns.find(x => x.id === id), say = m => { $('#pmMsg', h).textContent = m; };
      try {
        if (b.dataset.act === 'edit') return edit(c);
        if (b.dataset.act === 'preview') return preview(c);
        if (b.dataset.act === 'print') return printCoupon(c);
        if (b.dataset.act === 'link') { const u = `${S.siteUrl}/?offer=${encodeURIComponent(c.couponCode || '')}`; try { await navigator.clipboard.writeText(u); } catch { /* shown below */ } say('Offer link: ' + u + ' (opens the pop-up for this offer and carries the coupon to checkout).'); return toast('Link copied'); }
        if (b.dataset.act === 'dup') { const { status, stats, view, couponOk, id: _i, mode, ...rest } = c; await post('/api/admin/promos/save', { ...rest, name: c.name + ' (copy)' }); toast('Duplicated'); return promos(); }
        if (b.dataset.act === 'del') { if (!confirm('Delete this offer? Its statistics are removed from the list.')) return; await post('/api/admin/promos/delete', { id }); toast('Deleted'); return promos(); }
        if (b.dataset.act === 'live') { if (c.couponCode && !c.couponOk) throw new Error('The coupon for this offer is off or expired. Edit the offer and fix the coupon first.'); await post('/api/admin/promos/live', { id }); toast('“' + c.name + '” is live. The site shows it now.'); return promos(); }
        if (b.dataset.act === 'off') { await post('/api/admin/promos/off', { id }); toast('Switched off'); return promos(); }
        if (b.dataset.act === 'schedule') { await post('/api/admin/promos/schedule', { id }); toast('Will run between its start and end dates'); return promos(); }
      } catch (x) { say(err(x)); toast(err(x), true); }
    };
  }

  function preview(c, variant) {
    if (!window.CVTaraPromo) return toast('Preview is not available on this page.', true);
    const v = c.view || c;
    const pick = modal('Preview', `<p style="margin:0 0 10px">Which pop-up do you want to see?</p><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" data-v="">Timed pop-up</button><button class="button secondary" data-v="exit">Exit-intent pop-up</button><button class="button secondary" data-v="idle">Idle pop-up</button></div>`);
    pick.el.onclick = e => { const b = e.target.closest('[data-v]'); if (b) { pick.close(); window.CVTaraPromo.preview(v, b.dataset.v); } };
  }

  function printCoupon(c) {
    const th = S.themes.find(x => x.key === c.theme) || S.themes[0], v = c.view, w = window.open('', '_blank', 'width=900,height=700'); if (!w) return toast('Allow pop-ups to print.', true);
    const link = `${S.siteUrl}/?offer=${encodeURIComponent(c.couponCode || '')}`, until = c.endsAt ? new Date(c.endsAt + '+05:30').toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' }) : '';
    w.document.write(`<!doctype html><title>${E(c.name)} — coupon</title><style>body{margin:0;font-family:Arial,sans-serif;display:grid;place-items:center;min-height:100vh;background:#f1f5f9}.c{width:560px;border-radius:22px;overflow:hidden;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.15)}.t{padding:28px;background:linear-gradient(135deg,${th.from},${th.to});color:${th.ink};text-align:center}.t i{font-size:64px;font-style:normal;display:block}.t h1{margin:8px 0 4px;font-size:30px}.t p{margin:0;opacity:.95}.b{padding:26px;text-align:center}.off{font-size:54px;font-weight:900;color:${th.from}}.code{display:inline-block;margin:10px 0;padding:12px 26px;border:3px dashed ${th.from};border-radius:14px;font-size:30px;font-weight:900;letter-spacing:.12em}.s{color:#475569;font-size:15px;line-height:1.6}.f{padding:12px;background:#f8fafc;color:#64748b;font-size:12px;text-align:center}@media print{body{background:#fff}.c{box-shadow:none;border:1px solid #cbd5e1}}</style><div class="c"><div class="t"><i>${E(th.art)}</i><h1>${E(v.heading)}</h1><p>${E(v.subheading)}</p></div><div class="b"><div class="off">${E(v.discount || '')}</div><div class="code">${E(v.code || '')}</div><p class="s">1. Go to <b>${E(S.siteUrl.replace(/^https?:\/\//, ''))}</b> and create your account<br>2. Choose any ${v.plan ? 'plan' : 'plan'} and enter the code at checkout${until ? `<br>3. Valid until <b>${E(until)}</b>` : ''}</p><p class="s"><b>${E(link)}</b></p></div><div class="f">All payments are final and non-refundable. One use per account unless stated. support@cvtara.com</div></div><script>onload=()=>setTimeout(()=>print(),400)<\/script>`);
    w.document.close();
  }

  function edit(c, tplKey) {
    const t = tplKey ? S.templates.find(x => x.key === tplKey) : null, year = String(new Date().getFullYear());
    const f = c ? { ...c } : { id: '', name: (t ? t.label : 'Offer') + ' ' + year, template: tplKey, theme: t.theme, heading: t.heading.replace('{{year}}', year), subheading: t.subheading, body: '', badge: t.badge, cta: 'Claim this offer', couponCode: '', planKey: '', imageUrl: '', startsAt: '', endsAt: '', countdown: true, banner: true, popup: true, popupDelay: 20, exit: true, idle: true, idleSeconds: 45, exitHeading: 'Wait — your offer is still here', exitText: 'Claim it before you go. It applies automatically at checkout.', idleHeading: 'Still thinking? This offer is waiting', idleText: 'Take a minute: your coupon is ready.', pages: 'all', frequencyHours: 12, priority: 0 };
    const opt = (v, l, cur) => `<option value="${E(v)}" ${String(cur) === String(v) ? 'selected' : ''}>${E(l)}</option>`;
    const m = modal(c ? 'Edit offer' : 'New offer: ' + (t ? t.label : ''), `<form id="pmForm" class="ops-form pm-form">
      <label class="wide">Offer name (only you see this)<input name="name" maxlength="80" required value="${E(f.name)}"></label>
      <fieldset class="wide"><legend>Look</legend><div class="pm-themes">${S.themes.map(th => `<label class="pm-theme" title="${E(th.key)}"><input type="radio" name="theme" value="${E(th.key)}" ${th.key === f.theme ? 'checked' : ''}><span style="background:${swatch(th)};color:${E(th.ink)}">${E(th.art)}</span></label>`).join('')}</div></fieldset>
      <label class="wide">Heading<input name="heading" maxlength="120" required value="${E(f.heading)}"></label>
      <label class="wide">Sub-heading<input name="subheading" maxlength="240" value="${E(f.subheading || '')}"></label>
      <label class="wide">Details (optional)<textarea name="body" rows="2" maxlength="500">${E(f.body || '')}</textarea></label>
      <label>Badge<input name="badge" maxlength="40" value="${E(f.badge || '')}"></label><label>Button text<input name="cta" maxlength="40" value="${E(f.cta || '')}"></label>
      <label class="wide">Picture (optional: replaces the festival artwork)<span class="pm-img"><input name="imageUrl" maxlength="300" placeholder="Upload, or paste an https address" value="${E(f.imageUrl || '')}"><button type="button" class="button secondary small" data-up>Upload</button><input type="file" hidden accept="image/jpeg,image/png,image/webp" data-file></span></label>
      <p class="muted wide" style="margin:0;font-size:12px">You can type <code>{{year}}</code>, <code>{{discount}}</code> (for example 25% OFF) and <code>{{code}}</code> in any text; they are filled in automatically.</p>
      <fieldset class="wide"><legend>Coupon</legend><div class="pm-cp">
        <label>Coupon for this offer<select name="couponCode"><option value="">No coupon (banner only)</option>${S.coupons.map(k => opt(k.code, `${k.code} · ${k.type === 'percent' ? k.value + '%' : '₹' + k.value} off${k.enabled ? '' : ' (off)'}`, f.couponCode)).join('')}</select></label>
        <button type="button" class="button secondary small" data-newcoupon>+ Create a new coupon</button></div>
        <div class="pm-newcp" hidden><label>Code<input name="cc_code" maxlength="30" placeholder="e.g. DIWALI25"></label><label>Type<select name="cc_type"><option value="percent">Percent off</option><option value="flat">Rupees off</option></select></label><label>Value<input type="number" name="cc_value" min="1" max="100000" value="25"></label>
          <label>Last day<input type="date" name="cc_exp"></label><label>Total uses (0 = no limit)<input type="number" name="cc_max" min="0" value="0"></label><label>Uses per customer<input type="number" name="cc_per" min="0" value="1"></label>
          <div class="wide"><span class="muted" style="font-size:12px">Works on these plans</span><div class="pm-plans">${S.plans.map(p => `<label><input type="checkbox" name="cc_plan" value="${E(p.key)}" checked> ${E(p.name)}</label>`).join('')}</div></div><button type="button" class="button primary small" data-savecoupon>Save coupon</button> <span role="status" data-ccmsg></span></div></fieldset>
      <label>Open this plan when clicked<select name="planKey">${opt('', 'Let the visitor choose', f.planKey)}${S.plans.map(p => opt(p.key, p.name + ' · ₹' + p.price, f.planKey)).join('')}</select></label>
      <label>Show on<select name="pages">${opt('all', 'Every public page', f.pages)}${opt('home-pricing', 'Home, templates and cover-letter pages', f.pages)}${opt('home', 'Home page only', f.pages)}</select></label>
      <fieldset class="wide"><legend>When it runs (India time)</legend><div class="pm-two"><label>Starts<input type="datetime-local" name="startsAt" value="${E(f.startsAt || '')}"></label><label>Ends<input type="datetime-local" name="endsAt" value="${E(f.endsAt || '')}"></label></div>
        <label class="pm-check"><input type="checkbox" name="countdown" ${f.countdown ? 'checked' : ''}> Show a countdown to the end time</label><p class="muted" style="margin:4px 0 0;font-size:12px">“Make live now” runs it immediately until you switch it off. “Run by dates” uses the start and end times.</p></fieldset>
      <fieldset class="wide"><legend>Where visitors see it</legend>
        <label class="pm-check"><input type="checkbox" name="banner" ${f.banner ? 'checked' : ''}> Top bar on every page</label>
        <div class="pm-trg"><label class="pm-check"><input type="checkbox" name="popup" ${f.popup ? 'checked' : ''}> Pop-up after <input type="number" name="popupDelay" min="3" max="600" value="${E(f.popupDelay)}" style="width:70px"> seconds</label></div>
        <div class="pm-trg"><label class="pm-check"><input type="checkbox" name="exit" ${f.exit ? 'checked' : ''}> Pop-up when the visitor is about to leave</label><label>Heading<input name="exitHeading" maxlength="120" value="${E(f.exitHeading || '')}"></label><label>Text<input name="exitText" maxlength="240" value="${E(f.exitText || '')}"></label></div>
        <div class="pm-trg"><label class="pm-check"><input type="checkbox" name="idle" ${f.idle ? 'checked' : ''}> Pop-up when the visitor goes quiet for <input type="number" name="idleSeconds" min="10" max="600" value="${E(f.idleSeconds)}" style="width:70px"> seconds</label><label>Heading<input name="idleHeading" maxlength="120" value="${E(f.idleHeading || '')}"></label><label>Text<input name="idleText" maxlength="240" value="${E(f.idleText || '')}"></label></div>
        <div class="pm-two"><label>Show a pop-up again after (hours)<input type="number" name="frequencyHours" min="1" max="720" value="${E(f.frequencyHours)}"></label><label>Priority (higher wins)<input type="number" name="priority" min="0" max="100" value="${E(f.priority)}"></label></div></fieldset>
      <div class="wide" style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" type="submit">Save</button><button class="button secondary" type="button" data-savelive>Save and make live now</button><button class="button secondary" type="button" data-prev>Preview</button></div><p role="status" id="pmFormMsg" class="wide"></p></form>`, { wide: true });
    const form = $('#pmForm', m.el), msg = $('#pmFormMsg', m.el), val = () => {
      const g = n => form.elements[n]; const o = { id: f.id || '', template: f.template || '', name: g('name').value.trim(), theme: form.querySelector('[name=theme]:checked')?.value || f.theme, heading: g('heading').value.trim(), subheading: g('subheading').value.trim(), body: g('body').value.trim(), badge: g('badge').value.trim(), cta: g('cta').value.trim(), imageUrl: g('imageUrl').value.trim(), couponCode: g('couponCode').value, planKey: g('planKey').value, pages: g('pages').value,
        startsAt: g('startsAt').value, endsAt: g('endsAt').value, countdown: g('countdown').checked, banner: g('banner').checked, popup: g('popup').checked, popupDelay: +g('popupDelay').value || 20, exit: g('exit').checked, idle: g('idle').checked, idleSeconds: +g('idleSeconds').value || 45, exitHeading: g('exitHeading').value.trim(), exitText: g('exitText').value.trim(), idleHeading: g('idleHeading').value.trim(), idleText: g('idleText').value.trim(), frequencyHours: +g('frequencyHours').value || 12, priority: +g('priority').value || 0 };
      return o;
    };
    $('[data-newcoupon]', m.el).onclick = () => { $('.pm-newcp', m.el).hidden = !$('.pm-newcp', m.el).hidden; };
    $('[data-savecoupon]', m.el).onclick = async () => {
      const g = n => form.elements[n], plans = [...form.querySelectorAll('[name=cc_plan]:checked')].map(x => x.value), say = $('[data-ccmsg]', m.el);
      try { const code = g('cc_code').value.trim().toUpperCase(); if (!code) throw new Error('Type a coupon code.'); S = await post('/api/admin/promos/coupon', { code, type: g('cc_type').value, value: +g('cc_value').value, plans, expiresAt: g('cc_exp').value || null, maxUses: +g('cc_max').value || 0, perUser: +g('cc_per').value }); const sel = g('couponCode'); sel.innerHTML = '<option value="">No coupon (banner only)</option>' + S.coupons.map(k => `<option value="${E(k.code)}">${E(k.code)} · ${k.type === 'percent' ? k.value + '%' : '₹' + k.value} off${k.enabled ? '' : ' (off)'}</option>`).join(''); sel.value = code; say.textContent = 'Saved and selected.'; $('.pm-newcp', m.el).hidden = true; }
      catch (x) { say.textContent = err(x); }
    };
    const up = $('[data-file]', m.el); $('[data-up]', m.el).onclick = () => up.click();
    // The picture is shrunk and saved as WebP in the browser (the server accepts WebP files up to 500 KB).
    const webp = async file => {
      const bmp = await createImageBrowser(file), scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height)), cv = document.createElement('canvas');
      cv.width = Math.max(1, Math.round(bmp.width * scale)); cv.height = Math.max(1, Math.round(bmp.height * scale)); cv.getContext('2d').drawImage(bmp, 0, 0, cv.width, cv.height);
      for (const q of [0.88, 0.78, 0.68, 0.55, 0.4]) { const blob = await new Promise(r => cv.toBlob(r, 'image/webp', q)); if (blob && blob.size <= 480000) return new Promise((res, rej) => { const fr = new FileReader(); fr.onload = () => res(String(fr.result).split(',')[1]); fr.onerror = rej; fr.readAsDataURL(blob); }); }
      throw new Error('That picture is too detailed. Choose a simpler or smaller one.');
    };
    const createImageBrowser = f => createImageBitmap(f);
    up.onchange = async () => {
      const file = up.files[0]; if (!file) return;
      try { msg.textContent = 'Preparing the picture…'; const d = await post('/api/admin/media', { base64: await webp(file) }); form.elements.imageUrl.value = d.url; msg.textContent = 'Picture uploaded.'; } catch (x) { msg.textContent = err(x); }
    };
    const save = async live => {
      msg.textContent = 'Saving…';
      try { const o = val(); const r = await post('/api/admin/promos/save', o); if (live) await post('/api/admin/promos/live', { id: r.id }); toast(live ? 'Saved and live' : 'Saved'); m.close(); promos(); }
      catch (x) { msg.textContent = err(x); }
    };
    form.onsubmit = e => { e.preventDefault(); save(false); };
    $('[data-savelive]', m.el).onclick = () => save(true);
    $('[data-prev]', m.el).onclick = () => { const o = val(); const cp = S.coupons.find(k => k.code === o.couponCode); const v = toView(o, cp, S.themes); const pick = modal('Preview', `<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button primary" data-v="">Timed pop-up</button><button class="button secondary" data-v="exit">Exit-intent</button><button class="button secondary" data-v="idle">Idle</button></div>`); pick.el.onclick = e => { const b = e.target.closest('[data-v]'); if (b) { pick.close(); window.CVTaraPromo?.preview(v, b.dataset.v); } }; };
  }

  K.register('promos', promos, ['🎉', 'Offers & festivals', 'Festival, early-bird and flash offers with pop-ups, a top bar and a coupon that applies at checkout.']);
})();
