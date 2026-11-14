// Merges results/*.json into report.json and report.md: summary, then every page and every function with its result.
const fs = require('fs'), path = require('path');
function build(OUT) {
  const files = fs.readdirSync(OUT).filter(f => /^p\d\d-.*\.json$/.test(f)); const personas = files.map(f => JSON.parse(fs.readFileSync(path.join(OUT, f), 'utf8'))).sort((a, b) => a.id.localeCompare(b.id));
  const rows = personas.flatMap(p => p.results.map(r => ({ ...r, persona: p.id })));
  const count = (rs, s) => rs.filter(r => r.status === s).length; const ico = { pass: '✅', warn: '⚠️', fail: '❌', skip: '⏭️' };
  const line = r => `| ${ico[r.status] || r.status} | ${r.name.replace(/\|/g, '/')} | ${r.persona} | ${[...r.notes, ...r.errors.slice(0, 4).map(e => `${e.kind}: ${e.text}`)].join('<br>').replace(/\|/g, '/').replace(/\n/g, ' ').slice(0, 700)} |`;
  const sect = (title, rs) => [`## ${title} (${rs.length}: ${count(rs, 'pass')} pass, ${count(rs, 'warn')} warn, ${count(rs, 'fail')} fail, ${count(rs, 'skip')} skipped)`, '', '| | Item | Tester | Notes |', '|---|---|---|---|', ...rs.sort((a, b) => ['fail', 'warn', 'skip', 'pass'].indexOf(a.status) - ['fail', 'warn', 'skip', 'pass'].indexOf(b.status)).map(line), ''];
  const md = [`# CVTara live test report`, '', `Run ${personas[0]?.started || ''} to ${personas.map(p => p.ended).sort().pop() || ''}. ${rows.length} checks: ${count(rows, 'pass')} pass, ${count(rows, 'warn')} warn, ${count(rows, 'fail')} fail.`, '',
    '## Testers', '', ...personas.map(p => `- **${p.id}**: ${p.title} (${count(p.results, 'fail')} fail, ${count(p.results, 'warn')} warn of ${p.results.length})`), '',
    ...sect('Pages', rows.filter(r => r.area === 'page')), ...sect('Functions', rows.filter(r => r.area === 'function'))].join('\n');
  fs.writeFileSync(path.join(OUT, 'report.md'), md); fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify({ personas: personas.map(p => ({ id: p.id, title: p.title, started: p.started, ended: p.ended })), rows }, null, 1));
  console.log(`report: ${rows.length} checks, ${count(rows, 'fail')} fail, ${count(rows, 'warn')} warn -> ${path.join(OUT, 'report.md')}`);
}
module.exports = { build }; if (require.main === module) build(process.argv[2] || path.join(__dirname, 'results'));
