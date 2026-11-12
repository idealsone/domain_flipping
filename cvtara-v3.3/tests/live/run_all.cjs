// Runs the 10 QA personas in parallel against BASE (default https://cvtara.com), staggered so the host is not hit
// all at once, then writes results/report.md + report.json. Usage: node tests/live/run_all.cjs [p03 p05 ...]
const { spawn } = require('child_process'); const path = require('path'); const fs = require('fs');
const OUT = process.env.QA_OUT || path.join(__dirname, 'results');
const ALL = [['p01', 'p01_public_desktop.cjs', { HALF: '0' }], ['p02', 'p01_public_desktop.cjs', { HALF: '1' }], ['p03', 'p03_signup_auth.cjs'], ['p04', 'p04_resume_builder.cjs'], ['p05', 'p05_ats_letters.cjs'],
  ['p06', 'p06_interview_tracker.cjs'], ['p07', 'p07_tara_support.cjs'], ['p08', 'p08_checkout_referrals.cjs'], ['p09', 'p09_b2b_partner.cjs'], ['p10', 'p10_admin_console.cjs']];
const pick = process.argv.slice(2); const list = pick.length ? ALL.filter(x => pick.includes(x[0])) : ALL;
const STAGGER = +(process.env.QA_STAGGER_MS || 15000);
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const t0 = Date.now(); const jobs = [];
  for (const [i, [id, file, env]] of list.entries()) {
    if (i) await new Promise(r => setTimeout(r, STAGGER));
    jobs.push(new Promise(res => { const log = fs.createWriteStream(path.join(OUT, `${id}.log`)); const c = spawn(process.execPath, [path.join(__dirname, 'personas', file)], { env: { ...process.env, ...(env || {}) } });
      c.stdout.pipe(log); c.stderr.pipe(log); c.stdout.on('data', d => process.stdout.write(d)); const kill = setTimeout(() => c.kill('SIGTERM'), +(process.env.QA_PERSONA_TIMEOUT_MS || 45 * 60000)); c.on('exit', code => { clearTimeout(kill); res([id, code]); }); }));
  }
  const codes = await Promise.all(jobs); console.log('exit codes', JSON.stringify(codes), `in ${Math.round((Date.now() - t0) / 60000)} min`);
  require('./report.cjs').build(OUT);
})();
