/* Browser-only preview helper: builds an ATS report the way the server does, so the static preview can show the whole flow. */
(function(){
'use strict';
  const statusLabel = { evidenced: 'Evidence found', mentioned: 'Mentioned only', not_found: 'Not found', review: 'Needs your review' };
  const weight = { Required: 3, Preferred: 1 };
  const credit = { evidenced: 1, mentioned: 0.5, review: 0.25, not_found: 0 };

  const bullets = t => String(t || '').split('\n').map(l => l.replace(/^\s*[•*-]\s*/, '').trim()).filter(Boolean);
  function resumeToText(d) {
    const p = d.personal || {}, out = [p.name, p.role, [p.email, p.phone, p.city, p.linkedin, p.portfolio].filter(Boolean).join(' | ')];
    const when = r => [r.start, r.current ? 'Present' : (r.end || r.date)].filter(Boolean).join(' - ');
    if (d.summary) out.push('SUMMARY', d.summary);
    if (d.skills) out.push('SKILLS', d.skills);
    if ((d.experience || []).length) { out.push('EXPERIENCE'); d.experience.forEach(r => out.push([r.role, r.company, r.city, when(r)].filter(Boolean).join(' | '), ...bullets(r.bullets || r.details).map(b => '• ' + b))); }
    if ((d.projects || []).length) { out.push('PROJECTS'); d.projects.forEach(r => out.push([r.name, r.tools, r.link].filter(Boolean).join(' | '), ...bullets(r.bullets || r.details).map(b => '• ' + b))); }
    if ((d.education || []).length) { out.push('EDUCATION'); d.education.forEach(r => out.push([r.degree, r.school, when(r)].filter(Boolean).join(' | '), ...bullets(r.details || r.bullets))); }
    if ((d.certifications || []).length) { out.push('CERTIFICATIONS'); d.certifications.forEach(r => out.push([r.name, r.issuer, r.date].filter(Boolean).join(' | '))); }
    if (d.languages) out.push('LANGUAGES', d.languages);
    if (d.awards) out.push('AWARDS', d.awards);
    return out.filter(x => x !== undefined && x !== null && String(x).trim() !== '').join('\n');
  }

  const STOP = new Set(('the a an and or of for to in on at by with as from be is are was were have has this that your our you we will must should required requires requirements about into over under their they them his her its it who whom which what when where while also able ability across all any can could may might more most other some such than then there these those through using use used within without would work working works role roles job jobs team teams candidate candidates company looking join looking skills skill strong good great excellent knowledge experience experienced years year plus etc including include includes ensure ensuring manage managing responsible responsibilities responsibility preferred nice have having bonus must apply application applications position opportunity opportunities benefits offer offers hybrid remote office full time part employee employees environment business new help support provide providing well one two three first based related relevant like both each every per via day days month months week weeks need needs needed make making get getting take taking high level highly ideal best build write present develop design deliver create lead drive own maintain participate collaborate communicate perform conduct provide identify improve analyse analyze understand deep proven track record seeking looking hire hiring join').split(/\s+/));
  const stem = w => w.length > 5 ? w.replace(/(ing|ed|es|ly|ions?|ment|s)$/, '') : w.replace(/s$/, '');
  function tokens(t) { return (String(t).match(/[A-Za-z][A-Za-z0-9+#.]*[A-Za-z0-9+#]|[A-Za-z]/g) || []); }
  function keywordCoverage(resumeText, jobText, title) {
    const have = new Set(tokens(resumeText).map(w => stem(w.toLowerCase())));
    const freq = new Map(), shown = new Map(), tl = String(title || '').toLowerCase(), src = String(jobText), re = /[A-Za-z][A-Za-z0-9+#.]*[A-Za-z0-9+#]|[A-Za-z]/g;
    let m;
    while ((m = re.exec(src))) {
      const raw = m[0], w = raw.toLowerCase();
      if (w.length < 3 || STOP.has(w)) continue;
      const before = src.slice(Math.max(0, m.index - 3), m.index).replace(/[ \t•*\-–]+$/, ''), starts = before === '' || /[.!?:\n]$/.test(before);
      // A capital letter inside a sentence, an acronym or a symbol marks a tool, product or skill name.
      const named = /[A-Z].*[A-Z]|[+#]/.test(raw) || (/^[A-Z][a-z]/.test(raw) && !starts);
      const k = stem(w), cur = freq.get(k) || { n: 0, bonus: 0 };
      cur.n++; if (named) cur.bonus = Math.max(cur.bonus, 2); if (tl.includes(w)) cur.bonus += 2;
      freq.set(k, cur); if (!shown.has(k) || named) shown.set(k, raw);
    }
    const ranked = [...freq.entries()].map(([k, v]) => [k, v.n + v.bonus, v]).filter(([, , v]) => v.n >= 2 || v.bonus > 0).sort((a, b) => b[1] - a[1]).slice(0, 30);
    const found = ranked.filter(([k]) => have.has(k)).map(([k]) => shown.get(k)), missing = ranked.filter(([k]) => !have.has(k)).map(([k]) => shown.get(k));
    return { total: ranked.length, found, missing, pct: ranked.length ? Math.round((found.length / ranked.length) * 100) : null };
  }
  function score(r, kw) {
    let got = 0, total = 0;
    for (const x of r.requirements) { const w = weight[x.priority] || 2; total += w; got += w * (credit[x.status] ?? 0); }
    const ev = total ? (got / total) * 100 : null;
    if (ev === null && kw.pct === null) return 0;
    if (ev === null) return kw.pct;
    if (kw.pct === null) return Math.round(ev);
    const w = Math.min(0.7, 0.1 * r.requirements.length);
    return Math.round(w * ev + (1 - w) * kw.pct);
  }
  function gaps(r, kw) {
    const order = { not_found: 0, mentioned: 1, review: 2 }, out = [];
    if (kw.missing.length) out.push(('Keywords · Missing from your resume: ' + kw.missing.slice(0, 12).join(', ') + '. Add them only where they truthfully describe your experience.').slice(0, 240));
    out.push(...r.requirements.filter(x => x.status !== 'evidenced')
      .sort((a, b) => ((weight[b.priority] || 2) - (weight[a.priority] || 2)) || ((order[a.status] ?? 3) - (order[b.status] ?? 3)))
      .slice(0, 30).map(x => `${x.priority} · ${x.name} — ${statusLabel[x.status]}. ${x.advice || ''}`.slice(0, 240)));
    out.push(...(r.parameters || []).filter(x => x.status === 'Review').slice(0, 6).map(x => `Resume format · ${x.name} — ${x.action}`.slice(0, 240)));
    return out.slice(0, 40);
  }
  const tone = n => n >= 75 ? 'good' : n >= 50 ? 'mid' : 'low';
  const verdict = n => n >= 75 ? 'Strong match — polish the details and apply.' : n >= 50 ? 'Partial match — close the gaps below before you apply.' : 'Low match — this resume needs work for this job.';


window.__atsReport=function(resumeData,jobText,title){
  var text=resumeToText(resumeData||{});
  var result=window.CVTaraMatch.analyse(text.length>=60?text:'Sample resume text for the preview. Experienced analyst with SQL and Excel skills. Built dashboards and reports for stakeholders.',jobText);
  var kw=keywordCoverage(text,jobText,title);
  return {score:score(result,kw),keywords:kw,gaps:gaps(result,kw),result:result,details:{location:'Bengaluru, India',employmentType:'Full time'}};
};
})();
