const { chromium } = require(process.argv[2]); const S=process.argv[3]; const B='http://127.0.0.1:8000';
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']});
 const watch=(p,label)=>{p.on('pageerror',e=>console.log(label,'JS ERROR:',e.message.slice(0,200)));p.on('response',r=>{if(r.status()>=400&&!r.url().includes('favicon'))console.log(label,'HTTP',r.status(),r.url().replace(B,''))});};
 // ---- signup via UI
 let c=await b.newContext({viewport:{width:1440,height:900}}); let p=await c.newPage(); watch(p,'signup');
 await p.goto(B+'/'); await p.click('text=Start free'); await p.waitForURL('**/register');
 await p.fill('input[name=name]','UI Tester'); await p.fill('input[name=email]','ui.tester@test.local'); await p.fill('input[name=password]','UiTester#2026pass'); await p.fill('input[name=password_confirmation]','UiTester#2026pass'); await p.selectOption('select[name=experienceLevel]','fresher'); await p.fill('input[name=profession]','Analyst'); await p.check('input[name=terms]');
 await Promise.all([p.waitForURL('**/workspace.html**'),p.click('button.au-go')]); await p.waitForTimeout(2500);
 console.log('after signup url:',p.url().replace(B,''));
 await p.screenshot({path:S+'/n_ws_resumes.png'});
 console.log('resumes view h2:',await p.locator('#view-resumes h2').first().innerText().catch(()=>'MISSING'));
 await p.click('[data-workspace-view=dashboard]'); await p.waitForTimeout(1200); await p.screenshot({path:S+'/n_ws_dash.png'});
 console.log('dash hero:',await p.locator('#wsDash .ws-hero h2').innerText().catch(()=>'MISSING'));
 await p.click('.side-nav [data-workspace-view=billing]'); await p.waitForTimeout(1500); await p.screenshot({path:S+'/n_ws_billing.png'});
 console.log('billing cards:',await p.locator('#billingPlans .pl-card').count());
 // checkout from billing
 await p.locator('#billingPlans [data-pl-go]').first().click(); await p.waitForURL('**/checkout.html**'); await p.waitForTimeout(1500);
 console.log('checkout url',p.url().replace(B,''),'cards',await p.locator('#coPlans .pl-card').count(),'summary',await p.locator('#checkoutPlanName').innerText(), await p.locator('#checkoutPrice').innerText());
 await p.locator('#coPlans [data-pl-select]').nth(1).click(); await p.waitForTimeout(500); console.log('after select:',await p.locator('#checkoutPlanName').innerText());
 await p.screenshot({path:S+'/n_checkout.png'});
 // logout + login
 await p.goto(B+'/workspace.html'); await p.click('text=Sign out'); await p.waitForURL(B+'/'); 
 await p.goto(B+'/login'); await p.fill('input[name=email]','ui.tester@test.local'); await p.fill('input[name=password]','UiTester#2026pass'); await Promise.all([p.waitForURL('**/workspace.html'),p.click('button.au-go')]); console.log('login ok');
 await b.close();
})();
