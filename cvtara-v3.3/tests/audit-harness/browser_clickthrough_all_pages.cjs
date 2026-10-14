const { chromium } = require(process.argv[2]); const B='http://127.0.0.1:8000';
const SKIP=/delete|sign out|log ?out|block|permanently|continue to secure|clear compiled|remove|reject|deny|withdraw|submit feedback|send test|process due/i;
const results=[]; 
async function loginTo(ctx,email,pw){const p=await ctx.newPage();await p.goto(B+'/login');await p.fill('input[name=email]',email);await p.fill('input[name=password]',pw);await Promise.all([p.waitForNavigation(),p.click('button.au-go')]);await p.close();}
async function sweep(state,label,url,opts={}){
  const br=await chromium.launch({args:['--no-sandbox']}); const ctx=await br.newContext({viewport:{width:1366,height:850},storageState:state||undefined}); const p=await ctx.newPage(); const errs=[],bad=[]; let reqs=0;
  p.on('pageerror',e=>errs.push('JS: '+e.message.slice(0,110))); p.on('request',()=>reqs++);
  p.on('response',r=>{const s=r.status();if(s>=400&&!r.url().includes('favicon')&&![401,402,422,409,403].includes(s))bad.push(s+' '+r.url().replace(B,''));});
  p.on('dialog',d=>d.accept('audit').catch(()=>{}));
  await p.goto(B+url,{waitUntil:'networkidle'}).catch(()=>{}); await p.waitForTimeout(900);
  if(opts.pre) await opts.pre(p);
  // 1) links
  const links=await p.$$eval('a[href]',as=>[...new Set(as.filter(a=>a.offsetParent!==null).map(a=>a.getAttribute('href')))].filter(h=>h&&!h.startsWith('#')&&!h.startsWith('mailto:')&&!h.startsWith('tel:')&&!h.startsWith('javascript:')&&!/^https?:/.test(h)));
  const deadLinks=[];
  for(const h of links){ if(/logout/.test(h)) continue; const st=await p.evaluate(async h=>{try{const r=await fetch(h,{redirect:'manual'});return r.status||200}catch{return 0}},h); if(st>=400) deadLinks.push(h+' ['+st+']'); }
  // 2) buttons
  const count=await p.locator('button:visible, [role=button]:visible, input[type=submit]:visible').count(); const nofx=[]; let clicked=0;
  for(let i=0;i<Math.min(count,80);i++){
    const loc=p.locator('button:visible, [role=button]:visible, input[type=submit]:visible').nth(i);
    let txt=''; try{txt=((await loc.innerText({timeout:800}))||await loc.getAttribute('aria-label')||'').trim().replace(/\s+/g,' ').slice(0,40);}catch{continue}
    if(SKIP.test(txt)||/^(×|☰)$/.test(txt)&&false) continue;
    const before=await p.evaluate(()=>[location.href,document.documentElement.outerHTML.length,document.querySelectorAll('dialog[open]').length,document.documentElement.className+document.body.className].join('|'));
    const r0=reqs; const url0=p.url();
    try{await loc.click({timeout:1500,noWaitAfter:true});}catch{continue}
    clicked++; await p.waitForTimeout(450);
    const after=await p.evaluate(()=>[location.href,document.documentElement.outerHTML.length,document.querySelectorAll('dialog[open]').length,document.documentElement.className+document.body.className].join('|')).catch(()=>'navigated');
    if(after===before&&reqs===r0) nofx.push(txt||'(icon)');
    if(p.url()!==url0&&!opts.stay){ await p.goto(B+url,{waitUntil:'networkidle'}).catch(()=>{}); if(opts.pre) await opts.pre(p); }
    // close any open dialog
    await p.keyboard.press('Escape').catch(()=>{});
  }
  const r={label,url,links:links.length,deadLinks,buttons:count,clicked,nofx:[...new Set(nofx)],errs:[...new Set(errs)],bad:[...new Set(bad)]}; results.push(r); report(r); await br.close();
}
function report(r){const flag=r.deadLinks.length||r.errs.length||r.bad.length||r.nofx.length; console.log(`${flag?'⚠':'✓'} ${r.label.padEnd(26)} ${r.url.padEnd(48)} links:${r.links} buttons:${r.buttons} clicked:${r.clicked}${r.deadLinks.length?'\n    DEAD LINKS: '+r.deadLinks.join(', '):''}${r.errs.length?'\n    '+r.errs.join('\n    '):''}${r.bad.length?'\n    HTTP: '+r.bad.join(', '):''}${r.nofx.length?'\n    no visible effect: '+r.nofx.join(' | '):''}`);}
(async()=>{
 const mkState=async(email,pw,file)=>{const b=await chromium.launch({args:['--no-sandbox']});const c=await b.newContext();const p=await c.newPage();await p.goto(B+'/login');await p.fill('input[name=email]',email);await p.fill('input[name=password]',pw);await Promise.all([p.waitForNavigation(),p.click('button.au-go')]);await c.storageState({path:file});await b.close();return file;};
 const cust=await mkState('demo@cvtara.test','DemoPass#2026!x','/tmp/st_cust.json'), own=await mkState('owner@cvtara.test','DemoPass#2026!x','/tmp/st_own.json');
 const run=async(...a)=>{try{await sweep(...a)}catch(e){console.log('✗ sweep failed',a[1],a[2],e.message.slice(0,100))}};
 const which=process.argv[3]||'all';
 if(which==='all'||which==='public') for(const u of ['/','/templates.html','/cover-letters.html','/blog.html','/guides.html','/about.html','/contact.html','/privacy.html','/terms.html','/refund.html','/checkout.html','/login','/register','/forgot-password','/customer-guide.html','/calculators/take-home-salary-calculator/','/guides/resume/action-verbs-list/']) await run(null,'public',u);
 if(which==='all'||which==='customer'){ for(const v of ['dashboard','resumes','builder','match','letter','tracker','billing','referrals','wallet','referral-history','account']) await run(cust,'customer:'+v,'/workspace.html?view='+v); await run(cust,'checkout(logged in)','/checkout.html?plan=fresher-standard'); }
 if(which==='all'||which==='admin') for(const v of ['overview','design','features','templates','letters','users','business','pricing','commerce','popup','blog','seo','keywords','emails','reviews','analytics','integrations','push','legal','setup']) await run(own,'admin:'+v,'/admin.html?tab='+v);
 console.log('finished');
})();
