const { chromium } = require(process.argv[2]);
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']});
 const login=async(email,pw)=>{const c=await b.newContext({viewport:{width:1400,height:900}});const lp=await c.newPage();await lp.goto('http://127.0.0.1:8000/login');await lp.fill('input[name=email]',email);await lp.fill('input[name=password]',pw);await Promise.all([lp.waitForNavigation(),lp.click('button')]);await lp.close();return c;};
 const check=async(c,label,url,click)=>{const p=await c.newPage();await p.goto('http://127.0.0.1:8000'+url,{waitUntil:'networkidle'});if(click){await p.evaluate(click);await p.waitForTimeout(900);}await p.waitForTimeout(500);console.log(label.padEnd(34),'xss fired:',await p.evaluate(()=>window.__xss||0),' injected <img src=x>:',await p.evaluate(()=>document.querySelectorAll('img[src="x"]').length));await p.close();};
 const a=await login('owner@test.local','OwnerPassw0rd!!');
 // publish the review from admin API via page context
 const ap=await a.newPage(); await ap.goto('http://127.0.0.1:8000/admin.html',{waitUntil:'networkidle'});
 const revs=await ap.evaluate(async()=>{const t=document.querySelector('meta[name=csrf-token]').content;const r=await (await fetch('/api/admin/reviews',{headers:{Accept:'application/json'}})).json();const x=r.reviews.find(v=>v.display_name.includes('onerror'));if(x){await fetch('/api/admin/reviews',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':t,Accept:'application/json'},body:JSON.stringify({id:x.id,status:'published'})});}return !!x;}); console.log('review published',revs); await ap.close();
 for(const v of ['overview','users','reviews','commerce','emails','analytics']) await check(a,'admin view '+v,'/admin.html',`document.querySelector('button[data-admin-view="${v}"]').click()`);
 const anon=await b.newContext(); await check(anon,'public home','/'); await check(anon,'public blog','/blog.html'); await check(anon,'checkout','/checkout.html'); await check(anon,'about','/about.html');
 const u=await login('xss@test.local','TestPassw0rd!!x');
 for(const v of ['account','tracker','builder','referrals','dashboard']) await check(u,'workspace '+v,'/workspace.html?view='+v);
 await b.close();})();
