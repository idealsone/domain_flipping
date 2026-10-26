/* Owner console: Outreach — opted-in contacts mailed from separate sending mailboxes, with warm-up, follow-ups and stop rules. */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, parse, modal, toast, ensureRecent, download } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const F = { status: 'all', list: 'all', q: '', page: 1 };
  const STATUS = { queued: 'Waiting', active: 'In sequence', done: 'Sequence finished', registered: 'Signed up', unsubscribed: 'Unsubscribed', bounced: 'Bounced', removed: 'Removed' };
  const tone = s => s === 'registered' ? 'ok' : s === 'bounced' || s === 'unsubscribed' ? 'bad' : 'mid';
  const when = s => { if (!s) return '—'; const d = parse(s); return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }); };
  const pct = (a, b) => b ? (Math.round(a / b * 1000) / 10) + '%' : '—';
  const inr = p => '₹' + Math.round(Number(p || 0) / 100).toLocaleString('en-IN');
  let D = null, CFG = null, CUR = 'students';
  const steps0 = () => CFG.lists[CUR].steps;

  /* Ready-made sequences. Short personal subject, one main link, a real reason to click, a polite follow-up.
     The recommended sequence for each list comes from the server (D.defaults), so there is one copy of it. */
  const LIB = {
    fresher: { name: 'Freshers: first job', steps: [
      { subject: '{{name}}, is your resume getting replies?', delayDays: 0, body: "Hi {{name}},\n\nMost freshers send the same resume to every job, and applicant tracking systems filter it out before a person reads it.\n\nCVTara checks your resume against a job description and shows the exact keywords you are missing, then helps you fix them in a few minutes.\n\nTry it on one job you want: {{ats_url}}\n\n{{sender_name}}" },
      { subject: 'Re: is your resume getting replies?', delayDays: 3, body: "Hi {{name}},\n\nQuick follow-up. If you have a job posting open right now, paste it into CVTara and you will see your match score in under a minute.\n\n{{ats_url}}\n\nNo pressure if the timing is wrong." },
      { subject: 'last note from me', delayDays: 5, body: "Hi {{name}},\n\nI won't keep writing. If a cleaner, ATS-friendly resume would help with your job search, CVTara has a guided template library with designs made for freshers. Building and checking cost nothing; a clean download starts at {{from_price}}, paid once.\n\n{{site_url}}\n\nAll the best with your applications." } ] },
    experienced: { name: 'Experienced: next role', steps: [
      { subject: '{{name}}, one resume per job takes 5 minutes now', delayDays: 0, body: "Hi {{name}},\n\nWhen you are applying for a new role, tailoring your resume to each posting makes a real difference, but it takes time.\n\nCVTara keeps one main resume and makes a tailored version for each job, with a cover letter to match, so each application takes minutes.\n\nHave a look: {{site_url}}\n\n{{sender_name}}" },
      { subject: 'Re: one resume per job', delayDays: 4, body: "Hi {{name}},\n\nFollowing up in case this got buried. You can check how well your current resume matches a job you like before you apply:\n\n{{ats_url}}\n\nIf you're not looking right now, feel free to ignore this." } ] },
    ats: { name: 'Free ATS check', steps: [
      { subject: 'your resume vs. the job description', delayDays: 0, body: "Hi {{name}},\n\nDo you know how your resume scores against the jobs you apply for?\n\nCVTara compares the two and shows your match score, the missing keywords and what to change. The first check costs nothing.\n\nRun yours here: {{ats_url}}\n\n{{sender_name}}" },
      { subject: 'Re: your resume vs. the job description', delayDays: 3, body: "Hi {{name}},\n\nJust bumping this up. It takes under a minute: paste a job posting, pick your resume, see the score.\n\n{{ats_url}}" } ] },
    festival: { name: 'Festival offer (uses the offer live in Offers & festivals)', steps: [
      { subject: '{{name}}, festive prices on CVTara resumes', delayDays: 0, body: "Hi {{name}},\n\nFor the festive season CVTara has an offer on: {{offer_line}}\n\nIt covers ATS-friendly resume templates, a tailored version for each job and matching cover letters, paid once with no auto-renewal.\n\nSee the packages: {{plans_url}}\n\n{{sender_name}}" },
      { subject: 'Re: festive prices', delayDays: 3, body: "Hi {{name}},\n\nA short reminder about the festive offer: {{offer_line}}\n\n{{plans_url}}\n\nWishing you and your family a happy festival." } ] }
  };
  const LIB_COLLEGES = {
    session: { name: 'Free resume session for the batch', steps: [
      { subject: 'A free resume session for {{college}}', delayDays: 0, body: "Hello {{name}},\n\nI'm from CVTara, a resume platform built by an HR team in Bengaluru. Before this placement season we are running free 30-minute online sessions for final-year students on writing a resume that passes company screening software (ATS).\n\nFor {{college}} it would include:\n- a live session for the batch, online, at a time that suits you\n- a free ATS resume check for every student\n- a short checklist you can share on your placement group\n\nWould you like a slot for {{college}}? Just reply with a convenient week.\n\n{{sender_name}}" },
      { subject: 'Re: A free resume session for {{college}}', delayDays: 5, body: "Hello {{name}},\n\nFollowing up in case my note got buried. The students can already use the free check here: {{ats_url}}\n\nIf a session would help, a one-line reply with a week is all I need.\n\n{{sender_name}}" } ] }
  };
  const lib = () => CUR === 'colleges' ? { recommended: { name: 'Recommended: free ATS check for students', steps: D.defaults.colleges.steps }, ...LIB_COLLEGES } : { recommended: { name: 'Recommended: placement season (designed)', steps: D.defaults.students.steps }, ...LIB };

  const TIPS = ['Send only to people who agreed to hear from you. Cold or bought lists get mailboxes suspended. For college data, ask the placement cell to tell students first.', 'Keep subjects short and personal. Avoid capitals, "!!!" and words like guarantee or winner.', 'Keep designed mails light: one button, no big images. Mail to placement officers works best plain, like a note from a person.', 'Add 2 or 3 of your own Gmail or Outlook addresses for warm-up, and open and reply to a few warm-up notes now and then.', 'Students who sign up get their own CVTara referral link, so happy students can bring friends.', 'Use {{name}} so every mail is personal, and keep each mail under about 120 words.', 'Send a test to a Gmail address first and check it lands in the inbox, not spam.', 'If bounces go above 2% or anyone complains, pause and clean the list.'];

  async function outreach() {
    const h = $('#admin-outreach'); if (!h) return;
    if (!D) h.innerHTML = '<div class="ops-skel"></div>';
    try { D = await api('/api/admin/outreach'); } catch (e) { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; return; }
    CFG = JSON.parse(JSON.stringify({ lists: D.config.lists, warmup: D.config.warmup }));
    const c = D.counts || {}, f = D.funnel || {}, total = Object.values(c).reduce((a, b) => a + Number(b), 0);
    const live = D.senders.filter(s => s.enabled).length;
    const days = D.capacityToday ? Math.ceil(D.pending / D.capacityToday) : null;
    const warn = [];
    if (!D.cronOk) warn.push('The scheduled task is not running, so nothing will be sent. Check the cron job in Settings → Health.');
    if (D.config.enabled && !live) warn.push('Sending is on but no mailbox is active. Add or resume a mailbox.');
    D.senders.filter(s => !s.enabled && s.pausedReason).forEach(s => warn.push(s.email + ': ' + s.pausedReason));
    h.innerHTML = `<div class="pg-head"><p>Mail opted-in contacts from separate mailboxes on another domain (for example Hostinger mailboxes on cvtara.in). Each mailbox warms up on its own, mail goes out one at a time inside the send window, and anyone who signs up, unsubscribes or bounces is never mailed again. Account mail from CVTara is not affected.</p></div>
      ${warn.map(w => `<div class="panel panel-pad" role="alert" style="border-left:4px solid #dc2626;margin-bottom:10px">${E(w)}</div>`).join('')}
      <div class="ops-kpis">
        <div class="kpi"><span>Sending</span><b style="font-size:16px">${D.config.enabled ? '🟢 On' : '⚪ Off'}</b></div>
        <div class="kpi"><span>Can send today</span><b>${D.capacityToday}</b></div>
        <div class="kpi"><span>Waiting to be mailed</span><b>${D.pending.toLocaleString('en-IN')}</b>${days ? `<small>~${days} day${days === 1 ? '' : 's'} at today's pace</small>` : ''}</div>
        <div class="kpi"><span>Reached</span><b>${(f.reached || 0).toLocaleString('en-IN')}</b></div>
        <div class="kpi"><span>Clicked</span><b>${f.clicked || 0}</b><small>${pct(f.clicked || 0, f.reached)}</small></div>
        <div class="kpi"><span>Signed up</span><b>${f.registered || 0}</b><small>${pct(f.registered || 0, f.reached)}</small></div>
        <div class="kpi"><span>Paid · revenue</span><b>${f.paid || 0}</b><small>${inr(f.revenuePaise)}</small></div>
        <div class="kpi"><span>Bounced · unsubscribed</span><b>${(c.bounced || 0)} · ${(c.unsubscribed || 0)}</b><small>${pct((c.bounced || 0), f.reached)} bounced</small></div>
      </div>

      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Your two lists</h3><div style="overflow:auto"><table class="ad-table" id="orLists"><thead><tr><th>List</th><th>Contacts</th><th>Waiting</th><th>Reached</th><th>Clicked</th><th>Shared on WhatsApp</th><th>Signed up</th></tr></thead><tbody>${Object.entries(D.lists).map(([k, l]) => { const b = D.byList[k] || {}; return `<tr><td><b>${E(l)}</b></td><td>${(b.total || 0).toLocaleString('en-IN')}</td><td>${(b.waiting || 0).toLocaleString('en-IN')}</td><td>${(b.reached || 0).toLocaleString('en-IN')}</td><td>${b.clicked || 0} <small>${pct(b.clicked || 0, b.reached)}</small></td><td>${b.shares || 0}</td><td>${b.registered || 0} <small>${pct(b.registered || 0, b.reached)}</small></td></tr>`; }).join('')}</tbody></table></div>
        <p class="muted" style="margin:8px 0 0">Last 30 days: ${(D.mix30.campaign || 0).toLocaleString('en-IN')} campaign mails and ${(D.mix30.warmup || 0).toLocaleString('en-IN')} warm-up notes${D.mix30.campaign + D.mix30.warmup ? ` (${pct(D.mix30.warmup, D.mix30.campaign + D.mix30.warmup)} warm-up)` : ''}.</p></section>

      <section class="panel panel-pad" data-nokit><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center"><h3 style="margin:0">Sending mailboxes</h3><button class="button primary small" id="orAddBox">+ Add mailbox</button></div>
        <p class="muted" style="margin:6px 0 12px">Each mailbox starts at its first-day amount and adds the daily step until it reaches its cap. Hostinger's free mailboxes allow 100 a day and paid Business Starter 1,000, so a cap of 50 stays well inside both.</p>
        <div style="overflow:auto"><table class="ad-table" id="orBoxes"><thead><tr><th>Mailbox</th><th>Today</th><th>Warm-up</th><th>All time</th><th>Status</th><th></th></tr></thead><tbody>${D.senders.length ? D.senders.map(s => `<tr data-sid="${E(s.id)}">
          <td><b>${E(s.email)}</b><br><small>${E(s.fromName || '')} · ${E(s.host)}:${s.port}</small></td>
          <td>${s.sent24h} / ${s.todayLimit}<br><small>${s.warm24h ? s.warm24h + ' warm-up · ' : ''}last ${E(when(s.lastSentAt))}</small></td>
          <td>${s.startedOn ? 'Started ' + E(s.startedOn) : 'Not started'}<br><small>${s.warmupStart} → +${s.warmupStep}/day → cap ${s.dailyCap}${s.daysToCap ? ` · cap in ${s.daysToCap} day${s.daysToCap === 1 ? '' : 's'}` : ' · at cap'}</small></td>
          <td>${s.totals.sent} sent<br><small>${s.totals.bounced} bounced · ${s.totals.failed} failed</small></td>
          <td><span class="ad-pill ${s.enabled ? 'ok' : 'bad'}">${s.enabled ? 'Active' : 'Paused'}</span>${s.pausedReason ? `<br><small>${E(s.pausedReason)}</small>` : ''}</td>
          <td style="white-space:nowrap"><button class="button secondary small" data-act="test">Send test</button> <button class="button secondary small" data-act="edit">Edit</button> <button class="button secondary small" data-act="${s.enabled ? 'pause' : 'resume'}">${s.enabled ? 'Pause' : 'Resume'}</button> <button class="button secondary small" data-act="more" aria-label="More actions">⋯</button></td></tr>`).join('') : '<tr><td colspan="6">No mailboxes yet. Add the mailboxes you created on your sending domain.</td></tr>'}</tbody></table></div></section>

      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Mails in each sequence</h3>
        <div role="tablist" id="orTabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">${Object.entries(D.lists).map(([k, l]) => `<button role="tab" class="button small ${k === CUR ? 'primary' : 'secondary'}" data-list="${k}" aria-selected="${k === CUR}">${E(l)}</button>`).join('')}</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:8px"><label class="ops-f">Start from a template<select id="orLib"></select></label><label class="ops-f">Look<select id="orDesign"><option value="card">Designed: light card with a button</option><option value="plain">Plain: a personal note</option></select></label></div>
        <p class="muted" style="margin:0 0 12px"><small>Placeholders: {{name}} first name · {{college}} · {{designation}} · {{city}} · {{ats_url}} free ATS check · {{site_url}} home page · {{plans_url}} plans · {{share_url}} send to a friend on WhatsApp · {{sender_name}} · {{from_price}} lowest package price · {{offer_line}} the offer live now (empty when none, and the line is left out). Prices and offers come from Pricing and Offers & festivals, so mails never show an old price. Formatting: start with "# Heading", use "- " for bullets, and put [button: Label | {{ats_url}}] on its own line. Links are tracked.</small></p>
        <div id="orSteps"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 16px"><button class="button secondary small" id="orAddStep">+ Add follow-up</button></div>
        <h3 style="margin:20px 0 6px">Warm-up</h3>
        <p class="muted" style="margin:0 0 10px">Part of each mailbox's daily amount goes to short, link-free notes to your own addresses, mixed in with the campaign. That keeps each mailbox's history healthy. When no one is waiting to be mailed, warm-up uses the whole day. Open and reply to a few of these notes now and then; that is what mail providers notice.</p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">
          <label class="ops-f">Warm-up share of each day<select id="orWarmShare">${[0, 20, 30, 40, 50, 60].map(n => `<option value="${n}" ${n === Number(CFG.warmup.share) ? 'selected' : ''}>${n}% warm-up · ${100 - n}% campaign</option>`).join('')}</select></label>
          <label class="ops-f grow">Warm-up addresses, one per line (your own Gmail, Outlook or other mailboxes)<textarea id="orSeeds" rows="3" placeholder="yourname@gmail.com&#10;yourname@outlook.com">${E((CFG.warmup.seeds || []).join('\n'))}</textarea></label></div>
        ${(CFG.warmup.seeds || []).length ? '' : '<p class="muted"><small>No warm-up addresses yet, so every mail goes to the campaign.</small></p>'}
        <h3 style="margin:20px 0 6px">Send window and switch</h3>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
          <label class="ops-f">Send from (India time)<select id="orWs">${Array.from({ length: 24 }, (_, i) => `<option value="${i}" ${i === D.config.windowStart ? 'selected' : ''}>${String(i).padStart(2, '0')}:00</option>`).join('')}</select></label>
          <label class="ops-f">Until<select id="orWe">${Array.from({ length: 24 }, (_, i) => i + 1).map(i => `<option value="${i}" ${i === D.config.windowEnd ? 'selected' : ''}>${String(i).padStart(2, '0')}:00</option>`).join('')}</select></label>
          <label class="ops-f grow">Signature line under your name (optional)<input id="orFooter" maxlength="400" value="${E(D.config.footer || '')}" placeholder="CVTara · Bengaluru"></label>
          <label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" id="orOn" ${D.config.enabled ? 'checked' : ''}> Sending on</label>
          <button class="button primary" id="orSave">Save</button></div>
        <p role="status" id="orMsg" class="muted"></p></section>

      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Add contacts</h3>
        <p class="muted" style="margin-top:0">Paste or upload a CSV with an <b>email</b> column and, if you have them, <b>name</b>, <b>college</b>, <b>designation</b> and <b>city</b> columns (for placement officers, put the officer's name in <b>name</b>). Up to 20,000 rows at a time. Add each college as its own source, so you can stop one list without touching the others. Bad addresses, system addresses, domains that cannot receive mail, duplicates, existing CVTara users and anyone who unsubscribed or bounced before are left out.</p>
        <div style="display:grid;gap:10px">
          <label class="ops-f">CSV file<input type="file" id="orFile" accept=".csv,.txt,text/csv,text/plain"></label>
          <label class="ops-f">…or paste rows<textarea id="orText" rows="5" placeholder="email,name,college,designation,city&#10;tpo@abc.edu.in,Ramesh Kumar,ABC College of Engineering,Training and Placement Officer,Mysuru"></textarea></label>
          <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end"><label class="ops-f">Add to list<select id="orImpList">${Object.entries(D.lists).map(([k, l]) => `<option value="${k}" ${k === CUR ? 'selected' : ''}>${E(l)}</option>`).join('')}</select></label><label class="ops-f grow">Where these came from<input id="orSource" maxlength="120" placeholder="e.g. ABC College placement cell sign-ups, Oct 2026"></label>
          <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="orMx" checked> Check that each domain can receive mail</label></div>
          <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" id="orConsent"> <span>Everyone on this list agreed to hear from us, and I can show where and when.</span></label>
          <div><button class="button primary" id="orImport">Check and add</button></div>
          <div id="orImportRes" role="status"></div></div></section>

      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Contacts</h3>
        <div class="ops-filterbox"><label class="ops-f grow">Search<input id="orQ" type="search" value="${E(F.q)}" placeholder="Email, name, college or source…"></label>
          <label class="ops-f">List<select id="orL"><option value="all">Both lists</option>${Object.entries(D.lists).map(([k, l]) => `<option value="${k}" ${F.list === k ? 'selected' : ''}>${E(l)}</option>`).join('')}</select></label>
          <label class="ops-f">Status<select id="orS"><option value="all">All (${total})</option>${Object.entries(STATUS).map(([k, l]) => `<option value="${k}" ${F.status === k ? 'selected' : ''}>${E(l)} (${c[k] || 0})</option>`).join('')}</select></label>
          <button class="button secondary small" id="orExport">Download CSV</button> <button class="button secondary small" id="orSuppress">Mark bounced / unsubscribed</button> <button class="button secondary small" id="orStop">Stop everyone waiting</button></div>
        <div style="overflow:auto"><table class="ad-table" id="orT"><tbody><tr><td>Loading…</td></tr></tbody></table></div><div class="ops-pager" id="orP"></div></section>

      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Latest sends</h3><div style="overflow:auto"><table class="ad-table"><thead><tr><th>When</th><th>To</th><th>From</th><th>Mail</th><th>Result</th></tr></thead><tbody>${D.recent.length ? D.recent.map(x => `<tr><td>${E(when(x.created_at))}</td><td>${E(x.email || '')}</td><td>${E(x.sender || '')}</td><td>${x.kind === 'warmup' ? '<small>Warm-up</small> ' : x.step + '. '}${E(x.subject)}</td><td><span class="ad-pill ${x.status === 'sent' ? 'ok' : 'bad'}">${E(x.status)}</span>${x.error ? `<br><small>${E(x.error)}</small>` : ''}</td></tr>`).join('') : '<tr><td colspan="5">Nothing sent yet.</td></tr>'}</tbody></table></div></section>

      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Good practice</h3><ul style="margin:0;padding-left:18px;line-height:1.7">${TIPS.map(t => `<li>${E(t)}</li>`).join('')}</ul></section>`;
    steps(h); wire(h); list(h);
  }

  function steps(h) {
    const box = $('#orSteps', h), STEPS = steps0();
    $('#orLib', h).innerHTML = '<option value="">Choose…</option>' + Object.entries(lib()).map(([k, v]) => `<option value="${k}">${E(v.name)} (${v.steps.length} mails)</option>`).join('');
    $('#orDesign', h).value = CFG.lists[CUR].design;
    h.querySelectorAll('#orTabs [data-list]').forEach(b => { const on = b.dataset.list === CUR; b.classList.toggle('primary', on); b.classList.toggle('secondary', !on); b.setAttribute('aria-selected', String(on)); });
    box.innerHTML = STEPS.map((s, i) => `<fieldset class="panel panel-pad" style="margin:0 0 10px;border:1px solid var(--line,#e2e8f0)"><legend style="font-weight:700;padding:0 6px">${i === 0 ? 'First mail' : 'Follow-up ' + i}</legend>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><label class="ops-f grow">Subject<input data-k="subject" data-i="${i}" maxlength="160" value="${E(s.subject)}"></label>${i ? `<label class="ops-f">Days after the previous mail<input type="number" min="1" max="60" data-k="delayDays" data-i="${i}" value="${Number(s.delayDays) || 3}" style="width:90px"></label>` : ''}</div>
      <label class="ops-f">Message<textarea rows="9" data-k="body" data-i="${i}" maxlength="5000">${E(s.body)}</textarea></label>
      <div style="display:flex;gap:8px;margin-top:6px"><button class="button secondary small" data-prev="${i}">Preview</button>${i ? `<button class="button secondary small" data-del="${i}">Remove</button>` : ''}<small class="muted" data-wc="${i}"></small></div></fieldset>`).join('');
    const wc = i => { const n = (STEPS[i].body.replace(/\[button:[^\]]*\]/g, '').match(/\S+/g) || []).length; const el = box.querySelector(`[data-wc="${i}"]`); if (el) el.textContent = n + ' words' + (n > 150 ? ' · shorter mails get more replies' : ''); };
    STEPS.forEach((_, i) => wc(i));
    box.oninput = e => { const t = e.target; if (t.dataset.k) { STEPS[+t.dataset.i][t.dataset.k] = t.dataset.k === 'delayDays' ? +t.value : t.value; if (t.dataset.k === 'body') wc(+t.dataset.i); } };
    box.onclick = async e => {
      const d = e.target.closest('[data-del]'); if (d) { STEPS.splice(+d.dataset.del, 1); steps(h); return; }
      const p = e.target.closest('[data-prev]'); if (!p) return;
      try {
        const r = await api('/api/admin/outreach/preview', { method: 'POST', body: { ...STEPS[+p.dataset.prev], list: CUR, design: CFG.lists[CUR].design } });
        const m = modal('Preview', `<p><b>Subject:</b> ${E(r.subject)}</p><iframe title="Mail preview" sandbox="" style="width:100%;height:60vh;border:1px solid #e2e8f0;border-radius:10px;background:#fff"></iframe><details style="margin-top:8px"><summary>Plain-text version</summary><pre style="white-space:pre-wrap;font:14px/1.6 Arial,sans-serif;background:#f8fafc;padding:14px;border-radius:10px">${E(r.text)}</pre></details>`, { wide: true });
        m.el.querySelector('iframe').srcdoc = r.html;
      } catch (x) { toast(err(x), true); }
    };
    $('#orAddStep', h).disabled = STEPS.length >= 4;
  }

  function wire(h) {
    const msg = $('#orMsg', h);
    $('#orTabs', h).onclick = e => { const b = e.target.closest('[data-list]'); if (b) { CUR = b.dataset.list; steps(h); } };
    $('#orDesign', h).onchange = e => { CFG.lists[CUR].design = e.target.value; };
    $('#orLib', h).onchange = e => { const t = lib()[e.target.value]; if (!t) return; if (!confirm(`Replace the current ${D.lists[CUR]} mails with "${t.name}"? Nothing is saved until you press Save.`)) { e.target.value = ''; return; } CFG.lists[CUR].steps = JSON.parse(JSON.stringify(t.steps)); steps(h); };
    $('#orAddStep', h).onclick = () => { const S = steps0(); if (S.length < 4) { S.push({ subject: 'Re: ' + (S[S.length - 1].subject || ''), body: (CUR === 'colleges' ? 'Hello' : 'Hi') + ' {{name}},\n\n', delayDays: 3 }); steps(h); } };
    $('#orSave', h).onclick = async () => {
      const on = $('#orOn', h).checked;
      const seeds = $('#orSeeds', h).value.split(/[\s,;]+/).map(x => x.trim()).filter(Boolean);
      const body = { enabled: on, windowStart: +$('#orWs', h).value, windowEnd: +$('#orWe', h).value, footer: $('#orFooter', h).value, lists: CFG.lists, warmup: { share: +$('#orWarmShare', h).value, seeds } };
      try { if (on && !D.config.enabled && !(await ensureRecent())) return; await api('/api/admin/outreach/settings', { method: 'POST', body }); toast(on ? 'Saved. Sending is on.' : 'Saved.'); outreach(); } catch (x) { msg.textContent = err(x); }
    };
    $('#orAddBox', h).onclick = () => box(null);
    $('#orBoxes', h).onclick = async e => {
      const b = e.target.closest('[data-act]'); if (!b) return; const s = D.senders.find(x => x.id === b.closest('tr').dataset.sid); if (!s) return;
      const act = b.dataset.act;
      if (act === 'edit') return box(s);
      if (act === 'more') return more(s);
      if (act === 'test') { const to = prompt('Send a test mail to:', ''); if (to === null) return; b.disabled = true; try { const r = await api('/api/admin/outreach/senders', { method: 'POST', body: { action: 'test', id: s.id, to: to || null, list: CUR } }); toast(r.message); } catch (x) { toast(err(x), true); } b.disabled = false; return; }
      try { await api('/api/admin/outreach/senders', { method: 'POST', body: { action: act, id: s.id } }); toast(act === 'pause' ? 'Mailbox paused' : 'Mailbox resumed'); outreach(); } catch (x) { toast(err(x), true); }
    };
    $('#orFile', h).onchange = async e => { const f = e.target.files[0]; if (!f) return; if (f.size > 3e6) { toast('That file is over 3 MB. Split it into smaller files.', true); return; } $('#orText', h).value = await f.text(); if (!$('#orSource', h).value) $('#orSource', h).value = f.name; };
    $('#orImport', h).onclick = async ev => {
      const out = $('#orImportRes', h), text = $('#orText', h).value.trim();
      if (!text) { out.textContent = 'Choose a file or paste some rows first.'; return; }
      if (!$('#orConsent', h).checked) { out.textContent = 'Tick the box to confirm everyone on this list agreed to hear from you.'; return; }
      ev.target.disabled = true; out.textContent = 'Checking addresses… this can take up to a minute for a big list.';
      try {
        const { result: r } = await api('/api/admin/outreach/import', { method: 'POST', body: { text, source: $('#orSource', h).value, list: $('#orImpList', h).value, consent: true, checkDomains: $('#orMx', h).checked } });
        const row = (l, n) => n ? `<li>${E(l)}: <b>${n.toLocaleString('en-IN')}</b></li>` : '';
        out.innerHTML = `<div class="panel panel-pad" style="background:#f0fdf4"><b>${r.added.toLocaleString('en-IN')} added</b> of ${r.received.toLocaleString('en-IN')} rows.<ul style="margin:8px 0 0;padding-left:18px">${row('Not a valid address', r.invalid)}${row('System addresses (no-reply, postmaster…)', r.role)}${row('Domain cannot receive mail', r.noMail)}${row('Duplicates in this file', r.duplicate)}${row('Already in your list', r.existing)}${row('Already CVTara users', r.users)}${row('Unsubscribed or bounced before', r.suppressed)}</ul>${r.samples.length ? `<details style="margin-top:8px"><summary>Examples left out</summary><ul>${r.samples.map(x => `<li>${E(x.email)} — ${E(x.reason)}</li>`).join('')}</ul></details>` : ''}</div>`;
        $('#orText', h).value = ''; $('#orFile', h).value = ''; D = null; setTimeout(() => outreach().then(() => { const o = $('#orImportRes'); if (o) o.innerHTML = out.innerHTML; }), 50);
      } catch (x) { out.textContent = err(x); }
      ev.target.disabled = false;
    };
    let t; $('#orQ', h).oninput = e => { F.q = e.target.value; clearTimeout(t); t = setTimeout(() => { F.page = 1; list(h); }, 300); };
    $('#orS', h).onchange = e => { F.status = e.target.value; F.page = 1; list(h); };
    $('#orL', h).onchange = e => { F.list = e.target.value; F.page = 1; list(h); };
    $('#orExport', h).onclick = async () => { try { const r = await fetch('/api/admin/outreach/export', { credentials: 'same-origin', headers: { Accept: 'text/csv' } }); if (!r.ok) throw new Error('Download failed.'); download('outreach-contacts.csv', await r.text(), 'text/csv'); } catch (x) { toast(err(x), true); } };
    $('#orStop', h).onclick = async () => { if (!confirm('Stop everyone who has not been mailed yet? They will be marked Removed and never mailed. People already in a sequence keep getting their follow-ups.')) return; try { const r = await api('/api/admin/outreach/stop-queued', { method: 'POST', body: {} }); toast(r.updated + ' contacts stopped'); outreach(); } catch (x) { toast(err(x), true); } };
    $('#orSuppress', h).onclick = () => {
      const m = modal('Mark addresses as bounced or unsubscribed', `<form id="orSup"><p class="muted">Paste addresses from bounce notices or unsubscribe replies that arrived in the mailboxes. They will never be mailed again.</p><label class="ops-f">Addresses<textarea name="t" rows="8" required></textarea></label><label class="ops-f">Mark as<select name="s"><option value="bounced">Bounced</option><option value="unsubscribed">Unsubscribed</option></select></label><button class="button primary">Save</button><p role="status" class="ops-st"></p></form>`);
      $('#orSup', m.el).onsubmit = async e => { e.preventDefault(); try { const r = await api('/api/admin/outreach/suppress', { method: 'POST', body: { text: e.target.t.value, status: e.target.s.value } }); toast(r.updated + ' addresses updated'); m.close(); outreach(); } catch (x) { m.el.querySelector('.ops-st').textContent = err(x); } };
    };
  }

  function box(s) {
    const v = s || { email: '', fromName: '', host: 'smtp.hostinger.com', port: 465, user: '', warmupStart: 10, warmupStep: 2, dailyCap: 50 };
    const m = modal(s ? 'Edit mailbox' : 'Add a sending mailbox', `<form id="orBox" autocomplete="off"><p class="muted" style="margin-top:0">Use a mailbox on your separate sending domain. Set up SPF, DKIM and DMARC for that domain first (Hostinger: Emails → your domain → DNS records).</p>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
      <label class="ops-f">Mailbox address<input name="email" type="email" required maxlength="190" value="${E(v.email)}" placeholder="priya@cvtara.in"></label>
      <label class="ops-f">Sender name<input name="fromName" maxlength="80" value="${E(v.fromName)}" placeholder="Priya from CVTara"></label>
      <label class="ops-f">SMTP server<input name="host" required maxlength="190" value="${E(v.host)}"></label>
      <label class="ops-f">Port<select name="port">${[465, 587, 2525, 25].map(p => `<option ${+v.port === p ? 'selected' : ''}>${p}</option>`).join('')}</select></label>
      <label class="ops-f">Login (usually the address)<input name="user" required maxlength="190" value="${E(v.user)}"></label>
      <label class="ops-f">Password<input name="password" type="password" maxlength="300" ${s ? 'placeholder="Leave blank to keep the saved one"' : 'required'}></label>
      <label class="ops-f">First-day mails<input name="warmupStart" type="number" min="1" max="200" value="${v.warmupStart}"></label>
      <label class="ops-f">Add per day<input name="warmupStep" type="number" min="0" max="100" value="${v.warmupStep}"></label>
      <label class="ops-f">Daily cap<input name="dailyCap" type="number" min="1" max="1000" value="${v.dailyCap}"></label></div>
      <p class="muted" id="orPlan"></p><button class="button primary">Save mailbox</button><p role="status" class="ops-st"></p></form>`, { wide: true });
    const f = $('#orBox', m.el);
    const plan = () => { const a = +f.warmupStart.value || 1, st = +f.warmupStep.value || 0, cap = +f.dailyCap.value || 1; const d = st ? Math.max(0, Math.ceil((cap - a) / st)) : 0; let tot = 0; for (let i = 0; i < 30; i++) tot += Math.min(cap, a + st * i); $('#orPlan', m.el).textContent = `Day 1: ${Math.min(a, cap)} mails. Reaches ${cap} a day ${d ? 'on day ' + (d + 1) : 'straight away'}. About ${tot.toLocaleString('en-IN')} mails in the first 30 days from this mailbox.` + (cap > 100 ? ' Above 100 a day needs a paid Hostinger mailbox.' : ''); };
    f.oninput = plan; plan();
    if (!s && !f.user.value) f.email.addEventListener('input', () => { f.user.value = f.email.value; });
    f.onsubmit = async e => {
      e.preventDefault(); const st = m.el.querySelector('.ops-st');
      const body = { action: s ? 'update' : 'add', id: s ? s.id : null, email: f.email.value, fromName: f.fromName.value, host: f.host.value, port: +f.port.value, user: f.user.value, password: f.password.value || null, warmupStart: +f.warmupStart.value, warmupStep: +f.warmupStep.value, dailyCap: +f.dailyCap.value };
      try { if (!(await ensureRecent())) return; await api('/api/admin/outreach/senders', { method: 'POST', body }); toast('Mailbox saved. Send a test before turning sending on.'); m.close(); outreach(); } catch (x) { st.textContent = err(x); }
    };
  }

  function more(s) {
    const m = modal(s.email, `<p>Restarting the warm-up sends the first-day amount again tomorrow and builds up from there. Use it after a long break or after the mailbox was suspended.</p><div style="display:flex;gap:8px;flex-wrap:wrap"><button class="button secondary" data-x="restart">Restart warm-up</button><button class="button secondary" data-x="delete">Remove mailbox</button></div><p role="status" class="ops-st"></p>`);
    m.body.onclick = async e => {
      const b = e.target.closest('[data-x]'); if (!b) return;
      if (b.dataset.x === 'delete' && !confirm('Remove this mailbox? Its contacts move to the other mailboxes. Its send history stays in the numbers.')) return;
      try { await api('/api/admin/outreach/senders', { method: 'POST', body: { action: b.dataset.x, id: s.id } }); toast(b.dataset.x === 'delete' ? 'Mailbox removed' : 'Warm-up restarted'); m.close(); outreach(); } catch (x) { m.body.querySelector('.ops-st').textContent = err(x); }
    };
  }

  async function list(h) {
    const tbl = $('#orT', h); if (!tbl) return;
    try {
      const qs = new URLSearchParams({ page: String(F.page), per: String(F.per || 20) }); if (F.q) qs.set('q', F.q); if (F.status !== 'all') qs.set('status', F.status); if (F.list !== 'all') qs.set('list', F.list);
      const d = await api('/api/admin/outreach/contacts?' + qs);
      tbl.innerHTML = d.rows.length ? `<thead><tr><th>Contact</th><th>Status</th><th>Mails sent</th><th>Next mail</th><th>Mailbox</th></tr></thead><tbody>${d.rows.map(r => `<tr><td><b>${E(r.email)}</b><br><small>${E([r.name, r.designation, r.college, r.city].filter(Boolean).join(' · '))}${r.source ? ' · ' + E(r.source) : ''}</small><br><small class="muted">${E(D.lists[r.list] || '')}${r.clicks ? ' · ' + r.clicks + ' click' + (r.clicks === 1 ? '' : 's') : ''}${r.shares ? ' · shared ' + r.shares + '×' : ''}</small></td><td><span class="ad-pill ${tone(r.status)}">${E(STATUS[r.status] || r.status)}</span>${r.error ? `<br><small>${E(r.error)}</small>` : ''}</td><td>${r.step}${r.last_sent_at ? `<br><small>last ${E(when(r.last_sent_at))}</small>` : ''}</td><td>${r.status === 'active' ? E(when(r.next_at)) : r.status === 'queued' ? 'In line' : '—'}</td><td>${E(r.sender || '—')}</td></tr>`).join('')}</tbody>` : '<tbody><tr><td>No contacts match.</td></tr></tbody>';
      K.pager($('#orP', h), d, (p, per) => { F.page = p; F.per = per; list(h); });
    } catch (e) { tbl.innerHTML = `<tbody><tr><td role="alert">${E(err(e))}</td></tr></tbody>`; }
  }

  K.register('outreach', outreach, ['📨', 'Outreach', 'Mail students and college placement officers from separate mailboxes, with warm-up and automatic stops.']);
})();
