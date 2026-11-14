/* 3.74.0: still pictures of every resume and cover letter design, for image search (see App\Services\TemplateImages).
 *
 * Usage: start a preview instance, then
 *   node tools/seo/template_images.cjs http://127.0.0.1:PORT
 * It opens the template and cover letter galleries in light mode, screenshots each design's preview and writes
 * public_html/assets/templates/{slug}-resume-template.webp and public_html/assets/cover-letters/{slug}-cover-letter.webp
 * (480 px wide WebP, made with Python Pillow). Run it again after adding or changing a design. */
const {chromium} = require('playwright');
const fs = require('fs'), os = require('os'), path = require('path'), {execFileSync} = require('child_process');

const base = (process.argv[2] || '').replace(/\/$/, '');
if (!base) { console.error('Usage: node tools/seo/template_images.cjs http://127.0.0.1:PORT'); process.exit(1); }
const pub = path.resolve(__dirname, '../../public_html/assets');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'cvt-img-'));
const hide = '[class^=cvp],[class*=" cvp"],.cvp-overlay,.promo-bar,[class*=promo],[class*=fomo],.mobile-cta-bar,.to-top{display:none!important}';

(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({viewport: {width: 1280, height: 1000}, deviceScaleFactor: 1.5});
  await p.addInitScript(() => { try { localStorage.setItem('cvtara-theme', 'light'); } catch (e) {} });
  const open = async url => { await p.goto(base + url, {waitUntil: 'load'}); await p.waitForTimeout(4000); await p.addStyleTag({content: hide}); };
  const shot = async (el, file) => { await el.scrollIntoViewIfNeeded(); await p.waitForTimeout(120); await el.screenshot({path: file}); };
  const jobs = [];

  await open('/templates.html');
  for (let page = 0; page < 30; page++) {
    for (const c of await p.$$('.template-card')) {
      const slug = await (await c.$('[data-template]')).getAttribute('data-template');
      const png = path.join(tmp, 'r-' + slug + '.png');
      await shot(await c.$('.template-preview'), png);
      jobs.push([png, path.join(pub, 'templates', slug + '-resume-template.webp')]);
    }
    const next = await p.$('[data-gallery-next]:not([disabled])');
    if (!next) break;
    await next.evaluate(e => e.click()); await p.waitForTimeout(800);
  }
  await open('/cover-letters.html');
  for (const c of await p.$$('#coverLetterGrid .cover-letter-card')) {
    const slug = await c.getAttribute('data-cover-letter');
    const png = path.join(tmp, 'c-' + slug + '.png');
    await shot(await c.$('.cover-letter-preview'), png);
    jobs.push([png, path.join(pub, 'cover-letters', slug + '-cover-letter.webp')]);
  }
  await b.close();

  fs.mkdirSync(path.join(pub, 'templates'), {recursive: true});
  fs.mkdirSync(path.join(pub, 'cover-letters'), {recursive: true});
  const list = path.join(tmp, 'jobs.json');
  fs.writeFileSync(list, JSON.stringify(jobs));
  execFileSync('python3', ['-I', '-c', [
    'import json,sys', 'from PIL import Image',
    'for src,dst in json.load(open(sys.argv[1])):',
    '    im=Image.open(src).convert("RGB"); h=round(im.height*480/im.width)',
    '    im.resize((480,h),Image.LANCZOS).save(dst,"WEBP",quality=80,method=6)',
  ].join('\n'), list], {stdio: 'inherit'});
  fs.rmSync(tmp, {recursive: true, force: true});
  console.log(`${jobs.length} pictures written`);
})();
