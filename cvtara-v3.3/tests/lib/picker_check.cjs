// Loads the shipped data and picker scripts in a sandbox and checks every role, industry, style and candidate type.
// Usage: node picker_check.cjs <public_html> <seo-pages.json>   Prints one JSON report.
const fs = require('fs'), vm = require('vm'), path = require('path');
const [pub, seoFile] = process.argv.slice(2);
const ctx = {console, URLSearchParams, setTimeout, Promise};
ctx.window = ctx; vm.createContext(ctx);
for (const f of ['data.js', 'reference-templates.js', 'premium-collection.js', 'template-curation.js', 'template-picker.js']) vm.runInContext(fs.readFileSync(path.join(pub, f), 'utf8'), ctx, {filename: f});
const P = ctx.CVTaraPicker, D = ctx.CVTARA_DATA, R = ctx.CVTaraReferenceTemplates, L = ctx.CVTaraPremium;
const lib = JSON.parse(fs.readFileSync(path.join(pub, 'role-library.json'), 'utf8')), seo = JSON.parse(fs.readFileSync(seoFile, 'utf8'));
P.load(lib);
const bad = [], add = (what, why) => { if (bad.length < 40) bad.push(what + ': ' + why); };
const counts = {roles: 0, industries: 0, styles: 0, cityPages: 0, combos: 0, letters: 0, searches: 0};
const textOf = d => [d.summary, d.skills, ...(d.experience || []).map(x => x.bullets)].join(' ');
function checkResume(label, choice, style) {
  const list = P.designsFor({...choice, style});
  if (list.length !== 5 || new Set(list.map(t => t.slug)).size !== 5) return add(label, 'expected 5 different designs, got ' + list.length);
  for (const t of list) {
    const d = P.resumeDoc(choice, t);
    if (!d.summary || !d.skills || !(d.experience || []).some(x => x.bullets)) return add(label, 'missing summary, skills or bullets');
    if (/undefined|null|\{\w+\}|NaN/.test(textOf(d) + d.personal.role)) return add(label, 'unfilled text: ' + textOf(d).slice(0, 120));
    const html = R.render(t, d, {sample: true});
    if (!html.includes('ref-resume') || !html.includes(d.personal.name)) return add(label, 'did not render');
  }
  counts.combos++;
}
function checkLetters(label, choice) {
  const list = P.lettersFor(choice);
  if (list.length !== 5 || new Set(list.map(l => l.slug)).size !== 5) return add(label, 'expected 5 letters');
  for (const l of list) {
    const d = P.letterDoc(choice, l), body = [d.opening, d.evidence, d.closing].join(' ');
    if (!d.opening || !d.closing || /undefined|null|\{\w+\}/.test(body + d.subject)) return add(label + ' ' + l.slug, 'letter text: ' + body.slice(0, 160));
    if (!L.renderLetter(d, {sample: true}).includes('letter-document')) return add(label, 'letter did not render');
  }
  counts.letters++;
}
const types = D.audiences.map(a => a.id);
for (const role of Object.keys(lib.roles)) { counts.roles++; for (const type of types) { checkResume(`role ${role} / ${type}`, {type, role}); checkLetters(`letters ${role} / ${type}`, {type, role}); } }
for (const ind of Object.keys(lib.industries)) { counts.industries++; for (const type of types) { checkResume(`industry ${ind} / ${type}`, {type, industry: ind}); checkLetters(`letters ${ind} / ${type}`, {type, industry: ind}); } }
for (const style of Object.keys(seo.templates)) { counts.styles++; for (const type of types) checkResume(`style ${style} / ${type}`, {type}, style); }
for (const [city, rs] of Object.entries(seo.cityRoles)) for (const [slug, x] of Object.entries(rs)) { counts.cityPages++; if (!lib.roles[x.role]) add(`city ${city}/${slug}`, 'role missing from the library'); else checkResume(`city ${city}/${slug}`, {type: 'experienced', role: x.role}); }
for (const r of Object.keys(seo.roles)) if (!lib.roles[r]) add('role ' + r, 'missing from role-library.json');
for (const c of Object.keys(seo.categories)) if (!lib.industries[c]) add('category ' + c, 'missing from role-library.json');
// every catalog profession group and every resume design name resolves to a role or industry
for (const c of new Set(D.templates.map(t => t.category))) { counts.searches++; if (c !== 'Reference collection' && P.resolve(c).match === 'custom') add('category ' + c, 'search finds nothing'); }
const typed = {'react developer': 'front-end-developer', 'accountant': 'accountant', 'acountant': 'accountant', 'nurse': 'staff-nurse', 'truck driver': 'driver', 'ca': 'chartered-accountant', 'call centre': 'customer-support', 'software developer': 'software-engineer', 'teacher': 'teacher', 'devops': 'devops-engineer', 'hr recruiter': 'hr-recruiter', 'graphic designer': 'graphic-designer', 'electrician': 'electrician', 'pharmacist': 'pharmacist', 'mba fresher': 'freshers'};
for (const [q, want] of Object.entries(typed)) { counts.searches++; const r = P.resolve(q); if (r.role !== want) add(`search "${q}"`, `found ${r.role || r.industry || r.title}`); }
for (const q of ['astronaut', 'zzqx', 'Chief Happiness Officer']) { counts.searches++; const r = P.resolve(q); if (!r.title) add(`search "${q}"`, 'empty'); for (const type of types) checkResume(`custom ${q} / ${type}`, {type, role: r.role, industry: r.industry, title: r.match === 'custom' ? r.title : ''}); }
const per = {};
for (const a of types) per[a] = {resumes: D.templates.filter(t => t.audience === a).length, letters: D.coverLetters.filter(l => l.audience === a).length};
console.log(JSON.stringify({ok: !bad.length, bad, counts, per, templates: D.templates.length, letters: D.coverLetters.length}));
