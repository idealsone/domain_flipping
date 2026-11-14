// Renders the SEO page pictures for page_images.py: node page_images.cjs jobs.json 1200 675
// Each job is {html, png}: a fixed-size HTML file drawn to a PNG. Fonts load once and stay cached between pages.
const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const [jobsFile, w, h] = process.argv.slice(2);
  const jobs = JSON.parse(fs.readFileSync(jobsFile, 'utf8'));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: +w, height: +h }, deviceScaleFactor: 1 });
  let n = 0;
  for (const j of jobs) {
    await page.goto('file://' + j.html, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: j.png, type: 'png' });
    if (++n % 100 === 0) console.log('drawn', n);
  }
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
