/* Owner console: Blog & SEO (with premium editor), Footer & policies (with all site URLs), Setup & health. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, $$, E, api, parse, dmy, pill, modal, toast, download, csv } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const dtm = d => { const x = parse(d); return isNaN(x) ? '' : x.toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); };
  const save = (key, data, revision) => api('/api/admin/content/' + key, { method: 'POST', body: { data, revision } });
  const words = b => String(b || '').replace(/[\[\]()#!*_>`-]/g, ' ').trim().split(/\s+/).filter(Boolean).length;
  const mods = {};
  const fmt = async () => mods.fmt || (mods.fmt = await import('/content-format.mjs'));
  const pathOf = (slug, p) => '/' + ((p && p.type === 'Calculator') ? 'calculators' : 'guides') + '/' + slug + '/';
  const kwList = p => String(p.keywords || '').split(',').map(x => x.trim()).filter(Boolean);

  /* ======================= BLOG & SEO ======================= */
  const B = { q: '', cat: 'all', st: 'all' };
  async function bloghub() {
    const h = $('#admin-bloghub'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div><div class="ops-skel"></div>';
    try {
      const [blog, seo] = await Promise.all([api('/api/admin/content/blog'), api('/api/admin/content/seo')]);
      const posts = blog.data, keys = Object.keys(posts), cats = [...new Set(keys.map(k => posts[k].category || 'Other'))].sort();
      const wc = k => words(posts[k].body);
      const rows = () => keys.filter(k => { const p = posts[k]; return (B.cat === 'all' || (p.category || 'Other') === B.cat) && (B.st === 'all' || (B.st === 'live' ? p.published : B.st === 'draft' ? !p.published : wc(k) < 350)) && (!B.q || (p.title + ' ' + k + ' ' + (p.keywords || '')).toLowerCase().includes(B.q.toLowerCase())); });
      const s = { sitemapEnabled: true, searchIndexing: false, siteUrl: 'https://cvtara.com', pages: {}, ...seo.data };
      h.innerHTML = `<div class="pg-head"><h2>Blog & SEO</h2><p>Every guide and calculator page: readable web address, focus keyword, advanced keywords and a premium editor.</p></div>
        <div class="ops-kpis" id="bk"></div>
        <section class="panel panel-pad seo-files"><div><h3>Search engine files</h3><p class="ops-note" style="margin:4px 0 0">Generated automatically from your published pages using <b>${E(s.siteUrl || 'your domain')}</b>.</p></div>
          <form id="seoF" class="ops-form four"><label class="ops-check"><input type="checkbox" name="sitemapEnabled" ${s.sitemapEnabled ? 'checked' : ''}> Sitemap on</label><label class="ops-check"><input type="checkbox" name="searchIndexing" ${s.searchIndexing ? 'checked' : ''}> Let search engines index</label><label class="wide" style="grid-column:span 2">Website address<input name="siteUrl" value="${E(s.siteUrl)}" placeholder="https://cvtara.com"></label>
          <div class="ops-end"><a class="button secondary small" href="/sitemap.xml" target="_blank" rel="noopener">View sitemap.xml ↗</a> <a class="button secondary small" href="/robots.txt" target="_blank" rel="noopener">View robots.txt ↗</a> <button class="button primary small">Save</button> <button type="button" class="button secondary small" data-admin-view="seo">Per-page SEO editor</button> <span class="ops-st" role="status"></span></div></form>
          <p class="ops-note" id="idxNote"></p></section>
        <section class="panel panel-pad ops-filterbox"><label class="ops-f grow">Search<input id="bq" type="search" placeholder="Search title, address or keyword" value="${E(B.q)}"></label><label class="ops-f">Category<select id="bc"><option value="all">All</option>${cats.map(c => `<option ${B.cat === c ? 'selected' : ''}>${E(c)}</option>`).join('')}</select></label><label class="ops-f">Show<select id="bs"><option value="all">All</option><option value="live" ${B.st === 'live' ? 'selected' : ''}>Published</option><option value="draft" ${B.st === 'draft' ? 'selected' : ''}>Drafts</option><option value="short" ${B.st === 'short' ? 'selected' : ''}>Under 350 words</option></select></label><div class="ops-f"><span>&nbsp;</span><span><button class="button primary small" id="bnew">＋ New post</button> <button class="button secondary small" id="bcsv">Export CSV</button></span></div></section>
        <section class="panel"><table class="ad-table ops-click"><thead><tr><th>Post & web address</th><th>Focus keyword</th><th>Words</th><th>Keywords</th><th>Status</th><th class="r"></th></tr></thead><tbody id="bbody"></tbody></table></section>`;
      const idx = () => { $('#idxNote').innerHTML = s.searchIndexing ? '✅ Search engines may index your public pages. Admin, checkout and account pages stay blocked.' : '⏸ Indexing is <b>off</b> (robots.txt blocks everything) — keep it off until you have reviewed every page, then switch it on at launch.'; };
      const draw = () => {
        const r = rows(); const all = keys.map(wc);
        $('#bk').innerHTML = `<div class="kpi"><span>Posts</span><b>${keys.length}</b></div><div class="kpi"><span>Published</span><b>${keys.filter(k => posts[k].published).length}</b></div><div class="kpi"><span>Avg. words</span><b>${Math.round(all.reduce((a, b) => a + b, 0) / Math.max(1, all.length))}</b></div><div class="kpi"><span>Under 350 words</span><b style="color:${all.filter(x => x < 350).length ? '#ef4444' : '#22c55e'}">${all.filter(x => x < 350).length}</b></div><div class="kpi"><span>With keywords</span><b>${keys.filter(k => kwList(posts[k]).length >= 5).length}</b></div>`;
        $('#bbody').innerHTML = r.map(k => { const p = posts[k], w = wc(k), kw = kwList(p); return `<tr data-k="${E(k)}"><td><b>${E(p.title)}</b><br><a class="mono" href="${E(pathOf(k, p))}" target="_blank" rel="noopener">${E(pathOf(k, p))}</a></td><td>${E(kw[0] || '—')}</td><td><span class="pill ${w >= 350 ? 'st-paid' : 'st-failed'}">${w}</span></td><td>${kw.length}</td><td>${p.published ? '<span class="pill st-paid">Published</span>' : '<span class="pill st-free">Draft</span>'}</td><td class="r"><button class="button small primary" data-edit="${E(k)}">Edit</button></td></tr>`; }).join('') || '<tr><td colspan="6">No posts match.</td></tr>';
      };
      $('#bq').oninput = e => { B.q = e.target.value; draw(); }; $('#bc').onchange = e => { B.cat = e.target.value; draw(); }; $('#bs').onchange = e => { B.st = e.target.value; draw(); };
      $('#bcsv').onclick = () => download('cvtara-posts.csv', csv(keys.map(k => ({ title: posts[k].title, url: s.siteUrl + pathOf(k, posts[k]), focus: kwList(posts[k])[0], keywords: posts[k].keywords, words: wc(k), published: posts[k].published })), ['title', 'url', 'focus', 'keywords', 'words', 'published']), 'text/csv');
      $('#bbody').onclick = e => { const b = e.target.closest('[data-edit]'); if (b) editor(b.dataset.edit); else { const tr = e.target.closest('tr[data-k]'); if (tr && !e.target.closest('a')) editor(tr.dataset.k); } };
      $('#bnew').onclick = () => editor(null);
      $('#seoF').onsubmit = async e => { e.preventDefault(); const st = $('.ops-st', e.target); st.textContent = 'Saving…'; const f = e.target.elements; try { const data = { ...seo.data, sitemapEnabled: f.sitemapEnabled.checked, searchIndexing: f.searchIndexing.checked, siteUrl: f.siteUrl.value.trim() }; const r = await save('seo', data, seo.revision); seo.revision = r.revision ?? seo.revision + 1; seo.data = data; s.searchIndexing = data.searchIndexing; idx(); st.textContent = 'Saved.'; toast('Search engine settings saved'); } catch (x) { st.textContent = err(x); } };
      idx(); draw();

      /* ---------- premium editor ---------- */
      async function editor(slug) {
        const isNew = !slug, p0 = isNew ? { title: '', description: '', body: '', category: cats[0] || 'Resume formatting', type: 'Guide', published: false, imageUrl: '', imageAlt: '', faq: '', keywords: '' } : posts[slug];
        const faqRows = String(p0.faq || '').split('\n').map(l => l.split('|')).filter(a => a.length >= 2).map(a => [a[0].trim(), a.slice(1).join('|').trim()]);
        const m = modal(isNew ? 'New post' : 'Edit post', `<div class="ed">
          <div class="ed-main"><label>Title<input id="eTitle" maxlength="180" value="${E(p0.title)}" placeholder="Post title"></label>
            <div class="ed-url"><span>${E(s.siteUrl || '')}/guides/</span><input id="eSlug" ${isNew ? '' : 'readonly'} value="${E(slug || '')}" placeholder="web-address-of-the-post"><span>/</span></div>
            <label>Meta description <small id="eDc"></small><textarea id="eDesc" rows="2" maxlength="500">${E(p0.description || '')}</textarea></label>
            <div class="ed-kw"><label>Focus keyword<input id="eFocus" maxlength="120" value="${E(kwList(p0)[0] || '')}" placeholder="the one phrase people search"></label><label>Advanced keywords (comma separated)<input id="eKw" maxlength="800" value="${E(kwList(p0).slice(1).join(', '))}" placeholder="long-tail phrases"></label></div>
            <div class="ed-tools" role="toolbar" aria-label="Formatting"><button type="button" data-fx="h2">H2</button><button type="button" data-fx="h3">H3</button><button type="button" data-fx="b"><b>B</b></button><button type="button" data-fx="i"><i>I</i></button><button type="button" data-fx="ul">• List</button><button type="button" data-fx="quote">❝</button><button type="button" data-fx="link">🔗 Link</button><button type="button" data-fx="img">🖼 Image</button><span class="grow"></span><small id="eWc"></small></div>
            <div class="ed-split"><textarea id="eBody" spellcheck="true" aria-label="Article body (Markdown)">${E(p0.body || '')}</textarea><div class="ed-prev" id="ePrev" aria-label="Preview"></div></div>
            <input type="file" id="eFile" accept="image/*" hidden>
            <h4>Questions & answers (shown on the page and in search)</h4><div id="eFaq"></div><button type="button" class="button secondary small" id="eFaqAdd">＋ Add question</button></div>
          <aside class="ed-side"><div class="ed-card"><h4>SEO score <span id="eScore"></span></h4><ul id="eChecks" class="ed-checks"></ul></div>
            <div class="ed-card"><h4>Cover image</h4><div class="ed-cover" id="eCover"></div><label>Image address<input id="eImg" value="${E(p0.imageUrl || '')}" placeholder="/media/… or https://…"></label><label>Alt text<input id="eAlt" maxlength="200" value="${E(p0.imageAlt || '')}" placeholder="Describe the image (include the focus keyword)"></label><button type="button" class="button secondary small" id="eUp">⬆ Upload image</button></div>
            <div class="ed-card"><h4>Details</h4><label>Category<input id="eCat" list="eCats" maxlength="100" value="${E(p0.category || '')}"><datalist id="eCats">${cats.map(c => `<option value="${E(c)}">`).join('')}</datalist></label><label>Type<select id="eType"><option ${p0.type !== 'Calculator' ? 'selected' : ''}>Guide</option><option ${p0.type === 'Calculator' ? 'selected' : ''}>Calculator</option></select></label><label class="ops-check"><input type="checkbox" id="ePub" ${p0.published ? 'checked' : ''}> Published</label></div>
            <div class="ed-actions"><button class="button primary" id="eSave">Save post</button>${isNew ? '' : `<a class="button secondary" href="${E(pathOf(slug, p0))}" target="_blank" rel="noopener">View live ↗</a>`}<span class="ops-st" id="eSt" role="status"></span></div></aside></div>`, { wide: true, xl: true });
        m.el.classList.add('xl'); const g = id => $('#' + id, m.el);
        const body = g('eBody'); let faq = faqRows.length ? faqRows : [['', '']];
        const drawFaq = () => { g('eFaq').innerHTML = faq.map((r, i) => `<div class="ed-faq"><input data-q="${i}" placeholder="Question?" value="${E(r[0])}" maxlength="200"><textarea data-a="${i}" rows="2" placeholder="Answer" maxlength="600">${E(r[1])}</textarea><button type="button" class="button small secondary" data-rm="${i}" aria-label="Remove">✕</button></div>`).join(''); };
        drawFaq(); g('eFaqAdd').onclick = () => { faq.push(['', '']); drawFaq(); };
        g('eFaq').oninput = e => { const q = e.target.dataset.q, a = e.target.dataset.a; if (q !== undefined) faq[q][0] = e.target.value; if (a !== undefined) faq[a][1] = e.target.value; analyse(); };
        g('eFaq').onclick = e => { const b = e.target.closest('[data-rm]'); if (b) { faq.splice(Number(b.dataset.rm), 1); if (!faq.length) faq = [['', '']]; drawFaq(); analyse(); } };
        const state = () => { const focus = g('eFocus').value.trim(); const adv = g('eKw').value.split(',').map(x => x.trim()).filter(Boolean); const kws = [focus, ...adv].filter(Boolean).filter((x, i, a) => a.findIndex(y => y.toLowerCase() === x.toLowerCase()) === i); return { title: g('eTitle').value.trim(), description: g('eDesc').value.trim(), body: body.value, focus, kws, slug: g('eSlug').value.trim(), img: g('eImg').value.trim(), alt: g('eAlt').value.trim(), faqS: faq.filter(r => r[0].trim() && r[1].trim()).map(r => r[0].trim() + ' | ' + r[1].trim()).join('\n') }; };
        const analyse = () => {
          const v = state(), w = words(v.body), f = v.focus.toLowerCase(), first = v.body.split(/\s+/).slice(0, 100).join(' ').toLowerCase(), heads = (v.body.match(/^#{2,3} .*/gm) || []).join(' ').toLowerCase(), dens = f && w ? ((v.body.toLowerCase().split(f).length - 1) * f.split(/\s+/).length / w * 100) : 0;
          const checks = [['Title is 20–70 characters', v.title.length >= 20 && v.title.length <= 70], ['Meta description is 120–160 characters', v.description.length >= 120 && v.description.length <= 160], ['At least 350 words', w >= 350], ['Focus keyword in the title', !!f && v.title.toLowerCase().includes(f)], ['Focus keyword in the first 100 words', !!f && first.includes(f)], ['Focus keyword in a heading', !!f && heads.includes(f.split(' ').slice(0, 2).join(' '))], ['Focus keyword in the description', !!f && v.description.toLowerCase().includes(f)], ['5+ advanced keywords', v.kws.length >= 6], ['Keyword density 0.5–2.5%', dens >= .5 && dens <= 2.5], ['Link to another page', /\]\(\/(?!\/)/.test(v.body)], ['Cover image with alt text', !!v.img && v.alt.length >= 8], ['3+ questions & answers', v.faqS.split('\n').filter(Boolean).length >= 3], ['Readable web address', /^[a-z0-9][a-z0-9/-]{2,100}$/.test(v.slug)]];
          const sc = Math.round(checks.filter(c => c[1]).length / checks.length * 100);
          g('eScore').innerHTML = `<b class="${sc >= 80 ? 'ok' : sc >= 55 ? 'mid' : 'bad'}">${sc}/100</b>`; g('eChecks').innerHTML = checks.map(c => `<li class="${c[1] ? 'ok' : 'no'}">${c[1] ? '✓' : '○'} ${c[0]}</li>`).join('');
          g('eWc').textContent = `${w} words · ${Math.max(1, Math.ceil(w / 200))} min read` + (w < 350 ? ' · aim for 350+' : ''); g('eDc').textContent = v.description.length + ' / 160';
          g('eCover').innerHTML = v.img ? `<img src="${E(v.img)}" alt="" onerror="this.replaceWith(document.createTextNode('Image could not be loaded'))">` : '<span>No cover image</span>';
        };
        let pt; const prev = async () => { const { markdown } = await fmt(); g('ePrev').innerHTML = `<h1>${E(g('eTitle').value)}</h1>` + markdown(body.value); };
        const onIn = () => { analyse(); clearTimeout(pt); pt = setTimeout(prev, 200); }; m.el.addEventListener('input', onIn); onIn();
        if (isNew) g('eTitle').addEventListener('input', () => { if (!g('eSlug').dataset.touched) g('eSlug').value = g('eTitle').value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80); }), g('eSlug').addEventListener('input', () => { g('eSlug').dataset.touched = '1'; });
        const wrap = (a, b, ph) => { const s0 = body.selectionStart, e0 = body.selectionEnd, sel = body.value.slice(s0, e0) || ph; body.setRangeText(a + sel + b, s0, e0, 'select'); body.focus(); onIn(); };
        const line = pre => { const s0 = body.selectionStart; const ls = body.value.lastIndexOf('\n', s0 - 1) + 1; body.setRangeText(pre, ls, ls, 'end'); body.focus(); onIn(); };
        const upload = async file => { g('eSt').textContent = 'Optimising image…'; try { const url = await uploadImage(file); g('eSt').textContent = ''; return url; } catch (x) { g('eSt').textContent = err(x); return null; } };
        g('eFile').onchange = async e => { const f = e.target.files[0]; e.target.value = ''; if (!f) return; const url = await upload(f); if (!url) return; if (g('eFile').dataset.mode === 'cover') { g('eImg').value = url; } else { body.setRangeText(`\n\n![${g('eAlt').value || 'Describe the image'}](${url})\n\n`, body.selectionStart, body.selectionEnd, 'end'); } onIn(); toast('Image added'); };
        g('eUp').onclick = () => { g('eFile').dataset.mode = 'cover'; g('eFile').click(); };
        m.el.querySelector('.ed-tools').onclick = e => {
          const b = e.target.closest('[data-fx]'); if (!b) return; const x = b.dataset.fx;
          if (x === 'h2') line('## '); else if (x === 'h3') line('### '); else if (x === 'ul') line('- '); else if (x === 'quote') line('> '); else if (x === 'b') wrap('**', '**', 'bold text'); else if (x === 'i') wrap('*', '*', 'italic text');
          else if (x === 'img') { g('eFile').dataset.mode = 'body'; g('eFile').click(); }
          else if (x === 'link') { const mm = modal('Insert link', `<form class="ops-form"><label class="wide">Address<input name="u" required placeholder="/guides/resume/ats-friendly-format-india/ or https://…"></label><label class="wide">Link text<input name="t" placeholder="${E(body.value.slice(body.selectionStart, body.selectionEnd) || 'read more')}"></label><div class="wide"><button class="button primary">Insert</button></div></form>`); mm.body.querySelector('form').onsubmit = ev => { ev.preventDefault(); const u = ev.target.u.value.trim(); if (!/^(https:\/\/|\/(?!\/))/.test(u)) { toast('Use an https:// address or a path starting with /', true); return; } const t = ev.target.t.value.trim() || body.value.slice(body.selectionStart, body.selectionEnd) || 'read more'; body.setRangeText(`[${t}](${u})`, body.selectionStart, body.selectionEnd, 'end'); mm.close(); onIn(); }; }
        };
        g('eSave').onclick = async () => {
          const v = state(), st = g('eSt'); const sl = isNew ? v.slug : slug;
          if (!v.title) return (st.textContent = 'Add a title.');
          if (!/^[a-z0-9][a-z0-9/-]{0,160}$/.test(sl) || sl.includes('..')) return (st.textContent = 'Web address: lowercase letters, numbers and hyphens only.');
          if (isNew && posts[sl]) return (st.textContent = 'A post with this address already exists.');
          if (v.img && !/^(https:\/\/|\/(?!\/))/.test(v.img)) return (st.textContent = 'Image address must start with https:// or /');
          st.textContent = 'Saving…';
          const next = { ...posts, [sl]: { ...(posts[sl] || {}), id: (posts[sl] || {}).id ?? Date.now(), slug: sl, title: v.title, description: v.description, body: v.body, category: g('eCat').value.trim() || 'General', type: g('eType').value, published: g('ePub').checked, keywords: v.kws.join(', '), faq: v.faqS, imageUrl: v.img, imageAlt: v.alt, author: (posts[sl] || {}).author || 'CVTara Editorial Team' } };
          try { const r = await save('blog', next, blog.revision); blog.revision = r.revision ?? blog.revision + 1; Object.assign(blog.data, next); toast('Post saved'); m.close(); bloghub(); } catch (x) { st.textContent = err(x); }
        };
      }
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }
  async function uploadImage(file) {
    if (!/^image\//.test(file.type)) throw new Error('Choose an image file.');
    const bmp = await createImageBitmap(file); let scale = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
    for (let round = 0; round < 4; round++) {
      const w = Math.max(1, Math.round(bmp.width * scale)), h = Math.max(1, Math.round(bmp.height * scale)), c = document.createElement('canvas'); c.width = w; c.height = h; c.getContext('2d').drawImage(bmp, 0, 0, w, h);
      for (const q of [.85, .72, .6, .48]) {
        const blob = await new Promise(r => c.toBlob(r, 'image/webp', q));
        if (blob && blob.type === 'image/webp' && blob.size <= 480000) {
          const b64 = await new Promise((res, rej) => { const fr = new FileReader(); fr.onload = () => res(String(fr.result).split(',')[1]); fr.onerror = rej; fr.readAsDataURL(blob); });
          const r = await api('/api/admin/media', { method: 'POST', body: { base64: b64 } }); return r.url;
        }
      }
      scale *= .75;
    }
    throw new Error('That image is too large even after compression. Try a smaller picture.');
  }

  /* ======================= FOOTER & POLICIES ======================= */
  const DOCS = [['about', 'About us', 'about.html'], ['terms', 'Terms & Conditions', 'terms.html'], ['privacy', 'Privacy Policy', 'privacy.html'], ['refund', 'Refund Policy', 'refund.html']];
  async function policies() {
    const h = $('#admin-policies'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div><div class="ops-skel"></div>';
    try {
      const [lg, urls] = await Promise.all([api('/api/admin/content/legal'), api('/api/admin/site-urls')]);
      const l = { ...lg.data }; let tab = 'about';
      const f = (n, lab, v, o = {}) => `<label class="${o.wide ? 'wide' : ''}">${lab}${o.area ? `<textarea name="${n}" rows="${o.rows || 3}" maxlength="${o.max || 1500}">${E(v)}</textarea>` : `<input name="${n}" ${o.type ? `type="${o.type}"` : ''} maxlength="${o.max || 200}" value="${E(v)}">`}</label>`;
      h.innerHTML = `<div class="pg-head"><h2>Footer & policies</h2><p>Your company details and legal pages — written for you, ready to publish, editable any time. Contact: <b>support@cvtara.com</b> · Bangalore, India.</p></div>
        <div class="sd-grid"><section class="panel panel-pad"><h3>Company & contact details</h3><form id="coF" class="ops-form">${f('supportEmail', 'Support email', l.supportEmail, { type: 'email' })}${f('grievanceEmail', 'Grievance email', l.grievanceEmail, { type: 'email' })}${f('phone', 'Support phone (optional)', l.phone)}${f('hours', 'Support hours', l.hours)}${f('address', 'Address', l.address, { wide: true, area: true, rows: 2 })}${f('footerText', 'Footer introduction', l.footerText, { wide: true, area: true, rows: 2 })}${f('copyright', 'Copyright line', l.copyright)}${f('contactIntro', 'Contact page introduction', l.contactIntro, { wide: true, area: true, rows: 2 })}<div class="wide"><button class="button primary">Save details</button> <span class="ops-st" role="status"></span></div></form></section>
        <section class="panel panel-pad"><h3>Legal pages</h3><div class="ops-tabs" id="lgTabs">${DOCS.map(d => `<button data-t="${d[0]}" class="${d[0] === tab ? 'on' : ''}">${d[1]}</button>`).join('')}</div><div class="ops-bar" style="margin:0 0 8px"><button class="button secondary small" id="lgGen">✨ Auto-generate all from my details</button><a class="button secondary small" id="lgOpen" target="_blank" rel="noopener" href="/about.html">View page ↗</a><span class="ops-st" id="lgSt" role="status"></span></div><div class="ed-split"><textarea id="lgText" spellcheck="true" aria-label="Page text (Markdown)"></textarea><div class="ed-prev" id="lgPrev"></div></div><div style="margin-top:12px"><button class="button primary" id="lgSave">Save legal pages</button></div></section></div>
        <h3 class="ops-h3">All website URLs <small class="tag tag-blue">${E(urls.base)}</small></h3><section class="panel panel-pad"><div class="ops-filters" style="margin:0 0 10px"><input id="uq" type="search" placeholder="Filter URLs"><span id="uc"></span><button class="button secondary small" id="ucopy">Copy all</button><button class="button primary small" id="ucsv">Export CSV</button></div><p class="ops-note" style="margin-top:0">Tick each URL as you confirm it during final review. Ticks are remembered on this device.</p><div id="ulist"></div></section>`;
      const co = $('#coF'); co.onsubmit = async e => { e.preventDefault(); const st = $('.ops-st', co); st.textContent = 'Saving…'; Object.assign(l, Object.fromEntries(new FormData(co))); try { const r = await save('legal', l, lg.revision); lg.revision = r.revision ?? lg.revision + 1; st.textContent = 'Saved.'; toast('Details saved'); } catch (x) { st.textContent = err(x); } };
      const ta = $('#lgText'), pv = async () => { const { markdown } = await fmt(); $('#lgPrev').innerHTML = markdown(ta.value); };
      const load = () => { ta.value = l[tab] || ''; $('#lgOpen').href = '/' + DOCS.find(d => d[0] === tab)[2]; pv(); };
      ta.oninput = () => { l[tab] = ta.value; clearTimeout(ta._t); ta._t = setTimeout(pv, 200); };
      $('#lgTabs').onclick = e => { const b = e.target.closest('[data-t]'); if (!b) return; tab = b.dataset.t; $$('#lgTabs button').forEach(x => x.classList.toggle('on', x === b)); load(); };
      $('#lgGen').onclick = async () => { const st = $('#lgSt'); st.textContent = 'Generating…'; try { Object.assign(l, Object.fromEntries(new FormData(co))); const r = await api('/api/admin/legal/generate'); Object.assign(l, r.policies); st.textContent = 'Generated from your details — review, then Save.'; load(); } catch (x) { st.textContent = err(x); } };
      $('#lgSave').onclick = async () => { const st = $('#lgSt'); st.textContent = 'Saving…'; try { const r = await save('legal', l, lg.revision); lg.revision = r.revision ?? lg.revision + 1; st.textContent = 'Saved — pages updated.'; toast('Legal pages saved'); } catch (x) { st.textContent = err(x); } };
      load();
      let rev = {}; try { rev = JSON.parse(localStorage.getItem('cvtara-url-review') || '{}'); } catch (e) { rev = {}; }
      const allU = [...urls.urls.map(u => ({ ...u, pub: true })), ...urls.private.map(u => ({ ...u, group: 'Private (not indexed)', pub: false }))];
      const drawU = () => { const q = ($('#uq').value || '').toLowerCase(), rows = allU.filter(u => !q || (u.label + u.url).toLowerCase().includes(q)), groups = [...new Set(rows.map(u => u.group))]; $('#uc').textContent = `${Object.values(rev).filter(Boolean).length} of ${allU.length} reviewed`; $('#ulist').innerHTML = groups.map(g => `<details class="url-grp" ${/Main|Legal|Private/.test(g) ? 'open' : ''}><summary>${E(g)} <span class="tag">${rows.filter(u => u.group === g).length}</span></summary><table class="ad-table"><tbody>${rows.filter(u => u.group === g).map(u => `<tr><td style="width:36px"><input type="checkbox" data-r="${E(u.url)}" ${rev[u.url] ? 'checked' : ''} aria-label="Reviewed"></td><td>${E(u.label)}</td><td><a class="mono" href="${E(u.url)}" target="_blank" rel="noopener">${E(u.url)}</a></td><td class="r"><button class="button small secondary" data-c="${E(u.url)}">Copy</button></td></tr>`).join('')}</tbody></table></details>`).join(''); };
      $('#uq').oninput = drawU; drawU();
      $('#ulist').onchange = e => { const c = e.target.closest('[data-r]'); if (c) { rev[c.dataset.r] = c.checked; try { localStorage.setItem('cvtara-url-review', JSON.stringify(rev)); } catch (x) { /* ignore */ } drawU(); } };
      const copy = t => (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(() => toast('Copied'), () => toast('Copy is not available here — select the text instead', true));
      $('#ulist').onclick = e => { const b = e.target.closest('[data-c]'); if (b) copy(b.dataset.c); };
      $('#ucopy').onclick = () => copy(allU.map(u => u.url).join('\n')); $('#ucsv').onclick = () => download('cvtara-urls.csv', csv(allU.map(u => ({ group: u.group, page: u.label, url: u.url, indexed: u.pub ? 'yes' : 'no' })), ['group', 'page', 'url', 'indexed']), 'text/csv');
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }

  /* ======================= SETUP & HEALTH ======================= */
  const CHK = { previewOff: ['Demo/preview mode is off', 'Remove CVTARA_PREVIEW from the .env file; demo mode fakes payments and skips the email confirmation.'], database: ['Database connection', 'Check the database details in hPanel.'], storageWritable: ['Private storage is writable', 'Make the storage folder writable (755/775).'], https: ['Secure HTTPS address', 'Set the website address to https://cvtara.com and install the free SSL.'], domain: ['Final domain is cvtara.com', 'Open Blog & SEO and set the website address to https://cvtara.com.'], privateFolder: ['App folder is outside public_html', 'Move the app folder next to public_html, never inside it.'], scheduler: ['Scheduler (cron) is running', 'Add the cron job (every minute) from Setup tools → cron command.'], emailConfigured: ['Email sender is configured', 'Enter the support@cvtara.com mailbox password in User Mailer / installer.'], paymentsConfigured: ['Razorpay keys are connected', 'Add Razorpay key ID and secret in Integrations → Payments, then verify.'], debugOff: ['Debug mode is off', 'Set APP_DEBUG=false in the .env file.'] };
  async function health() {
    const h = $('#admin-health'); if (!h) return; h.innerHTML = '<div class="ops-skel"></div><div class="ops-skel"></div>';
    try {
      const d = await api('/api/admin/health/history'), t = d.today, hist = d.history || [];
      const col = s => s === 'healthy' ? '#22c55e' : s === 'attention' ? '#f59e0b' : '#ef4444';
      h.innerHTML = `<div class="pg-head"><h2>Setup & health</h2><p>A health check runs every day and is kept for 60 days, so you can see exactly when something changed.</p></div>
        <section class="panel panel-pad hl-top" style="border-left:5px solid ${col(t.status)}"><div><b class="hl-st" style="color:${col(t.status)}">${t.status === 'healthy' ? '✅ All systems healthy' : t.status === 'attention' ? '⚠️ Needs attention' : '🔴 System problem'}</b><small>Checked ${dtm(t.at)}</small></div><div><button class="button primary" id="hRun">Run check now</button> <button class="button secondary" data-admin-view="setup">Setup tools (cron, webhooks, tests)</button> <span class="ops-st" role="status"></span></div></section>
        <div class="ops-kpis"><div class="kpi"><span>Users</span><b>${t.metrics.users}</b></div><div class="kpi"><span>Paid & active</span><b>${t.metrics.paidActive}</b></div><div class="kpi"><span>Emails queued</span><b>${t.metrics.mailQueued}</b></div><div class="kpi"><span>Failed emails (24h)</span><b style="color:${t.metrics.mailFailed24h ? '#ef4444' : 'inherit'}">${t.metrics.mailFailed24h}</b></div><div class="kpi"><span>Errors (24h)</span><b style="color:${t.metrics.errors24h ? '#ef4444' : 'inherit'}">${t.metrics.errors24h}</b></div>${t.metrics.diskFreePct != null ? `<div class="kpi"><span>Disk free</span><b>${t.metrics.diskFreePct}%</b></div>` : ''}</div>
        <section class="panel"><table class="ad-table"><thead><tr><th>Check</th><th>Result</th><th>What to do</th></tr></thead><tbody>${Object.entries(t.checks).map(([k, v]) => `<tr><td><b>${E((CHK[k] || [k])[0])}</b></td><td>${v ? '<span class="pill st-paid">OK</span>' : '<span class="pill st-failed">Fix</span>'}</td><td>${v ? '—' : E((CHK[k] || [, ''])[1])}</td></tr>`).join('')}</tbody></table></section>
        <h3 class="ops-h3">Daily history</h3><section class="panel"><table class="ad-table"><thead><tr><th>Date</th><th>Status</th><th>Open issues</th><th>Errors</th><th>Failed emails</th><th>Paid users</th></tr></thead><tbody>${hist.slice(0, 30).map(x => `<tr><td>${E(x.date)}</td><td><span class="dot" style="background:${col(x.status)}"></span> ${E(x.status)}</td><td>${(x.problems || []).length}</td><td>${x.metrics.errors24h}</td><td>${x.metrics.mailFailed24h}</td><td>${x.metrics.paidActive}</td></tr>`).join('') || '<tr><td colspan="6">The first daily check will appear here.</td></tr>'}</tbody></table></section>
        <h3 class="ops-h3">Moving to Hostinger — one link sets everything up</h3><section class="panel panel-pad"><ol class="mv-steps"><li><b>Upload</b> the package contents into <code>public_html</code> (the installer unpacks the private app folder beside it).</li><li>Open <a class="mono" href="/install.php" target="_blank" rel="noopener">https://cvtara.com/install.php</a> — the only link you need.</li><li>Enter the installation key, database details from hPanel, your owner login and the <b>support@cvtara.com</b> mailbox password. The installer creates the database, owner account, email sending, legal pages and the cvtara.com settings, then locks itself.</li><li>Sign in, then add the <b>Razorpay</b> keys (Settings → Integrations) and the <b>cron job</b> (every minute) shown in Setup tools.</li><li>Check this page is green, review every page in <b>Footer & policies → All website URLs</b>, then switch on search indexing in <b>Blog & SEO</b>.</li></ol></section>`;
      $('#hRun').onclick = async () => { const st = $('.ops-st', h); st.textContent = 'Checking…'; try { await api('/api/admin/health/run', { method: 'POST', body: {} }); health(); } catch (x) { st.textContent = err(x); } };
    } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; }
  }

  K.register('bloghub', bloghub, ['📝', 'Blog & SEO', 'Every post with its web address, keywords and a premium editor.']);
  K.register('policies', policies, ['⚖️', 'Footer & policies', 'Company details, legal pages and every website URL.']);
  K.register('health', health, ['🩺', 'Setup & health', 'Daily health checks and the one-link Hostinger setup.']);
})();
