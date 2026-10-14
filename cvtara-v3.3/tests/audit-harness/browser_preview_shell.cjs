const { chromium } = require(process.argv[2]); const S=process.argv[3]; const B='http://127.0.0.1:8000';
(async()=>{
 const b=await chromium.launch({args:['--no-sandbox']});
 const c=await b.newContext({viewport:{width:1500,height:950}}); const p=await c.newPage();
 p.on('pageerror',e=>console.log('JS ERROR:',e.message.slice(0,200)));
 await p.goto(B+'/preview'); await p.waitForTimeout(2500);
 const fr=()=>p.frames().find(f=>f.name()==='pvframe');
 console.log('tab1 frame url:',fr().url().replace(B,''));
 await p.screenshot({path:S+'/n_preview_home.png'});
 await p.click('form[data-go=customer] button'); await p.waitForTimeout(3500);
 console.log('after customer login:',fr().url().replace(B,''),await fr().locator('h1#workspaceTitle').innerText().catch(()=>'?'));
 await p.screenshot({path:S+'/n_preview_customer.png'});
 await p.click('.pv-tab[data-pv=admin]'); await p.waitForTimeout(3000); console.log('admin tab (as customer):',fr().url().replace(B,''), (await fr().locator('body').innerText()).slice(0,40).replace(/\n/g,' '));
 await p.click('form[action="/logout"] button'); await p.waitForTimeout(1500);
 await p.click('form[data-go=admin] button'); await p.waitForTimeout(4000);
 console.log('after owner login:',fr().url().replace(B,''),await fr().locator('#adminTitle').innerText().catch(()=>'?'));
 await p.screenshot({path:S+'/n_preview_admin.png'});
 await b.close();
})();
