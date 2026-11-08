/* Guided template picker: experience level → industry or job title → the five best designs, previewed with sample content for that job.
   Used on templates.html, cover-letters.html, the workspace choosers and the SEO pages. The data comes from data.js (designs, letters,
   candidate types) and /role-library.json (every role and industry the site publishes, built by tools/seo/build_role_library.py). */
(() => {
'use strict';
const D = window.CVTARA_DATA; if (!D || !D.audiences) return;
const A = D.audiences, TYPES = A.map(a => a.id);
let LIB = null, loading = null;
const E = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const norm = s => String(s || '').toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9+#]+/g, ' ').trim();
const cap = s => String(s || '').replace(/^./, c => c.toUpperCase());
const lc = s => { s = String(s || '').trim().replace(/\.$/, ''); return /^[A-Z][A-Z0-9]/.test(s) ? s : s.replace(/^./, c => c.toLowerCase()); };
const soft = s => { s = String(s || '').trim(); const rest = s.slice(1); if (!s || /[A-Z]/.test(rest) || /\d/.test(s)) return s; return s.includes(' ') || /(tion|sion|ing|ship|ity|ment|ance|ence|work|ness|ics|ery|ure|ism)$/.test(s) ? s[0].toLowerCase() + rest : s; };
const list = (a, n = 3) => { a = a.slice(0, n).map(soft); return a.length > 1 ? a.slice(0, -1).join(', ') + ' and ' + a.at(-1) : a[0] || ''; };
const hash = s => [...String(s)].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7);

function load(lib) { LIB = lib; return lib; }
function ready() {
  if (LIB) return Promise.resolve(LIB);
  return loading ||= fetch('/role-library.json', {credentials: 'same-origin'}).then(r => { if (!r.ok) throw new Error('role library ' + r.status); return r.json(); }).then(load).catch(e => { loading = null; throw e; });
}

// ---------------------------------------------------------------- search
function lev(a, b, max) {
  if (Math.abs(a.length - b.length) > max) return max + 1;
  let prev = Array.from({length: b.length + 1}, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i]; let best = i;
    for (let j = 1; j <= b.length; j++) { cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1)); best = Math.min(best, cur[j]); }
    if (best > max) return max + 1; prev = cur;
  }
  return prev[b.length];
}
function tokenScore(q, words) {
  let best = 0;
  for (const w of words) {
    if (w === q) return 1;
    if (q.length >= 2 && w.startsWith(q)) best = Math.max(best, .9);
    else if (q.length >= 4 && w.length >= 4 && lev(q, w, q.length >= 7 ? 2 : 1) <= (q.length >= 7 ? 2 : 1)) best = Math.max(best, .72);
  }
  return best;
}
function nameScore(nq, qt, name) {
  const nn = norm(name); if (!nn) return 0;
  if (nn === nq) return 100;
  if (nq.length >= 2 && nn.startsWith(nq)) return 92;
  if (nq.length >= 3 && (' ' + nn + ' ').includes(' ' + nq)) return 86;
  const words = nn.split(' '), sc = qt.map(t => tokenScore(t, words)), hit = sc.filter(Boolean).length;
  if (!hit) return 0;
  const avg = sc.reduce((a, b) => a + b, 0) / qt.length;
  return hit === qt.length ? 40 + 40 * avg : 30 * avg;
}
/** Ranked matches for a typed job title: roles first, then industries. Never throws; returns [] only for an empty query. */
function search(query, limit = 8) {
  const nq = norm(query); if (!nq || !LIB) return [];
  const qt = nq.split(' ').filter(t => t.length > 1 || /\d/.test(t)); if (!qt.length) qt.push(nq);
  const out = [];
  for (const [key, r] of Object.entries(LIB.roles)) {
    const s = Math.max(nameScore(nq, qt, r.t), ...(r.syn || []).map(x => nameScore(nq, qt, x) * .95));
    if (s >= 30) out.push({kind: 'role', key, industry: r.c, title: r.t, score: s + .01});
  }
  for (const [key, c] of Object.entries(LIB.industries)) {
    const s = Math.max(nameScore(nq, qt, c.n), ...(c.syn || []).map(x => nameScore(nq, qt, x) * .9)) * .85;
    if (s >= 30) out.push({kind: 'industry', key, industry: key, title: c.n, score: s});
  }
  return out.sort((a, b) => b.score - a.score).slice(0, limit);
}
/** Turns any text into a usable choice: the best role, else its industry, else the text itself as a custom job title. */
function resolve(query) {
  const hits = search(query, 1), top = hits[0];
  if (top && top.kind === 'role' && top.score >= 55) return {role: top.key, industry: top.industry, title: LIB.roles[top.key].t, match: 'role'};
  if (top && top.score >= 40) return top.kind === 'role' ? {role: top.key, industry: top.industry, title: LIB.roles[top.key].t, match: 'close'} : {role: '', industry: top.key, title: LIB.industries[top.key].n, match: 'industry'};
  const title = cap(String(query || '').trim().slice(0, 60)) || 'Your next role';
  return {role: '', industry: '', title, match: 'custom'};
}

// ---------------------------------------------------------------- designs
const SIDE = /sidebar|^signal$|two-column|infographics|^suite-(vector|forma|prism|folio)$/;
const STYLE_TYPE = {fresher: 'fresher', experienced: 'experienced', freelance: 'freelance', 'career-change': 'career-change', senior: 'senior', internship: 'internship'};
const STYLES = {
  'ats-friendly': t => t.ats && !SIDE.test(t.layout) && !t.photo,
  simple: t => t.ats && !t.creative && !t.photo && !SIDE.test(t.layout) && /clarity|compact|campus|minimal|classic|sb2nov|engineering|opal/.test(t.layout),
  professional: t => t.ats && !t.creative && /executive|ledger|meridian|timeline|harvard|moderncv|clarity|compact/.test(t.layout),
  modern: t => /modern|signal|technical|meridian|studio|apex|opal|vector|chroma/.test(t.layout),
  classic: t => t.defaultFont === 'Georgia' || /executive|harvard|classic|ledger|ink/.test(t.layout),
  creative: t => !!t.creative || /canvas|gallery|chroma|prism|folio|studio|atelier/.test(t.layout),
  minimalist: t => !t.photo && /minimal|clarity|compact|sb2nov|atelier|opal|latexcv-classic/.test(t.layout),
  corporate: t => t.ats && /executive|compact|ledger|timeline|meridian|apex|moderncv|harvard/.test(t.layout),
  'one-page': t => t.ats && !t.photo && /compact|clarity|campus|minimal|sb2nov|technical|classic|origin/.test(t.layout),
  'one-column': t => t.ats && !SIDE.test(t.layout),
  'two-column': t => SIDE.test(t.layout),
  'with-photo': t => !!t.photo
};
const pool = () => D.templates.filter(t => t.publicPublished !== false && !t.curatedHidden);
const kindOf = industry => (LIB?.industries?.[industry]?.kind) || 'general';
function fit(t, kind) {
  if (kind === 'creative') return (t.creative || t.visual ? 3 : 0) + (t.ats ? .5 : 0);
  if (kind === 'formal') return (t.ats ? 2 : 0) + (t.defaultFont === 'Georgia' ? 1 : 0) - (t.creative ? 2 : 0) - (t.visual ? 1 : 0);
  if (kind === 'modern') return (/modern|meridian|technical|signal|vector|studio|apex|opal/.test(t.layout) ? 2 : 0) + (t.ats ? 1 : 0);
  return (t.ats ? 1 : 0) - (t.creative ? .5 : 0);
}
const rank = (list, kind) => list.map((t, i) => [t, fit(t, kind), i]).sort((a, b) => b[1] - a[1] || a[2] - b[2]).map(x => x[0]);
/** Exactly five designs for a candidate type, ordered for the industry; a template style narrows the choice across the whole library. */
function designsFor({type = 'experienced', industry = '', style = ''} = {}) {
  if (STYLE_TYPE[style]) { type = STYLE_TYPE[style]; style = ''; }
  if (!TYPES.includes(type)) type = 'experienced';
  const all = pool(), kind = kindOf(industry), own = rank(all.filter(t => t.audience === type), kind);
  let picks = [];
  if (STYLES[style]) {
    const m = all.filter(STYLES[style]);
    picks = [...rank(m.filter(t => t.audience === type), kind), ...rank(m.filter(t => t.audience && t.audience !== type), kind), ...rank(m.filter(t => !t.audience), kind)];
  }
  const seen = new Set(), out = [];
  for (const t of [...picks, ...own, ...rank(all.filter(t => t.ats), kind), ...all]) { if (out.length === 5) break; if (!seen.has(t.slug)) { seen.add(t.slug); out.push(t); } }
  return out;
}
/** Exactly five cover letters for a candidate type. */
function lettersFor({type = 'experienced'} = {}) {
  if (!TYPES.includes(type)) type = 'experienced';
  const all = D.coverLetters.filter(l => l.publicPublished !== false), own = all.filter(l => l.audience === type), seen = new Set(), out = [];
  for (const l of [...own, ...all.filter(l => l.audience), ...all]) { if (out.length === 5) break; if (!seen.has(l.slug)) { seen.add(l.slug); out.push(l); } }
  return out;
}

// ---------------------------------------------------------------- sample content
const PEOPLE = [['Aditya Rao','Bengaluru'],['Pooja Sharma','Jaipur'],['Rahul Menon','Kochi'],['Sneha Patil','Pune'],['Imran Qureshi','Hyderabad'],['Kavita Joshi','Lucknow'],['Arvind Iyer','Chennai'],['Nisha Gupta','Delhi NCR'],['Sanjay Das','Kolkata'],['Fatima Shaikh','Mumbai'],['Gurpreet Kaur','Chandigarh'],['Deepak Verma','Indore'],['Lakshmi Reddy','Visakhapatnam'],['Vikas Yadav','Patna'],['Meenal Desai','Ahmedabad'],['Joseph Mathew','Thiruvananthapuram']];
const FIRMS = ['Northstar','Bluepeak','Riverstone','Sunrise','Greenleaf','Silverline','Kaveri','Himgiri','Coastline','Deccan','Lotus','Indus'];
const SUFFIX = {'information-technology':'Technologies','engineering':'Engineering Works','accounting-finance':'Finserv','sales':'Distributors','marketing':'Brands','human-resources':'People Services','education':'Public School','government':'District Office','retail':'Retail','legal':'Law Chambers','maintenance-repair':'Facility Services','administrative':'Business Services','real-estate':'Realty','production':'Industries','business-management':'Consulting','security-protective-services':'Security Services','hospitality-catering':'Hotels','transport-logistics':'Logistics','transportation':'Transport','medical':'Hospital','sport-fitness':'Fitness Club','social-work':'Foundation','construction':'Builders','beauty-wellness':'Salon & Spa','student':'Services','creative-artistic':'Studio'};
const TRADE = /^(maintenance-repair|security-protective-services|transportation|beauty-wellness|sport-fitness|construction|hospitality-catering)$/;
const SELF = /^(electrician|ac-technician|maintenance-technician|beautician|hair-stylist|fitness-trainer|yoga-instructor|driver|delivery-executive|chef)$/;

/** Role content for any choice. Roles from the designs' own sample profiles reuse that profile; unknown titles get honest general content. */
function roleData({role = '', industry = '', title = ''} = {}) {
  const r = LIB?.roles?.[role];
  if (r && r.p && D.resumeProfiles[r.p]) { const p = D.resumeProfiles[r.p]; return {key: role, t: r.t, c: r.c, s: p.summary, b: p.experience, k: String(p.skills || '').split(' · '), d: p.education?.split(' · ')[0]}; }
  if (r) return {key: role, ...r};
  const ind = LIB?.industries?.[industry];
  if (ind && ind.roles?.length && !title) return roleData({role: ind.roles[0]});
  if (ind && ind.roles?.length) { const base = roleData({role: ind.roles[0]}); return {...base, key: '', t: title}; }
  const t = title || 'Your next role';
  return {key: '', t, c: '', s: `${t} with a steady record of reliable work, clear communication and learning new tools quickly.`, b: ['Completed daily work accurately and on time, and kept clear records of each task.', 'Worked with colleagues and customers to solve problems and follow up until they were closed.', 'Suggested and tested small improvements that saved the team time each week.', 'Trained new joiners and wrote short guides for common tasks.'], k: ['Communication', 'Teamwork', 'Problem solving', 'MS Office', 'Planning', 'Reporting', 'Time management', 'Customer focus']};
}
const fieldOf = c => { const n = LIB?.industries?.[c]?.n; return !n || c === 'student' ? 'entry-level work' : n.toLowerCase().replace(' & ', ' and '); };
function titleFor(type, r) {
  const t = r.t, c = r.c || '';
  if (type === 'fresher') return /intern|trainee|graduate|student|fresher/i.test(t) ? t : t + ' (Fresher)';
  if (type === 'internship') return /intern/i.test(t) ? t : (TRADE.test(c) || SELF.test(r.key || '') ? 'Trainee ' + t : t + ' Intern');
  if (type === 'senior') return c === 'student' ? 'Senior Manager' : /^(senior|head|chief|director|vice)/i.test(t) ? t : r.key === 'doctor' ? 'Senior Consultant Physician' : 'Senior ' + t;
  if (type === 'freelance') return c === 'student' ? 'Freelance Consultant' : SELF.test(r.key || '') ? 'Self-employed ' + t : 'Freelance ' + t;
  return c === 'student' && type !== 'career-change' ? 'Associate' : t;
}
/** A complete fictional resume for a candidate type and job, ready for CVTaraReferenceTemplates.render. */
function resumeDoc(choice = {}, template) {
  const type = TYPES.includes(choice.type) ? choice.type : 'experienced';
  let r = roleData(choice);
  const student = r.c === 'student';
  if (student && !['fresher', 'internship'].includes(type)) { const p = D.resumeProfiles[A.find(a => a.id === type).profile]; r = {key: r.key, t: p.role.toLowerCase().replace(/(^|[\s/(-])([a-z])/g, (m, a, b) => a + b.toUpperCase()), c: '', s: p.summary, b: p.experience, k: p.skills.split(' · '), d: p.education}; }
  const ind = LIB?.industries?.[r.c] || {}, field = fieldOf(r.c), k = (r.k || []).filter(Boolean), b = r.b || [];
  const h = hash((r.key || r.t) + type), [name, city] = PEOPLE[h % PEOPLE.length], firm = n => `${FIRMS[(h + n) % FIRMS.length]} ${SUFFIX[r.c] || 'Services'}`;
  const title = choice.title && !r.key ? choice.title : titleFor(type, r), degree = r.d || ind.degree || 'Bachelor’s degree';
  const trade = TRADE.test(r.c) || SELF.test(r.key || '');
  const doc = {version: 1, title: 'Example · ' + title, template: template?.slug || '', accent: template?.accent || '#2258d6', font: template?.defaultFont || 'Arial', spacing: 'comfortable', photo: '', showPhoto: true, photoShape: 'round', documentLanguage: 'en', sectionLabels: {},
    personal: {name, role: title, email: name.toLowerCase().replace(/[^a-z]+/g, '.') + '@example.com', phone: '+91 98XX XX3210', city: city + ', India', linkedin: 'linkedin.com/in/sample', portfolio: ''},
    summary: '', skills: k.slice(0, 8).join(' · '), languages: 'English · Hindi', awards: '', experience: [], education: [], projects: [], certifications: [], order: ['summary', 'experience', 'projects', 'education', 'skills', 'certifications', 'languages', 'awards'], hidden: [], example: true, sample: {type, role: r.key || '', industry: r.c || ''}};
  const job = (role, company, start, end, bullets) => ({role, company, city: '', type: '', start, end: end === 'Present' ? '' : end, current: end === 'Present', bullets: bullets.filter(Boolean).join('\n')});
  const school = r.c === 'medical' ? 'Government Medical College' : r.c === 'legal' ? 'State Law University' : trade ? 'Government ITI' : 'State University';
  if (type === 'experienced') {
    doc.summary = r.s;
    doc.experience = [job(r.t, firm(0), '2021', 'Present', b.slice(0, 4)), job(trade ? 'Assistant ' + r.t : 'Junior ' + r.t, firm(3), '2018', '2021', ['Learned the work under a senior colleague and took on more responsibility each year.', `Used ${k[1] || k[0] || 'core skills'} daily and kept accurate records.`])];
    doc.education = [{degree, school, start: '', end: '2018', grade: '', details: ''}];
  } else if (type === 'senior') {
    const team = trade ? 18 : 40, budget = trade ? '₹1.5 crore' : '₹12 crore';
    doc.summary = `${title} with 15 years of experience in ${field}, the last 6 leading teams of up to ${team} people and owning annual budgets. Strong in ${list(k)}, and known for building people who can run the work independently.`;
    doc.experience = [job(title, firm(0), '2019', 'Present', [`Leads a team of ${team} through ${trade ? 3 : 5} team leads across ${trade ? 'two shifts' : 'three locations'}.`, `Owns an annual budget of ${budget} and has delivered within plan for four years.`, cap(b[0] || ''), 'Built a training and promotion plan; three team leads were promoted from within.']), job(r.t, firm(4), '2014', '2019', [b[1], b[2]]), job(trade ? 'Assistant ' + r.t : 'Junior ' + r.t, firm(7), '2010', '2014', [b[3]])];
    doc.education = [{degree: /MBA/.test(degree) ? degree : degree + ' · Management programme', school, start: '', end: '2010', grade: '', details: ''}];
  } else if (type === 'freelance') {
    doc.summary = trade ? `${title} serving homes and small businesses around ${city} since 2020. Known for ${soft(k[0] || 'reliable work')}, fair pricing and arriving on time.` : `${title} working with small businesses and start-ups across India since 2021. Known for ${soft(k[0] || 'quality work')}, written scopes and on-time delivery.`;
    doc.personal.portfolio = trade ? '' : 'portfolio.example.com/' + name.split(' ')[0].toLowerCase();
    doc.experience = [job(title, trade ? 'Self-employed' : 'Independent practice', trade ? '2020' : '2021', 'Present', [trade ? 'Serves 60+ regular customers in nearby areas, booked by phone and messages.' : `Delivered ${soft(k[0] || 'project')} work for 20+ clients, with a written scope and fixed timeline for each.`, b[0] ? 'For a client: ' + lc(b[0]) + '.' : '', b[1] ? 'For another client: ' + lc(b[1]) + '.' : '', 'Most new work comes from repeat clients and referrals.']), job(r.t, firm(2), '2017', trade ? '2020' : '2021', [b[2], b[3]])];
    doc.projects = trade ? [] : [{name: 'Selected client work', role: 'Freelance', tools: k.slice(0, 3).join(', '), link: '', details: `Short case notes for three recent clients: the brief, my work and the result.`}];
    doc.education = [{degree, school, start: '', end: '2017', grade: '', details: ''}];
  } else if (type === 'career-change') {
    const prev = ind.prev || 'customer service';
    doc.summary = `Moving into ${field} as a ${r.t} after 6 years in ${prev}. Brings transferable strengths in communication, planning and problem solving, and has completed practical training in ${list(k, 2) || 'the core skills'}.`;
    doc.order = ['summary', 'skills', 'projects', 'experience', 'certifications', 'education', 'languages', 'awards'];
    doc.experience = [job(`Senior Executive, ${cap(prev)}`, firm(5), '2019', 'Present', ['Handled day-to-day work for a team of 10 and kept clear records of every task.', 'Trained new joiners and wrote simple guides that the team still uses.', `Took on ${soft(k[0] || 'new')} tasks in this role to build experience before switching.`, 'Explained weekly results to managers and suggested one improvement each week.'])];
    doc.projects = [{name: `${r.t} portfolio project`, role: 'Self-directed', tools: k.slice(0, 3).join(', '), link: '', details: `Completed a practical course covering ${list(k)}.\nFinished a portfolio project with a short write-up of the approach and results.`}];
    doc.certifications = [{name: `Certificate in ${k[0] || r.t}`, issuer: 'Practical course', date: '2025', link: ''}];
    doc.education = [{degree: ind.degree || 'Bachelor’s degree', school, start: '', end: '2019', grade: '', details: ''}];
  } else if (type === 'fresher') {
    doc.order = ['summary', 'education', 'projects', 'experience', 'skills', 'certifications', 'languages', 'awards'];
    doc.summary = student ? r.s : `Recent ${degree.split(',')[0].split(' · ')[0]} graduate looking for a first role as a ${r.t}. Trained in ${list(k)} through coursework, a final-year project and a short internship. Quick to learn, careful with details and comfortable working in a team.`;
    doc.education = [{degree, school, start: '2022', end: '2026', grade: 'First class', details: ''}];
    doc.experience = student ? [job('Intern', firm(1), '2025', '2025', b.slice(0, 2))] : [job((trade ? 'Trainee ' : '') + r.t + (trade ? '' : ' Intern'), firm(1), '2025', '2025', [`Supported the ${field} team with day-to-day ${soft(k[0] || 'core')} and ${soft(k[1] || 'support')} tasks under a senior’s guidance.`, 'Kept a daily log of tasks and learning, and shared a short summary with my manager each week.', 'Followed the team’s checklists and asked for feedback on every piece of work.'])];
    doc.projects = student ? [{name: 'College project', role: 'Team member', tools: '', link: '', details: b.slice(2).join('\n')}] : [{name: 'Final-year project', role: 'Team of four', tools: k.slice(2, 4).join(', '), link: '', details: `Completed a project on ${soft(k[0] || 'the subject')} with three classmates and presented the results to the department.\nWrote a short report on what worked, what did not and what we would change.`}];
    doc.certifications = [{name: `Certificate in ${k[1] || k[0] || 'core skills'}`, issuer: 'Online course', date: '2025', link: ''}];
  } else {
    doc.order = ['summary', 'education', 'projects', 'experience', 'skills', 'certifications', 'languages', 'awards'];
    doc.summary = student ? r.s : `Student looking for an internship as a ${title}. Comfortable with ${list(k, 2)}, and keen to learn how a working ${field} team operates.`;
    doc.education = [{degree: /year\)/.test(degree) ? degree : degree + ' (2nd year)', school, start: '2024', end: '2028', grade: '', details: ''}];
    doc.projects = [{name: 'Course project', role: 'Team member', tools: k.slice(0, 2).join(', '), link: '', details: student ? b.slice(0, 2).join('\n') : `Used ${list(k, 2)} in a semester project and presented it to the class.\nKept notes on feedback and improved the final version.`}];
    doc.experience = [job('Class Representative', 'College', '2024', 'Present', student ? b.slice(2) : ['Shared notices and collected feedback for a class of 60 students.', 'Helped organise two department events with a team of eight.'])];
  }
  return doc;
}
/** A complete fictional cover letter (letterSample shape) for a candidate type and job, using the letter's own wording. */
function letterDoc(choice = {}, letter) {
  const P = window.CVTaraPremium, base = P ? P.letterSample(letter) : {};
  if (!letter?.bodyTemplate) return base;
  const type = letter.audience || choice.type || 'experienced';
  let r = roleData(choice);
  if (!r.key && !choice.title && !choice.industry) return base;
  if (r.c === 'student' && !['fresher', 'internship'].includes(type)) return base;
  const k = (r.k || []).filter(Boolean), b = r.b || [], ind = LIB?.industries?.[r.c] || {}, trade = TRADE.test(r.c) || SELF.test(r.key || '');
  const title = choice.title && !r.key ? choice.title : titleFor(type, r);
  const sk = i => (k[i] ? soft(k[i]) : letter.fill['skill' + (i + 1)]);
  const h = hash((r.key || r.t) + type), yrs = (String(r.s || '').match(/(\d+)\+? years/) || [])[1];
  const f = {...letter.fill, role: type === 'freelance' ? r.t : title, company: r.c ? `${FIRMS[(h + 2) % FIRMS.length]} ${SUFFIX[r.c] || 'Services'}` : letter.fill.company, years: type === 'senior' ? '15' : yrs || letter.fill.years, field: fieldOf(r.c), skill1: sk(0), skill2: sk(1), skill3: sk(2), degree: (r.d || ind.degree || letter.fill.degree || '').split(',')[0].split(' (')[0], prev: ind.prev || letter.fill.prev, prevYears: r.key || r.c ? '6' : letter.fill.prevYears};
  if (b[0]) f.evidence = lc(b[0]);
  if (b[1]) f.evidence2 = lc(b[1]);
  if (type === 'senior') { const team = trade ? 18 : 40; f.evidence = `lead a team of ${team} and own an annual budget of ${trade ? '₹1.5 crore' : '₹12 crore'}`; f.evidence2 = b[0] ? lc(b[0]) : f.evidence2; f.teamSize = String(team); }
  const fill = s => s.replace(/\{(\w+)\}/g, (m, key) => f[key] ?? m), body = letter.bodyTemplate.map(fill);
  return {...base, role: title, company: f.company, subject: (type === 'freelance' ? 'Proposal — ' : 'Application for ') + title, opening: body[0] || '', evidence: body.slice(1, -1).join('\n\n'), closing: body.at(-1) || '', sample: {type, role: r.key || '', industry: r.c || ''}};
}

// ---------------------------------------------------------------- interface
const layoutName = l => String(l || '').replace(/^(suite|rendercv|latexcv|html|career)-/, '').replace(/-/g, ' ').replace(/^engineering(classic|resumes)$/, 'engineering').replace(/^./, c => c.toUpperCase());
/** Documents render at their real width (595px) and are zoomed to the card, so every layout keeps its proportions. */
function fitPapers(root) { root.querySelectorAll('.tp-paper').forEach(p => { const w = p.parentElement.clientWidth; if (w) p.style.zoom = String(Math.min(1, w / 595)); }); }
let resizeTimer;
if (typeof window.addEventListener === 'function') window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => document.querySelectorAll('.tp,.tp-seo').forEach(fitPapers), 120); });
function mount(host, opts = {}) {
  if (!host || host.dataset.pickerReady) return; host.dataset.pickerReady = '1';
  const kind = opts.kind || host.dataset.templatePicker || 'resume', isLetter = kind === 'letter', studio = (opts.mode || host.dataset.pickerMode) === 'studio';
  const qs = new URLSearchParams(location.search);
  const st = {step: 1, type: '', industry: '', role: '', title: '', style: '', query: '', match: ''};
  const t0 = qs.get('type'), s0 = qs.get('style'), r0 = qs.get('role'), i0 = qs.get('industry');
  if (TYPES.includes(t0)) st.type = t0;
  if (s0 && (STYLES[s0] || STYLE_TYPE[s0])) { st.style = s0; if (STYLE_TYPE[s0]) st.type = STYLE_TYPE[s0]; }
  const pre = !!(r0 || i0 || st.type || st.style);
  host.classList.add('tp'); host.setAttribute('aria-live', 'polite');
  const R = window.CVTaraReferenceTemplates, P = window.CVTaraPremium;
  const choice = () => ({type: st.type || 'experienced', role: st.role, industry: st.industry, title: st.title});
  const label = () => st.role ? LIB.roles[st.role]?.t : st.industry ? LIB.industries[st.industry]?.n : st.title;
  const allLink = isLetter ? (studio ? '#letterTemplateGrid' : '#coverLetterGrid') : (studio ? '#studioTemplateCards' : '#allTemplates');
  const typeName = id => A.find(a => a.id === id)?.name || '';
  function steps() {
    const items = [['1', 'Experience level', st.type ? typeName(st.type) : ''], ['2', 'Industry or job', st.step > 2 ? label() || 'Any job' : ''], ['3', isLetter ? 'Pick a letter' : 'Pick a design', '']];
    return `<ol class="tp-steps">${items.map(([n, l, v], i) => `<li class="${st.step === i + 1 ? 'on' : st.step > i + 1 ? 'done' : ''}">${st.step > i + 1 ? `<button type="button" data-tp-step="${i + 1}">` : '<span>'}<i>${n}</i><b>${l}</b>${v ? `<small>${E(v)}</small>` : ''}${st.step > i + 1 ? '</button>' : '</span>'}</li>`).join('')}</ol>`;
  }
  function viewTypes() {
    return `<h3 class="tp-q">Where are you in your career?</h3><div class="tp-types">${A.map(a => `<button type="button" data-tp-type="${a.id}" class="${st.type === a.id ? 'on' : ''}"><b>${E(a.name)}</b><small>${E(a.blurb)}</small></button>`).join('')}</div>`;
  }
  function viewIndustry() {
    const ind = st.industry && LIB.industries[st.industry];
    return `<h3 class="tp-q">What job are you applying for?</h3><label class="tp-search"><span>Type any job title</span><input type="search" data-tp-search placeholder="e.g. accountant, nurse, React developer, driver" autocomplete="off" value="${E(st.query)}"></label><div class="tp-hits" data-tp-hits role="list"></div>
      <p class="tp-sub">Or choose an industry</p><div class="tp-inds">${Object.entries(LIB.industries).map(([key, c]) => `<button type="button" data-tp-industry="${key}" class="${st.industry === key ? 'on' : ''}">${E(c.n)}</button>`).join('')}</div>
      ${ind ? `<div class="tp-roles"><p class="tp-sub">${E(ind.n)} jobs</p>${ind.roles.map(k => `<button type="button" data-tp-role="${k}">${E(LIB.roles[k].t)}</button>`).join('')}<button type="button" data-tp-role="" class="tp-any">Any ${E(ind.n.toLowerCase())} job</button></div>` : ''}`;
  }
  function hits() {
    const box = host.querySelector('[data-tp-hits]'); if (!box) return;
    const q = st.query.trim(); if (!q) { box.innerHTML = ''; return; }
    const list = search(q, 6), res = resolve(q);
    box.innerHTML = list.map(h => `<button type="button" role="listitem" ${h.kind === 'role' ? `data-tp-role="${h.key}" data-tp-in="${h.industry}"` : `data-tp-industry-go="${h.key}"`}><b>${E(h.title)}</b><small>${h.kind === 'role' ? E(LIB.industries[h.industry]?.n || '') : 'Industry'}</small></button>`).join('') + (res.match === 'custom' || !list.length ? `<button type="button" role="listitem" data-tp-custom><b>Use “${E(cap(q))}”</b><small>We will show designs with general sample content</small></button>` : '');
  }
  function card(item) {
    const doc = isLetter ? letterDoc(choice(), item) : resumeDoc(choice(), item);
    const html = isLetter ? P.renderLetter(doc, {sample: true}) : R.render(item, doc, {sample: true});
    const use = isLetter ? `/workspace.html?view=letter&letter=${encodeURIComponent(item.slug)}` : `/workspace.html?view=builder&template=${encodeURIComponent(item.slug)}`;
    const extra = `&type=${encodeURIComponent(st.type || 'experienced')}${st.role ? '&role=' + encodeURIComponent(st.role) : ''}${st.industry ? '&industry=' + encodeURIComponent(st.industry) : ''}`;
    const tag = isLetter ? `${E(item.tone)} · ${E(item.market)}` : `${item.ats ? 'ATS-conscious' : 'Visual application'} · ${E(layoutName(item.layout))} layout`;
    return `<article class="tp-card"><button type="button" class="tp-art" data-tp-open="${E(item.slug)}" aria-label="Preview ${E(item.name)}"><div class="tp-paper-wrap"><div class="tp-paper">${html}</div></div><span>Full preview</span></button><div class="tp-meta"><b>${E(item.name)}</b><small>${tag}</small>${studio ? `<button type="button" class="button primary" data-tp-use="${E(item.slug)}">Use this ${isLetter ? 'letter' : 'design'}</button>` : `<a class="button primary" href="${use + extra}" data-tp-use-link="${E(item.slug)}">Use this ${isLetter ? 'letter' : 'template'}</a>`}</div></article>`;
  }
  function viewDesigns() {
    const list = isLetter ? lettersFor(choice()) : designsFor({...choice(), style: st.style});
    const note = st.match === 'custom' ? `<p class="tp-note">No exact job match, so the samples use general content with your title. Every design still works for your role.</p>` : st.match === 'industry' || st.match === 'close' ? `<p class="tp-note">Showing the closest match: ${E(label())}.</p>` : '';
    const style = st.style && !STYLE_TYPE[st.style] ? (LIB.styles?.[st.style]?.n || st.style) : '';
    return `<div class="tp-bar"><div><h3 class="tp-q">${isLetter ? 'Five cover letters' : 'Five designs'} for ${E(typeName(st.type || 'experienced').toLowerCase())}${label() ? ' · ' + E(label()) : ''}${style ? ' · ' + E(style) : ''}</h3><p class="tp-sub">Previews use fictional sample content for this job. Your own details replace it in the builder.</p></div><div class="tp-switch" role="group" aria-label="Experience level">${A.map(a => `<button type="button" data-tp-type-quick="${a.id}" aria-pressed="${(st.type || 'experienced') === a.id}">${E(a.name)}</button>`).join('')}</div></div>${note}
      <div class="tp-grid">${list.map(card).join('')}</div>
      <p class="tp-foot"><button type="button" class="button secondary" data-tp-step="2">Change job</button><a class="tp-all" href="${allLink}" data-tp-all>Show all ${isLetter ? 'cover letters' : 'templates'}</a></p>`;
  }
  function draw(focus) {
    if (!LIB) { host.innerHTML = '<p class="tp-loading">Loading the guided picker…</p>'; return; }
    host.innerHTML = `<div class="tp-head"><span class="eyebrow">${isLetter ? 'GUIDED LETTER PICKER' : 'GUIDED TEMPLATE PICKER'}</span><a class="tp-all" href="${allLink}" data-tp-all>Show all ${isLetter ? 'cover letters' : 'templates'}</a></div>${steps()}<div class="tp-body">${st.step === 1 ? viewTypes() : st.step === 2 ? viewIndustry() : viewDesigns()}</div>`;
    fitPapers(host);
    if (st.step === 2) { hits(); if (focus) host.querySelector('[data-tp-search]')?.focus({preventScroll: true}); }
    else if (focus) { const q = host.querySelector('.tp-q'); if (q) { q.setAttribute('tabindex', '-1'); q.focus({preventScroll: true}); } }
  }
  function go(step) { st.step = step; draw(true); }
  function chooseRole(role, industry) { st.role = role; st.industry = industry || LIB.roles[role]?.c || st.industry; st.title = ''; st.match = 'role'; go(st.type ? 3 : 1); }
  function open(slug) {
    const item = (isLetter ? D.coverLetters : D.templates).find(x => x.slug === slug); if (!item) return;
    let d = host.querySelector('dialog.tp-dialog');
    if (!d) { d = document.createElement('dialog'); d.className = 'tp-dialog'; host.append(d); d.addEventListener('click', e => { if (e.target === d || e.target.closest('[data-tp-close]')) d.close(); }); }
    const doc = isLetter ? letterDoc(choice(), item) : resumeDoc(choice(), item);
    const link = host.querySelector(`[data-tp-use-link="${CSS.escape(slug)}"]`)?.getAttribute('href');
    d.innerHTML = `<div class="tp-dialog-shell"><button type="button" class="dialog-close" data-tp-close aria-label="Close preview">×</button><h3>${E(item.name)}</h3><div class="tp-dialog-paper">${isLetter ? P.renderLetter(doc, {sample: true}) : R.render(item, doc, {sample: true})}</div><div class="tp-dialog-actions">${studio ? `<button type="button" class="button primary" data-tp-use="${E(slug)}">Use this ${isLetter ? 'letter' : 'design'}</button>` : `<a class="button primary" href="${E(link || '#')}">Use this ${isLetter ? 'letter' : 'template'}</a>`}<button type="button" class="button secondary" data-tp-close>Close</button></div></div>`;
    d.showModal();
  }
  host.addEventListener('click', e => {
    const b = e.target.closest('button,a'); if (!b || !host.contains(b)) return;
    const ds = b.dataset;
    if (ds.tpStep) { go(Number(ds.tpStep)); return; }
    if (ds.tpType) { st.type = ds.tpType; go(st.role || st.industry || st.title ? 3 : 2); return; }
    if (ds.tpTypeQuick) { st.type = ds.tpTypeQuick; draw(); return; }
    if (ds.tpIndustry) { st.industry = st.industry === ds.tpIndustry ? '' : ds.tpIndustry; st.role = ''; st.title = ''; draw(); host.querySelector('.tp-roles')?.scrollIntoView({block: 'nearest'}); return; }
    if (ds.tpIndustryGo) { st.industry = ds.tpIndustryGo; st.role = ''; st.title = ''; st.match = 'industry'; go(st.type ? 3 : 1); return; }
    if (ds.tpRole !== undefined) { if (ds.tpRole) chooseRole(ds.tpRole, ds.tpIn); else { st.role = ''; st.title = ''; st.match = 'industry'; go(st.type ? 3 : 1); } return; }
    if (ds.tpCustom !== undefined) { const res = resolve(st.query); Object.assign(st, {role: res.role, industry: res.industry, title: res.match === 'custom' ? res.title : '', match: res.match}); go(st.type ? 3 : 1); return; }
    if (ds.tpOpen) { open(ds.tpOpen); return; }
    if (ds.tpUse) { const item = (isLetter ? D.coverLetters : D.templates).find(x => x.slug === ds.tpUse); host.querySelector('dialog.tp-dialog[open]')?.close(); host.dispatchEvent(new CustomEvent('cvtara-picker-choose', {bubbles: true, detail: {kind, slug: ds.tpUse, choice: choice(), doc: isLetter ? letterDoc(choice(), item) : resumeDoc(choice(), item)}})); return; }
    if (ds.tpAll !== undefined) { const target = document.querySelector(allLink); if (target) { e.preventDefault(); target.scrollIntoView({behavior: 'smooth', block: 'start'}); } }
  });
  host.addEventListener('input', e => { if (e.target.matches('[data-tp-search]')) { st.query = e.target.value; hits(); } });
  host.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('[data-tp-search]') && st.query.trim()) { e.preventDefault(); const res = resolve(st.query); Object.assign(st, {role: res.role, industry: res.industry, title: res.match === 'custom' ? res.title : '', match: res.match}); go(st.type ? 3 : 1); } });
  draw();
  ready().then(() => {
    if (r0) { const res = LIB.roles[r0] ? {role: r0, industry: LIB.roles[r0].c, match: 'role'} : resolve(r0.replace(/-/g, ' ')); Object.assign(st, {role: res.role || '', industry: res.industry || '', title: res.match === 'custom' ? res.title : '', match: res.match}); }
    else if (i0) { if (LIB.industries[i0]) { st.industry = i0; st.match = 'industry'; } else { const res = resolve(i0.replace(/-/g, ' ')); Object.assign(st, {role: res.role, industry: res.industry, title: res.match === 'custom' ? res.title : '', match: res.match}); } }
    if (pre && !st.type) st.type = LIB.roles[st.role]?.c === 'student' || st.industry === 'student' ? 'fresher' : 'experienced';
    st.step = pre ? 3 : 1;
    draw();
    if (pre && location.hash === '#picker') host.scrollIntoView({block: 'start'});
  }).catch(() => { host.innerHTML = `<p class="tp-loading">The guided picker could not load. <a href="${allLink}">Browse all ${isLetter ? 'cover letters' : 'templates'}</a>.</p>`; });
}

/** SEO pages: five live previews for a template style, each opening the picker with that style chosen. */
function mountStyle(host) {
  if (!host || host.dataset.pickerReady) return; host.dataset.pickerReady = '1';
  const style = host.dataset.pickerStyle, R = window.CVTaraReferenceTemplates;
  ready().then(() => {
    const type = STYLE_TYPE[style] || 'experienced', list = designsFor({type, style}), role = host.dataset.pickerRole || '';
    host.classList.add('tp-seo');
    host.innerHTML = `<div class="tp-grid tp-grid-seo">${list.map(t => `<article class="tp-card"><a class="tp-art" href="/templates.html?style=${encodeURIComponent(style)}${role ? '&role=' + encodeURIComponent(role) : ''}#picker" aria-label="Open ${E(t.name)} in the guided picker"><div class="tp-paper-wrap"><div class="tp-paper">${R.render(t, resumeDoc({type, role}, t), {sample: true})}</div></div><span>Use this template</span></a><div class="tp-meta"><b>${E(t.name)}</b><small>${t.ats ? 'ATS-conscious' : 'Visual application'}</small></div></article>`).join('')}</div>`;
    fitPapers(host);
  }).catch(() => {});
}

window.CVTaraPicker = {load, ready, search, resolve, designsFor, lettersFor, resumeDoc, letterDoc, roleData, titleFor, mount, mountStyle, styles: Object.keys(STYLES), types: TYPES};
const auto = () => { document.querySelectorAll('[data-template-picker]').forEach(h => mount(h)); document.querySelectorAll('[data-picker-style]').forEach(mountStyle); document.dispatchEvent(new CustomEvent('cvtara-picker-ready')); };
if (typeof document !== 'undefined') { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto(); }
// Builders that draw their screens later (the workspace letter studio) get their picker as soon as the placeholder appears.
if (typeof MutationObserver !== 'undefined' && document.body) { let t; new MutationObserver(() => { clearTimeout(t); t = setTimeout(() => document.querySelectorAll('[data-template-picker]:not([data-picker-ready]),[data-picker-style]:not([data-picker-ready])').forEach(h => h.dataset.templatePicker !== undefined ? mount(h) : mountStyle(h)), 60); }).observe(document.body, {childList: true, subtree: true}); }
})();
