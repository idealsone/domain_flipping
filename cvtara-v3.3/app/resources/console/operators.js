/* Owner console: Growth page tabs added in 3.35.0. Customers & journeys (events, segments, journeys, checkout recovery, referrals),
   Links & AI search (link register, AI answer checks, crawler access) and Scorecard (revenue, contribution, attribution, autonomy).
   Every number comes from the server; anything the site cannot measure shows as "Not tracked". */
(() => {
  'use strict';
  const K = window.CVTaraAdminKit; if (!K) return;
  const { $, E, api, modal, toast } = K;
  const err = e => (e && e.message) || 'Something went wrong.';
  const n = x => x === null || x === undefined ? 'Not tracked' : Number(x).toLocaleString('en-IN');
  const inr = p => p === null || p === undefined ? 'Not tracked' : '₹' + Math.round(Number(p) / 100).toLocaleString('en-IN');
  const pct = x => x === null || x === undefined ? '—' : x + '%';
  const when = t => t ? new Date(String(t).replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(String(t)) || /^\d{4}-\d\d-\d\d$/.test(String(t)) ? '' : 'Z')).toLocaleString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  const yes = v => v === null || v === undefined ? '<span class="muted">—</span>' : v ? '<span class="pill ok">Yes</span>' : '<span class="pill bad">No</span>';
  const kpi = (label, value, sub = '') => `<div class="kpi"><small>${E(label)}</small><b${/^Not tracked$/.test(String(value)) ? ' style="font-size:1.1rem"' : ''}>${value}</b><small>${sub}</small></div>`;
  const table = (head, rows, empty) => rows.length ? `<div style="overflow:auto"><table class="ad-table"><thead><tr>${head.map(h => `<th>${E(h)}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>` : `<p class="muted">${E(empty)}</p>`;
  const opt = (o, v) => Object.entries(o).map(([k, l]) => `<option value="${E(k)}" ${String(k) === String(v) ? 'selected' : ''}>${E(l)}</option>`).join('');
  const panel = (title, body, sub = '') => `<section class="panel panel-pad" data-nokit><h3 style="margin-top:0">${E(title)}</h3>${sub ? `<p class="muted" style="margin-top:0">${E(sub)}</p>` : ''}${body}</section>`;
  const daysPick = (id, d, list = [7, 30, 90]) => `<label>Period <select id="${id}">${list.map(x => `<option value="${x}" ${x === d ? 'selected' : ''}>Last ${x} days</option>`).join('')}</select></label>`;
  const fail = (h, e) => { h.innerHTML = `<div class="panel panel-pad" role="alert">${E(err(e))}</div>`; };
  const SRC = { google: 'Google', bing: 'Bing', ai: 'AI assistants', social: 'Social', referral: 'Other sites', direct: 'Direct', unknown: 'Unknown (no analytics consent or older account)' };
  const OUT = { paid: ['Paid', 'ok'], recovered: ['Recovered after reminder', 'ok'], recovered_later: ['Paid later after reminder', 'ok'], pending: ['In progress', ''], abandoned: ['Not finished', 'mid'], failed: ['Payment failed', 'bad'], refunded: ['Refunded', 'mid'], disputed: ['Chargeback', 'bad'] };

  // ---------------------------------------------------------------- Customers & journeys
  const C = { days: 30, page: 1, per: 20, outcome: 'all', q: '' };
  async function customers(h) {
    h.innerHTML = '<div class="ops-skel"></div>';
    let D; try { D = await api('/api/admin/growth/customers?days=' + C.days); } catch (e) { fail(h, e); return; }
    const J = D.journeys, R = D.referrals;
    h.innerHTML = `<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px">${daysPick('cuDays', C.days)} <button class="button secondary small" id="cuSync">Refresh customer events now</button> <span class="muted">Last refresh: ${E(when(D.lastSync))}</span></div>
      <section class="panel panel-pad" data-nokit id="ffBox"><h3 style="margin-top:0">Feature use → purchase</h3><div class="ops-skel"></div></section>
      ${panel('Look up one customer', `<form id="cuF" style="display:flex;gap:8px;flex-wrap:wrap"><input type="search" name="q" placeholder="Customer email" required style="min-width:260px"><button class="button secondary small">Show their timeline</button></form><div id="cuT"></div>`, 'Every action they took, the segments they are in now, where they first came from and what they have paid. No resume text is stored in events.')}
      ${panel('Behaviour segments (now)', table(['Segment', 'People', 'Can be emailed', 'What the agent does'], D.segments.map(s => `<tr><td>${E(s.label)}</td><td>${n(s.people)}</td><td>${n(s.reachable)}</td><td class="muted">${E(s.action)}</td></tr>`), ''), 'Worked out from what people did, every time you open this page. Someone who buys leaves the free-user segments at once. "Can be emailed" = opted in, verified and not unsubscribed. The same segments are offered as audiences in Campaigns.')}
      ${panel('Customer journeys', `${table(['Journey', 'Switched on', 'Sent', 'Waiting', 'Stopped before sending', 'Did it within 7 days', 'Bought within 14 days', 'Their revenue (attributed)'], J.journeys.map(j => `<tr><td>${E(j.label)}<br><small class="muted">template ${E(j.template)}</small></td><td>${j.on ? '<span class="pill ok">On</span>' : '<span class="pill mid">Off</span>'}</td><td>${n(j.sent)}</td><td>${n(j.queued)}</td><td>${n(j.stopped)}</td><td>${n(j.didGoal)} <small class="muted">${E(j.goalLabel)}</small></td><td>${n(j.buyers)}</td><td>${inr(j.attributedNetPaise)}</td></tr>`), '')}
        <p class="muted">New journeys start switched off. Read each one in <a href="/admin.html?tab=emails">Emails</a> and switch it on there; that is your approval. Each person gets each journey once, never more than one marketing mail in 3 days, and at most ${n(J.settings.dailyCap)} per journey per day. Growth agent level: ${J.level} (journeys send at level 3 or 4).</p>
        ${table(['Automatic mail already running', 'Sent', 'Stopped before sending', 'Waiting'], J.existing.map(x => `<tr><td>${E(x.label)}</td><td>${n(x.sent)}</td><td>${n(x.stopped)}</td><td>${n(x.waiting)}</td></tr>`), '')}`, J.note)}
      <section class="panel panel-pad" data-nokit><h3 style="margin-top:0">Checkout and payment recovery</h3><div id="coK"><div class="ops-skel"></div></div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0"><input type="search" id="coQ" placeholder="Search email or package" value="${E(C.q)}"><select id="coO"><option value="all">Any outcome</option>${opt(Object.fromEntries(Object.entries(OUT).map(([k, v]) => [k, v[0]])), C.outcome)}</select><a class="button secondary small" id="coCsv" href="#">Download CSV</a></div>
        <div id="coT"></div><div class="ops-pager" id="coP"></div></section>
      ${panel('Referral programme activation', `<div class="ops-kpis">
          ${kpi('Programme', R.on ? '<span class="pill ok">On</span>' : '<span class="pill mid">Off</span>', 'Give ₹50, get ₹50')}
          ${kpi('Customers who can refer', n(R.eligible), n(R.invited) + ' invited; ' + n(R.notInvitedConsented) + ' opted-in not invited yet')}
          ${kpi('Link clicks', n(R.clicks), '')}
          ${kpi('Referred sign-ups', n(R.signups), n(R.verified) + ' verified')}
          ${kpi('Paid referrals', n(R.paidReferrals), pct(R.signupToPaid) + ' of referred sign-ups')}
          ${kpi('Referred revenue', inr(R.referredRevenuePaise), 'bonus ' + inr(R.commissionPaise) + ', reversed ' + inr(R.reversedPaise))}
        </div>
        ${table(['Top referrers', 'Sign-ups', 'Paid', 'Bonus earned'], R.top.map(t => `<tr><td>${E(t.email)}</td><td>${n(t.signups)}</td><td>${n(t.paid)}</td><td>${inr(t.commissionPaise)}</td></tr>`), 'No referred sign-ups in this period.')}
        ${R.flags.length ? `<p><b>For you to review</b></p><ul>${R.flags.map(f => `<li>${E(f.email)}: ${E(f.why)}</li>`).join('')}</ul>` : '<p class="muted">No suspicious referral patterns found.</p>'}`, R.note)}
      ${panel('Customer events recorded', table(['Event', 'Times', 'People'], D.events.map(e => `<tr><td>${E(e.label)}</td><td>${n(e.events)}</td><td>${n(e.people)}</td></tr>`), ''), 'Copied every 30 minutes from accounts, resumes, ATS checks, orders, refunds, referrals and support. Pricing visits count only for signed-in visitors who allowed analytics.')}`;
    $('#cuDays', h).onchange = e => { C.days = Number(e.target.value); C.page = 1; customers(h); };
    $('#cuSync', h).onclick = async e => { e.target.disabled = true; try { const r = await api('/api/admin/growth/events/sync', { method: 'POST', body: {} }); toast(r.summary || 'Done'); customers(h); } catch (x) { toast(err(x)); e.target.disabled = false; } };
    $('#cuF', h).onsubmit = async e => { e.preventDefault(); const q = new FormData(e.target).get('q'); const box = $('#cuT', h); box.innerHTML = '<div class="ops-skel"></div>'; try { box.innerHTML = timeline(await api('/api/admin/growth/customer?q=' + encodeURIComponent(q))); } catch (x) { box.innerHTML = `<p role="alert">${E(err(x))}</p>`; } };
    $('#coQ', h).onchange = e => { C.q = e.target.value.trim(); C.page = 1; checkout(h); };
    $('#coO', h).onchange = e => { C.outcome = e.target.value; C.page = 1; checkout(h); };
    checkout(h);
    features(h);
  }

  // 3.75.0: who uses which feature, and how many of them pay (resume, ATS, letters, interview questions, mocks, applications).
  const F = { feature: 'resume', page: 1, per: 20, q: '', paid: 'all' };
  async function features(h) {
    const box = $('#ffBox', h); if (!box) return;
    let D; try { D = await api('/api/admin/growth/features?days=' + C.days); } catch (e) { box.innerHTML = `<h3 style="margin-top:0">Feature use → purchase</h3><p role="alert">${E(err(e))}</p>`; return; }
    const bar = v => v === null || v === undefined ? '' : `<span aria-hidden="true" style="display:inline-block;vertical-align:middle;margin-left:6px;height:8px;width:${Math.max(2, Math.min(100, v))}px;border-radius:4px;background:#1557d2;opacity:.75"></span>`;
    const tracked = D.features.filter(f => f.tracked);
    box.innerHTML = `<h3 style="margin-top:0">Feature use → purchase</h3><p class="muted" style="margin-top:0">${E(D.note)} Period: last ${n(D.days)} days · ${n(D.activePeople)} people used at least one feature.</p>
      <h4>Sign-ups of this period, step by step</h4>
      ${table(['Step', 'People', 'Of sign-ups', 'From the step before'], D.funnel.map(s => `<tr><td>${E(s.label)}</td><td>${n(s.value)}</td><td>${pct(s.ofSignups)}${bar(s.ofSignups)}</td><td>${pct(s.fromPrevious)}</td></tr>`), 'No sign-ups in this period.')}
      <h4>Each feature: who used it and who paid</h4>
      ${table(['Feature', 'People', 'Times used', 'Paid (any order)', 'Paid after using it', 'Bought ₹299+ pack', 'Bought add-on or Kit', 'Paid rate', ''], D.features.map(f => f.tracked ? `<tr><td>${E(f.label)}</td><td>${n(f.people)}</td><td>${n(f.uses)}</td><td>${n(f.paid)}</td><td>${n(f.paidAfter)}</td><td>${n(f.packs)}</td><td>${n(f.addons)}</td><td>${pct(f.paidRate)}${bar(f.paidRate)}</td><td><button class="button secondary small" data-ff="${E(f.key)}">Who</button></td></tr>` : `<tr><td>${E(f.label)}</td><td colspan="8" class="muted">Not on this site yet</td></tr>`), '')}
      <h4>What people bought in this period</h4>
      ${table(['Package or add-on', 'Orders', 'Share of orders', 'Net revenue'], D.mix.map(m => `<tr><td>${E(m.name)}</td><td>${n(m.orders)}</td><td>${pct(m.share)}${bar(m.share)}</td><td>${inr(m.netPaise)}</td></tr>`), 'No paid orders in this period.')}
      <div id="ffWho" style="margin-top:12px"></div>`;
    box.querySelectorAll('[data-ff]').forEach(b => { b.onclick = () => { F.feature = b.dataset.ff; F.page = 1; who(h, tracked); }; });
  }
  async function who(h, tracked) {
    const box = $('#ffWho', h); if (!box) return;
    const q = new URLSearchParams({ feature: F.feature, days: String(C.days), page: String(F.page), per: String(F.per) }); if (F.q) q.set('q', F.q); if (F.paid !== 'all') q.set('paid', F.paid);
    const label = (tracked.find(f => f.key === F.feature) || {}).label || F.feature;
    box.innerHTML = `<h4>${E(label)}: people</h4><div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0"><input type="search" id="ffQ" placeholder="Search email or name" value="${E(F.q)}"><select id="ffPaid">${opt({ all: 'Paid or not', yes: 'Has paid', no: 'Never paid' }, F.paid)}</select><a class="button secondary small" href="/api/admin/growth/features/people?${q}&export=csv">Download CSV</a><a class="button secondary small" href="/api/admin/growth/features/people?${q}&export=xlsx">Excel</a><a class="button secondary small" href="/api/admin/growth/features/people?${q}&export=pdf">PDF</a></div><div id="ffT"><div class="ops-skel"></div></div><div class="ops-pager" id="ffP"></div>`;
    $('#ffQ', h).onchange = e => { F.q = e.target.value.trim(); F.page = 1; who(h, tracked); };
    $('#ffPaid', h).onchange = e => { F.paid = e.target.value; F.page = 1; who(h, tracked); };
    try {
      const d = await api('/api/admin/growth/features/people?' + q);
      $('#ffT', h).innerHTML = table(['Customer', 'Plan now', 'Times used', 'First use', 'Last use', 'Paid orders', 'Paid in total'], d.rows.map(r => `<tr><td>${E(r.email)}<br><small class="muted">${E(r.name || '')} · joined ${E(when(r.joined))}</small></td><td>${E(r.planNow)}</td><td>${n(r.uses)}</td><td>${E(when(r.firstAt))}</td><td>${E(when(r.lastAt))}</td><td>${n(r.orders)}</td><td>${inr(r.netPaise)}</td></tr>`), 'Nobody used this feature in this period.');
      K.pager($('#ffP', h), d, (p, per) => { F.page = p; F.per = per; who(h, tracked); });
    } catch (e) { $('#ffT', h).innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  function timeline(t) {
    const u = t.user, f = u.firstTouch;
    return `<p><b>${E(u.name || u.email)}</b> · ${E(u.email)} · since ${E(when(u.since))} · ${t.consent ? 'opted in to emails' : 'no marketing emails'} · paid ${inr(t.lifetimeNetPaise)} in ${n(t.orders)} order(s)${u.referred ? ' · came through a referral' : ''}</p>
      <p>First came from: ${f ? `<b>${E(SRC[f.source] || f.source)}</b>${f.site ? ' (' + E(f.site) + ')' : ''}${f.landing ? ' to <code>' + E(f.landing) + '</code>' : ''}${f.campaign ? ' · campaign ' + E(f.campaign) : ''}` : '<span class="muted">unknown</span>'}</p>
      <p>Segments now: ${t.segments.length ? t.segments.map(s => `<span class="pill">${E(s.label)}</span>`).join(' ') : '<span class="muted">none</span>'}</p>
      ${table(['When', 'What happened', 'Detail'], t.events.map(e => `<tr><td>${E(when(e.at))}</td><td>${E(e.label)}</td><td class="muted">${E(e.meta)}</td></tr>`), 'No events recorded yet. Press "Refresh customer events now".')}`;
  }

  async function checkout(h) {
    const box = $('#coT', h); if (!box) return;
    const q = new URLSearchParams({ days: String(C.days), page: String(C.page), per: String(C.per) }); if (C.q) q.set('q', C.q); if (C.outcome !== 'all') q.set('outcome', C.outcome);
    $('#coCsv', h).href = '/api/admin/growth/checkout?' + q + '&export=csv';
    try {
      const d = await api('/api/admin/growth/checkout?' + q), k = d.kpis;
      $('#coK', h).innerHTML = `<div class="ops-kpis">${kpi('Checkouts started', n(k.started), '')}${kpi('Paid without help', n(k.paidDirect), '')}${kpi('Recovered after a reminder', n(k.recovered), inr(k.recoveredPaise) + ' confirmed')}${kpi('Not finished / failed', n(k.abandoned) + ' / ' + n(k.failed), 'recovery rate ' + pct(k.recoveryRate))}${kpi('Reminders sent', n(k.remindersSent), 'two at most per order')}${kpi('Possible double payments', n(k.duplicates), 'check with the gateway')}${kpi('Refunded', n(k.refunded), inr(k.refundedPaise))}</div>
        <p class="muted">${E(d.note)}</p>${d.duplicates.length ? `<p><b>Possible double payments</b></p><ul>${d.duplicates.map(x => `<li>${E(x.email)}: ${E(x.plan)} paid twice within 30 minutes (${E(when(x.at))})</li>`).join('')}</ul>` : ''}`;
      box.innerHTML = table(['Started', 'Customer', 'Package', 'Amount', 'Gateway', 'Outcome', 'Reminders', 'Came from'], d.rows.map(r => { const o = OUT[r.outcome] || [r.outcome, '']; return `<tr><td>${E(when(r.at))}</td><td>${E(r.email)}</td><td>${E(r.plan)}</td><td>${inr(r.amountPaise)}</td><td>${E(r.gateway)}</td><td><span class="pill ${o[1]}">${E(o[0])}</span></td><td>${n(r.reminders)}${r.waiting ? ` <small class="muted">(+${r.waiting} waiting)</small>` : ''}</td><td>${E(SRC[r.source || 'unknown'] || r.source)}</td></tr>`; }), 'No checkouts in this period.');
      K.pager($('#coP', h), d, (p, per) => { C.page = p; C.per = per; checkout(h); });
    } catch (e) { box.innerHTML = `<p role="alert">${E(err(e))}</p>`; }
  }

  // ---------------------------------------------------------------- Links & AI search
  const L = { status: 'all', days: 90 };
  async function reach(h) {
    h.innerHTML = '<div class="ops-skel"></div>';
    let Lk, V; try { [Lk, V] = await Promise.all([api('/api/admin/growth/links'), api('/api/admin/growth/visibility?days=' + L.days)]); } catch (e) { fail(h, e); return; }
    const s = Lk.summary, A = V.audit || {}, PN = V.platformNames;
    const rows = Lk.rows.filter(r => L.status === 'all' || (L.status === 'due' ? r.due : r.status === L.status));
    h.innerHTML = `${panel('Links and brand mentions', `<div class="ops-kpis">${kpi('Sites in the register', n(s.total), n(s.dueToday) + ' follow-up(s) due')}${kpi('Live links found on the page', n(s.live), n(s.followLive) + ' followed')}${kpi('Unlinked mentions', n(s.mentions), 'ask the editor for a link')}${kpi('Published but link not found', n(s.lost), '')}${kpi('Costs entered', '₹' + n(s.costInr), s.paidFollow ? `<span class="pill bad">${s.paidFollow} paid link(s) not marked sponsored</span>` : '')}</div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0"><button class="button primary small" id="lkAdd">Add a site</button><select id="lkSt"><option value="all">All statuses</option><option value="due">Follow-up due</option>${opt(Lk.statuses, L.status)}</select></div>
        ${table(['Site', 'How', 'Score', 'Status', 'Next follow-up', 'Link on the page', 'Visits (90 days)', 'Sign-ups / buyers', ''], rows.map(r => `<tr><td><b>${E(r.domain)}</b>${r.contact_name ? `<br><small class="muted">${E(r.contact_name)}</small>` : ''}</td><td><small>${E(r.methodLabel)}</small></td><td>${n(r.score)}${r.spam_risk === 'high' ? ' <span class="pill bad">spam risk</span>' : ''}</td><td>${E(r.statusLabel)}${r.due ? ' <span class="pill mid">due</span>' : ''}</td><td>${E(r.next_follow_up ? when(r.next_follow_up) : '—')}</td>
          <td>${r.verify_state ? `<span class="pill ${r.verify_state === 'live' ? 'ok' : r.verify_state === 'mention' ? 'mid' : 'bad'}">${E({ live: 'Live (' + (r.link_attr || '?') + ')', mention: 'Named, not linked', missing: 'Not found', gone: 'Page not reachable', paid_follow: 'Paid but followed' }[r.verify_state] || r.verify_state)}</span><br><small class="muted">${E(when(r.verified_at))}</small>` : '<span class="muted">—</span>'}</td><td>${n(r.visits90d)}</td><td>${n(r.signups)} / ${n(r.buyers)}</td>
          <td><button class="button secondary small" data-edit="${E(r.id)}">Open</button> ${r.publication_url ? `<button class="button secondary small" data-verify="${E(r.id)}">Check link</button>` : ''}</td></tr>`), 'No sites yet. Add career blogs, college placement pages, HR publications and resource pages where CVTara genuinely helps readers.')}`,
        'Only earned, relevant links: no bought ranking links, link networks, mass directories or fake guest posts. A paid placement must be marked sponsored. Pitches are sent by you from your own mailbox; the agent drafts them and re-checks published links weekly.')}
      ${panel('AI search: answer checks', `<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">${daysPick('viDays', L.days, [30, 90])}<button class="button primary small" id="viAdd">Record a check</button><button class="button secondary small" id="viPrompts">Edit the prompt list</button><a class="button secondary small" href="/api/admin/growth/visibility/tests?export=csv">Download all checks (CSV)</a></div>
        ${table(['Platform', 'Checks', 'Mentioned CVTara', 'Recommended', 'Linked', 'Inaccurate', 'Last check'], V.platforms.map(p => `<tr><td>${E(p.label)}</td><td>${n(p.tests)}</td><td>${p.tests ? n(p.mentioned) + ' (' + pct(p.mentionRate) + ')' : '—'}</td><td>${p.tests ? n(p.recommended) : '—'}</td><td>${p.tests ? n(p.linked) : '—'}</td><td>${p.tests ? n(p.inaccurate) : '—'}</td><td>${E(when(p.lastTested))}</td></tr>`), '')}
        <p><b>${n(V.due)}</b> prompt and platform pair(s) not checked in the last 7 days.${V.dueSample.length ? ' Next: ' + V.dueSample.slice(0, 4).map(d => `"${E(d.prompt)}" on ${E(d.label)}`).join('; ') + '.' : ''}</p>
        <details><summary><b>Latest result per prompt</b></summary>${table(['Prompt'].concat(Object.values(PN)), V.latest.map(r => `<tr><td>${E(r.prompt)}</td>${Object.keys(PN).map(k => { const c = r.cells[k]; return `<td>${c ? (c.mentioned ? `<span class="pill ok">${c.recommended ? 'recommended' : 'mentioned'}</span>` : '<span class="pill bad">absent</span>') + `<br><small class="muted">${E(when(c.on))}</small>` : '<span class="muted">—</span>'}</td>`; }).join('')}</tr>`), '')}</details>
        <div class="ops-two"><div><p><b>Competitors named most</b></p>${V.competitors.length ? `<ul>${V.competitors.map(c => `<li>${E(c.name)} (${c.n})</li>`).join('')}</ul>` : '<p class="muted">None recorded.</p>'}</div>
          <div><p><b>CVTara pages cited</b></p>${V.cited.length ? `<ul>${V.cited.map(c => `<li><code>${E(c.url)}</code> (${c.n})</li>`).join('')}</ul>` : '<p class="muted">None recorded.</p>'}${V.missing.length ? `<p><b>What answers said was missing</b></p><ul>${V.missing.map(m => `<li>${E(m.platform)}: ${E(m.missing)}</li>`).join('')}</ul>` : ''}</div></div>
        ${table(['Visits from AI assistants', 'Visitors', 'Sign-ups', 'Buyers', 'Revenue (attributed)'], V.arrivals.map(a => `<tr><td>${E(a.label)}</td><td>${n(a.visitors)}</td><td>${n(a.signups)}</td><td>${n(a.buyers)}</td><td>${inr(a.netPaise)}</td></tr>`), 'No visits from AI assistants recorded yet (counted from 3.35.0, for visitors who allow analytics).')}`, V.note)}
      ${panel('Crawler access', `<p><button class="button secondary small" id="viAudit">Run the check now</button> <span class="muted">Last check: ${E(when(A.at))}</span></p>
        ${A.crawlers ? table(['Crawler', 'Owner', 'Kind', 'What it does', 'Public pages allowed', 'Private areas closed', 'Home page answer', 'OK'], A.crawlers.map(c => `<tr><td><code>${E(c.agent)}</code></td><td>${E(c.owner)}</td><td>${E({ search: 'Search', user: 'User asked', training: 'Model training' }[c.kind])}</td><td><small>${E(c.purpose)}</small></td><td>${n(c.publicAllowed)} / ${n(c.publicChecked)}</td><td>${yes(!c.privateOpen.length)}</td><td>${c.homeStatus === null ? '<span class="muted">not checked</span>' : E(c.homeStatus || 'no answer')}</td><td>${yes(c.ok)}</td></tr>`), '') : '<p class="muted">Not checked yet. The SEO agent runs it daily.</p>'}
        ${A.noindex ? `<p>Private pages answering noindex: ${A.noindex.map(p => `<code>${E(p.path)}</code> ${yes(p.ok)}`).join(' · ')}</p>` : ''}
        ${A.findings && A.findings.length ? `<ul>${A.findings.map(f => `<li><span class="pill ${f.sev === 'high' ? 'bad' : 'mid'}">${E(f.sev)}</span> ${E(f.text)}</li>`).join('')}</ul>` : A.at ? '<p><span class="pill ok">No problems found</span></p>' : ''}
        <p class="muted">${E(A.notChecked || '')}</p>
        <form id="viPol"><p><b>Model-training crawlers</b> are a separate choice from search: blocking them does not remove CVTara from search or AI answers. Your choice is written into robots.txt.</p>
          ${Object.entries(V.policy).map(([a, v]) => `<label style="display:inline-flex;gap:6px;margin-right:14px"><code>${E(a)}</code><select name="${E(a)}"><option value="allow" ${v === 'allow' ? 'selected' : ''}>Allow</option><option value="block" ${v === 'block' ? 'selected' : ''}>Block</option></select></label>`).join('')}
          <button class="button secondary small">Save</button></form>`, 'Search and AI crawlers must reach public pages; customer workspaces, the console, the API and exports must stay closed.')}`;
    $('#lkAdd', h).onclick = () => linkForm(h, Lk, null);
    $('#lkSt', h).value = L.status; $('#lkSt', h).onchange = e => { L.status = e.target.value; reach(h); };
    h.querySelectorAll('[data-edit]').forEach(b => { b.onclick = () => linkForm(h, Lk, Lk.rows.find(r => r.id === b.dataset.edit)); });
    h.querySelectorAll('[data-verify]').forEach(b => { b.onclick = async () => { b.disabled = true; try { const r = await api('/api/admin/growth/link/verify', { method: 'POST', body: { id: b.dataset.verify } }); toast(r.note || r.state || 'Checked'); reach(h); } catch (x) { toast(err(x)); b.disabled = false; } }; });
    $('#viDays', h).onchange = e => { L.days = Number(e.target.value); reach(h); };
    $('#viAdd', h).onclick = () => testForm(h, V);
    $('#viPrompts', h).onclick = () => promptForm(h, V);
    $('#viAudit', h).onclick = async e => { e.target.disabled = true; try { await api('/api/admin/growth/visibility/audit', { method: 'POST', body: {} }); toast('Checked'); reach(h); } catch (x) { toast(err(x)); e.target.disabled = false; } };
    $('#viPol', h).onsubmit = async e => { e.preventDefault(); try { await api('/api/admin/growth/visibility/policy', { method: 'POST', body: { policy: Object.fromEntries(new FormData(e.target)) } }); toast('Saved. robots.txt now follows your choice.'); } catch (x) { toast(err(x)); } };
  }

  function linkForm(h, Lk, p) {
    p = p || { method: 'resource', status: 'prospect', relevance: 3, quality: 3, spam_risk: 'unknown', link_attr: '', cost_inr: 0 };
    const m = modal(p.id ? 'Site: ' + p.domain : 'Add a site', `<form class="ops-form" id="lkF">
      <label>Site address<input name="domain" placeholder="careers.example.edu" value="${E(p.domain || '')}"></label><label>Page you would like a link on<input name="url" type="url" value="${E(p.url || '')}"></label>
      <label>How<select name="method">${opt(Lk.methods, p.method)}</select></label><label>Status<select name="status">${opt(Lk.statuses, p.status)}</select></label>
      <label>Topic relevance (0-5)<input name="relevance" type="number" min="0" max="5" value="${E(p.relevance)}"></label><label>Editorial quality (0-5)<input name="quality" type="number" min="0" max="5" value="${E(p.quality)}"></label>
      <label>Spam risk<select name="spam_risk">${opt({ unknown: 'Not assessed', low: 'Low', medium: 'Medium', high: 'High' }, p.spam_risk)}</select></label><label>Next follow-up<input name="next_follow_up" type="date" value="${E(p.next_follow_up || '')}"></label>
      <label>Contact name<input name="contact_name" maxlength="120" value="${E(p.contact_name || '')}"></label><label>Contact email<input name="contact_email" type="email" value="${E(p.contact_email || '')}"></label>
      <label class="wide">Where the contact came from<input name="contact_source" maxlength="200" placeholder="e.g. the site's own contact page" value="${E(p.contact_source || '')}"></label>
      <label class="wide">Pitch (you send it yourself) <button type="button" class="button secondary small" id="lkDraft">Draft one</button><textarea name="pitch" rows="5" maxlength="2000">${E(p.pitch || '')}</textarea></label>
      <label class="wide">Their reply<input name="response" maxlength="1000" value="${E(p.response || '')}"></label>
      <label>Published page<input name="publication_url" type="url" value="${E(p.publication_url || '')}"></label><label>CVTara page linked<input name="target_url" placeholder="/ats-checker/" value="${E(p.target_url || '')}"></label>
      <label>Link type<select name="link_attr"><option value="">Not known yet</option>${opt({ follow: 'follow', nofollow: 'nofollow', sponsored: 'sponsored', ugc: 'ugc' }, p.link_attr)}</select></label><label>Anchor text<input name="anchor" maxlength="200" value="${E(p.anchor || '')}"></label>
      <label>Cost (₹, if any)<input name="cost_inr" type="number" min="0" value="${E(p.cost_inr || 0)}"></label><label>Notes<input name="notes" maxlength="1000" value="${E(p.notes || '')}"></label>
      ${p.verify_note ? `<p class="wide muted">Last check: ${E(p.verify_note)}</p>` : ''}
      <div class="wide"><button class="button primary">Save</button> ${p.id ? '<button type="button" class="button secondary" id="lkDel">Delete</button>' : ''} <span role="status" id="lkMsg"></span></div></form>`);
    const f = $('#lkF', m.el);
    $('#lkDraft', m.el).onclick = async () => {
      if (!p.id) { $('#lkMsg', m.el).textContent = 'Save the site first, then draft a pitch.'; return; }
      try { f.pitch.value = (await api('/api/admin/growth/links/draft?id=' + encodeURIComponent(p.id))).text; } catch (x) { $('#lkMsg', m.el).textContent = err(x); }
    };
    if ($('#lkDel', m.el)) $('#lkDel', m.el).onclick = async () => { if (!confirm('Delete this site from the register?')) return; try { await api('/api/admin/growth/link/delete', { method: 'POST', body: { id: p.id } }); m.close(); reach(h); } catch (x) { $('#lkMsg', m.el).textContent = err(x); } };
    f.onsubmit = async e => {
      e.preventDefault(); const b = Object.fromEntries(new FormData(f)); b.id = p.id || null; ['relevance', 'quality', 'cost_inr'].forEach(k => { b[k] = Number(b[k] || 0); }); ['url', 'publication_url', 'next_follow_up', 'contact_email'].forEach(k => { if (!b[k]) b[k] = null; });
      try { const r = await api('/api/admin/growth/link', { method: 'POST', body: b }); toast(r.verify || 'Saved'); m.close(); reach(h); } catch (x) { $('#lkMsg', m.el).textContent = err(x); }
    };
  }

  function testForm(h, V) {
    const m = modal('Record an AI answer check', `<form class="ops-form" id="viF">
      <p class="wide muted">Ask the question in the platform's own app or site, signed out where possible, and record what the answer said. One check is one observation.</p>
      <label>Date<input name="tested_on" type="date" required value="${new Date().toISOString().slice(0, 10)}"></label><label>Platform<select name="platform">${opt(V.platformNames, V.dueSample.length ? V.dueSample[0].platform : 'google_ai')}</select></label>
      <label class="wide">Prompt<select name="prompt">${V.prompts.map(p => `<option>${E(p)}</option>`).join('')}</select></label>
      <label>Model or mode (if shown)<input name="model" maxlength="80"></label><label>Region<input name="region" value="India" maxlength="40"></label>
      <label><input type="checkbox" name="mentioned"> CVTara mentioned</label><label><input type="checkbox" name="recommended"> Recommended</label><label><input type="checkbox" name="linked"> Linked to the site</label>
      <label>Description accurate?<select name="accurate"><option value="">Not judged</option><option value="yes">Yes</option><option value="partly">Partly</option><option value="no">No</option></select></label>
      <label class="wide">CVTara page cited<input name="cited_url" maxlength="500"></label>
      <label class="wide">Competitors named (comma separated)<input name="competitors" maxlength="500"></label>
      <label class="wide">Sources cited instead<input name="sources" maxlength="1000"></label>
      <label class="wide">What CVTara's own pages are missing<input name="missing" maxlength="1000"></label>
      <label class="wide">Answer excerpt<textarea name="excerpt" rows="3" maxlength="5000"></textarea></label>
      <label class="wide">Conditions (signed in, web search on, device...)<input name="conditions" maxlength="300"></label>
      <div class="wide"><button class="button primary">Save</button> <span role="status" id="viMsg"></span></div></form>`);
    const f = $('#viF', m.el);
    f.onsubmit = async e => {
      e.preventDefault(); const b = Object.fromEntries(new FormData(f)); ['mentioned', 'recommended', 'linked'].forEach(k => { b[k] = f[k].checked; });
      try { await api('/api/admin/growth/visibility/test', { method: 'POST', body: b }); toast('Saved'); m.close(); reach(h); } catch (x) { $('#viMsg', m.el).textContent = err(x); }
    };
  }

  function promptForm(h, V) {
    const m = modal('Standard prompt list', `<form class="ops-form" id="prF"><label class="wide">One question per line, asked the same way every week<textarea name="p" rows="10">${E(V.prompts.join('\n'))}</textarea></label><div class="wide"><button class="button primary">Save</button> <span role="status" id="prMsg"></span></div></form>`);
    $('#prF', m.el).onsubmit = async e => { e.preventDefault(); try { await api('/api/admin/growth/visibility/prompts', { method: 'POST', body: { prompts: e.target.p.value.split('\n').map(s => s.trim()).filter(Boolean) } }); m.close(); reach(h); } catch (x) { $('#prMsg', m.el).textContent = err(x); } };
  }

  // ---------------------------------------------------------------- Scorecard
  const S = { days: 30 };
  async function scorecard(h) {
    h.innerHTML = '<div class="ops-skel"></div>';
    let D; try { D = await api('/api/admin/growth/scorecard?days=' + S.days); } catch (e) { fail(h, e); return; }
    const M = D.money, Cu = D.customers;
    h.innerHTML = `<div style="margin-bottom:8px">${daysPick('scDays', S.days)} <span class="muted">${E(D.period)}</span></div>
      ${panel('Money', `<div class="ops-kpis">${kpi('Confirmed collections', inr(M.grossPaise), 'verified payments')}${kpi('Refunds and chargebacks', inr(M.refundsPaise + M.chargebacksPaise), '')}${kpi('Net collected', inr(M.netPaise), '')}${kpi('Gateway fees', inr(M.gatewayFeesPaise), M.feePct === null ? 'enter your rate below' : 'estimate at ' + M.feePct + '%')}${kpi('AI spending', inr(M.aiPaise), '')}${kpi('Campaign and link costs', inr(M.campaignPaise + M.linkPaise), 'as entered')}${kpi('Contribution after variable costs', inr(M.contributionPaise), M.contributionComplete ? 'not profit: no hosting, staff or tax' : 'gateway fees missing')}</div>
        <div class="ops-kpis">${kpi('Repeat-customer revenue', inr(M.repeatPaise), '')}${kpi('Referral revenue', inr(M.referralPaise), '')}${kpi('Recovered checkouts', inr(M.recoveredPaise), 'after reminders')}${kpi('Google/Bing first-touch revenue', inr(M.organicFirstTouchPaise), 'attributed')}</div>`, D.notTracked)}
      ${panel('Customers', `<div class="ops-kpis">${kpi('Sign-ups', n(Cu.registrations), '')}${kpi('Activated', n(Cu.activated), 'made a resume')}${kpi('Paying customers', n(Cu.paidCustomers), n(Cu.newPaidCustomers) + ' new')}${kpi('Successful transactions', n(Cu.transactions), '')}${kpi('Variable cost per new paying customer', inr(Cu.variableCostPerNewCustomerPaise), '')}</div>`)}
      ${panel('Revenue by source, three ways', table(['Source', 'First touch (how they found CVTara)', 'Last touch (visit where they paid)', 'Assisted (both sources took part)'], D.attribution.map(a => `<tr><td>${E(SRC[a.source] || a.source)}</td><td>${inr(a.firstPaise)} <small class="muted">(${n(a.firstOrders)})</small></td><td>${inr(a.lastPaise)} <small class="muted">(${n(a.lastOrders)})</small></td><td>${inr(a.assistedPaise)} <small class="muted">(${n(a.assistedOrders)})</small></td></tr>`), 'No sales in this period.'), 'The first two columns each add up to net revenue, so nothing is counted twice. Assisted shows sales where the first and last source differ, credited to both; it is not added to totals. This is attribution, not proof of cause.')}
      ${panel('Agents: work, cost and permissions', `${table(['Agent', 'Level', 'Tasks done', 'Failed', 'Skipped', 'AI spending', 'Cost per task'], D.agents.map(a => `<tr><td>${E(a.label)}</td><td>${n(a.level)}</td><td>${n(a.ok)}</td><td>${n(a.failed)}</td><td>${n(a.skipped)}</td><td>${inr(a.spendPaise)}</td><td>${inr(a.costPerTaskPaise)}</td></tr>`), '')}
        ${D.aiBy.length ? table(['AI use', 'Calls', 'Spending'], D.aiBy.map(x => `<tr><td>${E(x.purpose)}</td><td>${n(x.calls)}</td><td>${inr(x.paise)}</td></tr>`), '') : ''}
        <div class="ops-two">${['seo', 'growth'].map(ag => `<div><p><b>${ag === 'seo' ? 'SEO agent' : 'Growth agent'}</b> works at level <select data-level="${ag}">${[1, 2, 3, 4].map(l => `<option value="${l}" ${l === D.levels[ag] ? 'selected' : ''}>${l}</option>`).join('')}</select></p><ol style="padding-left:18px">${Object.entries(D.matrix[ag]).map(([l, t]) => `<li value="${l}" style="${Number(l) <= D.levels[ag] ? '' : 'opacity:.55'}">${E(t)}</li>`).join('')}</ol></div>`).join('')}</div>`, 'Task counts are not results: the scorecards above are. Level 5 is never automated.')}
      ${panel('Your inputs', `<form id="scF" class="ops-form"><label>Payment gateway fee (% of each payment)<input name="gatewayFeePct" type="number" step="0.01" min="0" max="10" value="${E(D.costs.gatewayFeePct === null ? '' : D.costs.gatewayFeePct)}" placeholder="e.g. 2"></label><label>Most journey mails per journey per day<input name="dailyCap" type="number" min="0" max="1000" value="${E(D.lifecycle.dailyCap)}"></label><div class="wide"><button class="button secondary small">Save</button></div></form>`)}`;
    $('#scDays', h).onchange = e => { S.days = Number(e.target.value); scorecard(h); };
    h.querySelectorAll('[data-level]').forEach(s => { s.onchange = async () => { try { await api('/api/admin/growth/levels', { method: 'POST', body: { agent: s.dataset.level, level: Number(s.value) } }); toast('Saved'); scorecard(h); } catch (x) { toast(err(x)); } }; });
    $('#scF', h).onsubmit = async e => { e.preventDefault(); const b = Object.fromEntries(new FormData(e.target)); b.gatewayFeePct = b.gatewayFeePct === '' ? null : Number(b.gatewayFeePct); b.dailyCap = Number(b.dailyCap || 0); try { await api('/api/admin/growth/costs', { method: 'POST', body: b }); toast('Saved'); scorecard(h); } catch (x) { toast(err(x)); } };
  }

  window.CVTaraOperators = { customers, reach, scorecard };
})();
