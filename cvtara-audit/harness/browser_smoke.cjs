const { chromium } = require(process.argv[2]);
(async()=>{
 const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome',args:['--no-sandbox']}).catch(async()=>chromium.launch({args:['--no-sandbox']}));
 const login=async(ctx,email,pw)=>{const p=await ctx.newPage();await p.goto('http://127.0.0.1:8000/login');await p.fill('input[name=email]',email);await p.fill('input[name=password]',pw);await Promise.all([p.waitForNavigation(),p.click('button')]);await p.close();};
 const run=async(ctx,label,urls)=>{
  for(const u of urls){
   const p=await ctx.newPage(); const errs=[],bad=[];
   p.on('pageerror',e=>errs.push('JS: '+e.message.slice(0,160)));
   p.on('console',m=>{if(m.type()==='error')errs.push('console: '+m.text().slice(0,160));});
   p.on('response',r=>{if(r.status()>=400)bad.push(r.status()+' '+r.url().replace('http://127.0.0.1:8000',''));});
   p.on('requestfailed',r=>bad.push('FAILED '+r.url().slice(0,100)));
   try{await p.goto('http://127.0.0.1:8000'+u,{waitUntil:'networkidle',timeout:25000});}catch(e){errs.push('nav: '+e.message.slice(0,100));}
   await p.waitForTimeout(600);
   const info=await p.evaluate(()=>({title:document.title,h1:document.querySelector('h1')?.innerText?.slice(0,60),text:document.body.innerText.length,hOverflow:document.documentElement.scrollWidth>window.innerWidth+2}));
   console.log(label,u,JSON.stringify(info),errs.length?'\n   ERR '+[...new Set(errs)].join('\n   ERR '):'',bad.length?'\n   BAD '+[...new Set(bad)].join('\n   BAD '):'');
   await p.close();
  }};
 const anon=await b.newContext({viewport:{width:1280,height:900}});
 await run(anon,'anon',['/','/templates.html','/cover-letters.html','/blog.html','/about.html','/contact.html','/privacy.html','/terms.html','/refund.html','/checkout.html','/credits.html','/guides.html','/customer-guide.html','/admin-guide.html','/calculators/take-home-salary-calculator/','/guides/resume/action-verbs-list/','/login','/register','/sitemap.xml']);
 const mob=await b.newContext({viewport:{width:375,height:760}});
 await run(mob,'mobile',['/','/templates.html','/blog.html','/checkout.html']);
 const user=await b.newContext({viewport:{width:1280,height:900}}); await login(user,'bob@test.local','TestPassw0rd!!x');
 await run(user,'user',['/workspace.html','/workspace.html?view=builder','/workspace.html?view=letter','/workspace.html?view=match','/workspace.html?view=tracker','/workspace.html?view=account','/workspace.html?view=referrals','/admin.html']);
 const adm=await b.newContext({viewport:{width:1280,height:900}}); await login(adm,'owner@test.local','OwnerPassw0rd!!');
 await run(adm,'admin',['/admin.html','/admin-guide.html','/admin/preview/index.html']);
 await b.close();
})();
